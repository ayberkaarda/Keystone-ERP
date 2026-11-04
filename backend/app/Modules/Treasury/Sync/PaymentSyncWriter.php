<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Sync;

use App\Core\Documents\VersionConflictException;
use App\Core\Http\ApiException;
use App\Core\Http\ErrorCode;
use App\Core\Sync\Contracts\SyncWriter;
use App\Core\Sync\Mutation;
use App\Core\Sync\SyncConflict;
use App\Core\Sync\TargetConstraints;
use App\Core\Sync\WriteOutcome;
use App\Modules\Treasury\Enums\DocumentStatus;
use App\Modules\Treasury\Http\Controllers\TreasuryCoreController;
use App\Modules\Treasury\Http\Resources\PaymentResource;
use App\Modules\Treasury\Models\TreasuryPayment;
use BackedEnum;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Write side of `treasury-payments`: create, update and delete of draft payments, nothing else.
 *
 * Each mutation runs the REST endpoint itself: a synthetic request matched against the module's
 * own route (so the `area` default is the REST one) handed to TreasuryCoreController's store /
 * update / destroy. That is the same permission check (`treasury-access` with
 * treasury.payments.create|update|delete in the active company), the same rules (unknown keys
 * refused, amounts, rates, client key), the same invoice permission check of the allocations
 * and the same PaymentService::save / delete, so offline and online writes cannot diverge.
 *
 * What a sync push can never do: assign the number (only posting does, PaymentService::post),
 * move money or post to the ledger (save and delete write the draft and its allocations only),
 * or touch a payment that left draft (draft_only, and PaymentService answers DOCUMENT_LOCKED).
 * Submit, post, reverse and cancel stay online (manifest online_only `treasuryPayment`).
 */
final class PaymentSyncWriter implements SyncWriter
{
    private const string PATH = '/api/v1/treasury-payments';

    public function resource(): string
    {
        return PaymentSyncResource::NAME;
    }

    public function find(string $id): ?Model
    {
        return Str::isUuid($id) ? TreasuryPayment::query()->find($id) : null;
    }

    public function currentVersion(Model $target): int
    {
        return $target instanceof TreasuryPayment ? $target->currentVersion() : 0;
    }

    public function isDraft(Model $target): bool
    {
        if (! $target instanceof TreasuryPayment) {
            return false;
        }

        $status = $target->getAttribute('status');

        return ($status instanceof BackedEnum ? $status->value : $status) === DocumentStatus::Draft->value;
    }

    public function targetSatisfies(Model $target, array $constraints): bool
    {
        return TargetConstraints::satisfiedBy($target, $constraints);
    }

    public function create(Authenticatable $user, Mutation $mutation): WriteOutcome
    {
        return $this->run($user, $mutation, null, function (TreasuryCoreController $rest) use ($user, $mutation): WriteOutcome {
            $rest->store($this->request($user, 'POST', self::PATH, [...$this->payload($mutation), 'id' => $mutation->id]));

            return $this->applied($mutation->id);
        });
    }

    public function update(Authenticatable $user, Mutation $mutation, Model $target, int $version): WriteOutcome
    {
        $payment = $this->payment($target);

        return $this->run($user, $mutation, $payment, function (TreasuryCoreController $rest) use ($user, $mutation, $payment, $version): WriteOutcome {
            // The client never sends `version`; the version the endpoint must see comes from the resolver.
            $rest->update($this->request($user, 'PATCH', self::PATH.'/'.$payment->id, [...$this->payload($mutation), 'version' => $version]), $payment->id);

            return $this->applied($payment->id);
        });
    }

    public function delete(Authenticatable $user, Mutation $mutation, Model $target, int $version): WriteOutcome
    {
        $payment = $this->payment($target);

        return $this->run($user, $mutation, $payment, function (TreasuryCoreController $rest) use ($user, $payment, $version): WriteOutcome {
            $rest->destroy($this->request($user, 'DELETE', self::PATH.'/'.$payment->id, ['version' => $version]), $payment->id);

            return WriteOutcome::deleted();
        });
    }

    /** Submit, post, reverse and cancel move money or start approvals: online only. */
    public function action(Authenticatable $user, Mutation $mutation, Model $target, int $version): WriteOutcome
    {
        return WriteOutcome::rejected(ErrorCode::SyncOfflineWriteNotAllowed);
    }

