<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Sync;

use App\Core\Sync\Contracts\SyncResource;
use App\Core\Sync\SyncScope;
use App\Modules\Treasury\Http\Resources\PaymentResource;
use App\Modules\Treasury\Models\TreasuryPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Read side of `treasury-payments` (offline-manifest.json): every payment of the company in the
 * document window, whatever its status, in the REST detail shape with its allocations embedded.
 * Allocations are not a resource of their own; a change to one moves the payment's sync
 * position (syncable parent touch, migration 2027_08_01_200700). Movements, transfers and
 * plans are not synced.
 */
final class PaymentSyncResource implements SyncResource
{
    public const string NAME = 'treasury-payments';

    public function name(): string
    {
        return self::NAME;
    }

    public function table(): string
    {
        return 'treasury_payments';
    }

    public function scope(): SyncScope
    {
        return SyncScope::Company;
    }

    /**
     * @return Builder<Model>
     */
    public function query(string $companyId): Builder
    {
        /** @var Builder<Model> */
        return TreasuryPayment::query()
            ->withoutGlobalScopes()
            ->where('treasury_payments.company_id', $companyId)
            // Allocations by the explicit company too, never the ambient CompanyContext.
            ->with(['allocations' => static fn (Relation $relation): mixed => $relation->getQuery()->withoutGlobalScopes()
                ->where('treasury_allocations.company_id', $companyId)]);
    }

    public function positionColumn(): string
    {
        return 'treasury_payments.sync_txid';
    }

    public function resourceClass(): string
    {
        return PaymentResource::class;
    }

    public function embeds(): array
    {
        return ['allocations'];
    }

    public function windowField(): string
    {
        return 'document_date';
    }

    public function parentKey(): ?string
    {
        return null;
    }
}