    /**
     * What only the server sets: the number and status, and what posting, approval and reversal
     * write. The REST rules refuse these keys too; offline they are refused before the endpoint.
     */
    public function lockedFields(): array
    {
        return [
            'status', 'number', 'journal_entry_id', 'reversal_journal_entry_id', 'reversal_date', 'reversal_reason',
            'approval_request_id', 'amount_local', 'allocated_local', 'advance_local', 'realized_fx', 'created_by',
        ];
    }

    /**
     * Columns of the payment's activity log (TreasuryRecord logs every attribute). Allocations are
     * not logged on the payment, so a stale update of them is always a conflict.
     */
    public function auditFields(): array
    {
        return [
            'number', 'document_date', 'status', 'description', 'version', 'direction', 'account_id', 'party_id',
            'currency_code', 'amount', 'fx_rate', 'reference', 'counterparty_module', 'journal_entry_id',
            'reversal_journal_entry_id', 'reversal_date', 'reversal_reason', 'approval_request_id',
            'amount_local', 'allocated_local', 'advance_local', 'realized_fx', 'created_by',
        ];
    }

    /**
     * Runs one write as $user and turns what REST would answer with an error into an outcome.
     *
     * @param  Closure(TreasuryCoreController): WriteOutcome  $write
     */
    private function run(Authenticatable $user, Mutation $mutation, ?TreasuryPayment $target, Closure $write): WriteOutcome
    {
        $previous = Auth::guard()->user();
        $switched = $previous?->getAuthIdentifier() !== $user->getAuthIdentifier();

        if ($switched) {
            Auth::setUser($user);
        }

        try {
            return $write(app(TreasuryCoreController::class));
        } catch (ValidationException $e) {
            return WriteOutcome::rejected(ErrorCode::ValidationError, fields: $this->fields($e->errors()));
        } catch (AuthorizationException) {
            return WriteOutcome::rejected(ErrorCode::Forbidden);
        } catch (ModelNotFoundException) {
            return WriteOutcome::rejected(ErrorCode::NotFound);
        } catch (VersionConflictException) {
            return WriteOutcome::rejected(ErrorCode::VersionConflict);
        } catch (ApiException $e) {
            if ($e->errorCode === ErrorCode::DocumentLocked && $target !== null) {
                $current = TreasuryPayment::query()->find($target->id) ?? $target;

                return WriteOutcome::conflict(SyncConflict::documentLocked($mutation->baseVersion, $current->currentVersion(), $this->data($current)));
            }

            return WriteOutcome::rejected($e->errorCode, $e->getMessage(), $e->fields);
        } finally {
            if ($switched && $previous !== null) {
                Auth::setUser($previous);
            }
        }
    }

    /**
     * The REST request: matched against the module's routes, so `area` and the route
     * parameters are those of the real endpoint.
     *
     * @param  array<string, mixed>  $payload
     */
    private function request(Authenticatable $user, string $method, string $uri, array $payload): Request
    {
        $request = Request::create($uri, $method, $payload, server: ['HTTP_ACCEPT' => 'application/json']);
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);
        $request->setUserResolver(static fn (): Authenticatable => $user);

        return $request;
    }

    /**
     * Payload as the endpoint takes it: the client never sends version nor, on an edit, the key.
     *
     * @return array<string, mixed>
     */
    private function payload(Mutation $mutation): array
    {
        return array_diff_key($mutation->payload, ['id' => true, 'version' => true]);
    }

    /** The stored row, read back as the pull serves it. */
    private function applied(string $id): WriteOutcome
    {
        $payment = TreasuryPayment::query()->findOrFail($id);

        return WriteOutcome::applied($payment->currentVersion(), $this->data($payment));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(TreasuryPayment $payment): array
    {
        /** @var array<string, mixed> */
        return json_decode(json_encode((new PaymentResource($payment))->resolve(new Request), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
    }

    private function payment(Model $target): TreasuryPayment
    {
        if (! $target instanceof TreasuryPayment) {
            throw new InvalidArgumentException('Target is not a treasury payment.');
        }

        return $target;
    }

    /**
     * @param  array<string, array<array-key, string>>  $errors
     * @return array<string, list<string>>
     */
    private function fields(array $errors): array
    {
        return array_map(static fn (array $messages): array => array_values($messages), $errors);
    }
}
