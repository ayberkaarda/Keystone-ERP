<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Core\Http\ApiException;
use App\Core\Http\ErrorCode;
use App\Core\Numbering\NumberSequenceService;
use App\Modules\Accounting\Contracts\AccountDeterminer;
use App\Modules\Accounting\Contracts\PostingService;
use App\Modules\Accounting\Data\PostingLine;
use App\Modules\Accounting\Data\PostingRequest;
use App\Modules\Accounting\Enums\EntryType;
use App\Modules\Parties\Contracts\PartyLookup;
use App\Modules\Treasury\Contracts\PaymentReversalGuard;
use App\Modules\Treasury\Contracts\TreasuryInvoiceAccess;
use App\Modules\Treasury\Contracts\TreasuryPaymentLookup;
use App\Modules\Treasury\Data\TreasuryPaymentData;
use App\Modules\Treasury\Enums\DocumentStatus;
use App\Modules\Treasury\Enums\FlowDirection;
use App\Modules\Treasury\Enums\PlanStatus;
use App\Modules\Treasury\Models\TreasuryPayment;
use App\Modules\Treasury\Models\TreasuryPaymentPlan;
use App\Modules\Treasury\Support\Treasury;
use App\Modules\Treasury\Support\TreasurySettings;
use App\Modules\Workflow\Contracts\ApprovalEngine;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PaymentService implements TreasuryPaymentLookup
{
    public const string APPROVAL_TYPE = 'treasury_payment';

    public function find(string $id): ?TreasuryPaymentData
    {
        $p = Str::isUuid($id) ? TreasuryPayment::query()->where('status', DocumentStatus::Posted->value)->find($id) : null;

        return $p === null ? null : $this->data($p);
    }

    public function forAccount(string $accountId, string $from, string $to, int $page = 1, int $perPage = 100): array
    {
        return array_values(TreasuryPayment::query()->where('account_id', $accountId)->where('status', DocumentStatus::Posted->value)->whereBetween('document_date', [$from, $to])->orderBy('document_date')->orderBy('id')->offset((max(1, $page) - 1) * min(500, max(1, $perPage)))->limit(min(500, max(1, $perPage)))->get()->map(fn (TreasuryPayment $p) => $this->data($p))->all());
    }

    /** @param array<string,mixed> $input */
    public function save(?TreasuryPayment $payment, array $input, ?int $version, ?string $actor): TreasuryPayment
    {
        return DB::transaction(function () use ($payment, $input, $version, $actor): TreasuryPayment {
            if ($payment !== null) {
                $payment = $this->lock($payment->id);
                $this->assertDraft($payment);
                $payment->assertVersion($version ?? 0);
            } else {
                $payment = new TreasuryPayment;
                if (isset($input['id']) && is_string($input['id'])) {
                    // Client key of an offline draft, checked by ClientKey in the request.
                    $payment->setAttribute('id', $input['id']);
                }
            }
            $merged = [...$payment->getAttributes(), ...$input];
            $account = app(AccountService::class)->account((string) $merged['account_id']);
            if (! $account->getAttribute('is_active')) {
                Treasury::fail('account_id', 'inactive');
            }
            $party = app(PartyLookup::class)->find((string) $merged['party_id']);
            if ($party === null || ! $party->isActive) {
                Treasury::fail('party_id', 'unknown');
            }
            if (isset($merged['currency_code']) && $merged['currency_code'] !== $account->getAttribute('currency_code')) {
                Treasury::fail('currency_code', 'currency_invalid');
            }
            $merged['currency_code'] = (string) $account->getAttribute('currency_code');
            $merged['fx_rate'] = app(TreasurySettings::class)->rate($merged['currency_code'], (string) $merged['document_date'], isset($merged['fx_rate']) ? (string) $merged['fx_rate'] : null);
            $allocations = $input['allocations'] ?? null;
            unset($merged['allocations'],$merged['version'],$merged['id'],$merged['company_id'],$merged['created_at'],$merged['updated_at']);
            $payment->fill($merged);
            if ($allocations !== null && $payment->exists) {
                // Replacing the allocations is an edit of the payment: the version moves, so a
                // stale writer (REST or an offline draft) conflicts instead of overwriting them.
                $payment->bumpVersion();
            }
            if (! $payment->exists) {
                $payment->setAttribute('status', DocumentStatus::Draft->value);
                $payment->setAttribute('created_by', $actor);
            }
            $payment->save();
            if ($allocations !== null) {
                DB::table('treasury_allocations')->where('company_id', $payment->company_id)->where('payment_id', $payment->id)->delete();
                foreach ($allocations as $a) {
                    DB::table('treasury_allocations')->insert(['id' => (string) Str::uuid7(), 'company_id' => $payment->company_id, 'payment_id' => $payment->id, 'invoice_module' => $a['invoice_module'], 'invoice_id' => $a['invoice_id'], 'amount' => Treasury::money($a['amount']), 'fx_rate' => isset($a['fx_rate']) ? (string) $a['fx_rate'] : $this->allocationRate((string) $a['invoice_module'], (string) $a['invoice_id'], (string) $merged['currency_code'], (string) $merged['document_date'], (string) $merged['fx_rate']), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $this->preview($payment);

            return $payment;
        });
    }

    /** @return array<string,mixed> */
    public function preview(TreasuryPayment $payment): array
    {
        $account = app(AccountService::class)->account((string) $payment->getAttribute('account_id'));
        $cashLocal = Treasury::local(Treasury::decimal((string) $payment->getAttribute('amount'))->multipliedBy((string) $payment->getAttribute('fx_rate')));
        if (! Treasury::decimal($cashLocal)->isPositive()) {
            Treasury::fail('amount', 'positive');
        }
        $receipt = $payment->getAttribute('direction') === FlowDirection::Receipt->value;
        $lines = [new PostingLine(accountId: (string) $account->getAttribute('gl_account_id'), debit: $receipt ? $cashLocal : '0', credit: $receipt ? '0' : $cashLocal, currency: (string) $payment->getAttribute('currency_code'), fxAmount: (string) $payment->getAttribute('amount'), fxRate: (string) $payment->getAttribute('fx_rate'))];
        $allocated = Treasury::decimal('0');
        $fx = Treasury::decimal('0');
        $allocations = [];
        $modules = [];
        $rows = DB::table('treasury_allocations')->where('company_id', $payment->company_id)->where('payment_id', $payment->id)->orderBy('invoice_module')->orderBy('invoice_id')->get();
        foreach ($rows as $row) {
            $i = app(TreasuryInvoiceAccess::class)->find((string) $row->invoice_module, (string) $row->invoice_id);
            if ($i === null || $i->companyId !== $payment->company_id || $i->partyId !== $payment->getAttribute('party_id') || $i->direction() !== $payment->getAttribute('direction') || ! in_array($i->status, ['issued', 'booked', 'partially_paid'], true)) {
                Treasury::fail('allocations', 'invoice_invalid');
            }
            $amount = Treasury::decimal((string) $row->amount);
            if (! $amount->isPositive() || $amount->isGreaterThan($i->balanceDue)) {
                Treasury::fail('allocations', 'exceeds_balance');
            }
            if ((string) $payment->getAttribute('document_date') < $i->documentDate) {
                Treasury::fail('document_date', 'date_invalid');
            }
            $rate = app(TreasurySettings::class)->rate($i->currencyCode, (string) $payment->getAttribute('document_date'), (string) $row->fx_rate);
            if ($i->currencyCode === $payment->getAttribute('currency_code') && ! Treasury::decimal($rate)->isEqualTo((string) $payment->getAttribute('fx_rate'))) {
                Treasury::fail('allocations', 'rate_mismatch');
            }
            $settlement = Treasury::local($amount->multipliedBy($rate));
            if ($i->balanceLocal === null) {
                throw new \LogicException('Invoice lookup must provide exact local balance.');
            }
            $carrying = $amount->isEqualTo($i->balanceDue) ? Treasury::decimal($i->balanceLocal) : Treasury::decimal(Treasury::local(Treasury::decimal($i->balanceLocal)->multipliedBy($amount)->dividedBy($i->balanceDue, 8, RoundingMode::HalfUp)));
            $difference = Treasury::decimal($settlement)->minus($carrying);
            $allocated = $allocated->plus($settlement);
            $fx = $fx->plus($receipt ? $difference : $difference->negated());
            $event = $i->module === 'sales' ? 'sales.receivable' : 'purchase.payable';
            if ($i->controlAccountId === null) {
                Treasury::fail('allocations', 'account_invalid');
            }
            $partyAccountId = $i->controlAccountId;
            $lines[] = new PostingLine(accountId: $partyAccountId, debit: $receipt ? '0' : Treasury::money($carrying), credit: $receipt ? Treasury::money($carrying) : '0', partyId: $i->partyId, currency: $i->currencyCode, fxAmount: (string) $row->amount, fxRate: $i->fxRate, documentRef: $i->number);
            $allocations[] = ['id' => (string) $row->id, 'invoice_module' => $i->module, 'invoice_id' => $i->id, 'amount' => (string) $row->amount, 'fx_rate' => $rate, 'carrying_amount' => Treasury::money($carrying), 'settlement_amount' => $settlement, 'realized_fx' => Treasury::money($receipt ? $difference : $difference->negated())];
            $modules[$i->module] = true;
        }
        if ($allocated->isGreaterThan($cashLocal)) {
            Treasury::fail('allocations', 'exceeds_amount');
        }
        $advance = Treasury::decimal($cashLocal)->minus($allocated);
        if ($advance->isPositive()) {
            $module = $payment->getAttribute('counterparty_module');
            $party = app(PartyLookup::class)->find((string) $payment->getAttribute('party_id'));
            if ($party === null) {
                Treasury::fail('party_id', 'unknown');
            }
            if ($module === null && count($modules) === 1) {
                $module = array_key_first($modules);
            }
            if ($module === null && count($modules) === 0) {
                if ($party->isCustomer() && ! $party->isSupplier()) {
                    $module = 'sales';
                }
                if ($party->isSupplier() && ! $party->isCustomer()) {
                    $module = 'purchasing';
                }
            }
            if (! in_array($module, ['sales', 'purchasing'], true) || ($module === 'sales' && ! $party->isCustomer()) || ($module === 'purchasing' && ! $party->isSupplier())) {
                Treasury::fail('counterparty_module', 'module_invalid');
            }
            $partyAccount = app(AccountDeterminer::class)->resolve($module === 'sales' ? 'sales.receivable' : 'purchase.payable', ['party_id' => (string) $payment->getAttribute('party_id'), 'currency' => (string) $payment->getAttribute('currency_code')]);
            $lines[] = new PostingLine(accountId: $partyAccount->id, debit: $receipt ? '0' : Treasury::money($advance), credit: $receipt ? Treasury::money($advance) : '0', partyId: (string) $payment->getAttribute('party_id'), currency: (string) $payment->getAttribute('currency_code'), fxAmount: Treasury::money($advance->dividedBy((string) $payment->getAttribute('fx_rate'), 8, RoundingMode::HalfUp)), fxRate: (string) $payment->getAttribute('fx_rate'));
        }
        if (! $fx->isZero()) {
            $ref = app(AccountDeterminer::class)->resolve($fx->isPositive() ? 'fx.gain' : 'fx.loss');
            $lines[] = $fx->isPositive() ? PostingLine::credit($ref->id, Treasury::money($fx)) : PostingLine::debit($ref->id, Treasury::money($fx->abs()));
        }

        return ['currency' => app(TreasurySettings::class)->baseCurrency(), 'amount_local' => $cashLocal, 'allocated_local' => Treasury::money($allocated), 'advance_local' => Treasury::money($advance), 'realized_fx' => Treasury::money($fx), 'allocations' => $allocations, 'lines' => $lines];
    }

    public function submit(string $id, int $version): TreasuryPayment
    {
        return DB::transaction(function () use ($id, $version): TreasuryPayment {
            $p = $this->lock($id);
            $p->assertVersion($version);
            $this->status($p, [DocumentStatus::Draft->value, DocumentStatus::Approved->value]);
            $preview = $this->preview($p);
            $threshold = app(TreasurySettings::class)->approvalThreshold();
            if ($threshold === null || Treasury::decimal((string) $preview['amount_local'])->isLessThan($threshold)) {
                $p->setAttribute('status', DocumentStatus::Approved->value);
                $p->save();

                return $p;
            }
            $p->setAttribute('status', DocumentStatus::PendingApproval->value);
            $p->save();
            $request = app(ApprovalEngine::class)->start($p, self::APPROVAL_TYPE, (string) $preview['amount_local'], (string) $p->currentVersion());
            $p->setAttribute('approval_request_id', (string) $request->getKey());
            if ($request->getAttribute('status') === DocumentStatus::Approved->value) {
                $p->setAttribute('status', DocumentStatus::Approved->value);
            }
            $p->save();

            return $p;
        });
    }

    public function post(string $id, int $version, ?string $actor): TreasuryPayment
    {
        return DB::transaction(function () use ($id, $version, $actor): TreasuryPayment {
            $p = $this->lock($id);
            $p->assertVersion($version);
            $this->status($p, [DocumentStatus::Draft->value, DocumentStatus::Approved->value]);
            app(AccountService::class)->account((string) $p->getAttribute('account_id'), true);
            foreach (DB::table('treasury_allocations')->where('company_id', $p->company_id)->where('payment_id', $p->id)->orderBy('invoice_module')->orderBy('invoice_id')->get() as $lockedAllocation) {
                if (app(TreasuryInvoiceAccess::class)->findForUpdate((string) $lockedAllocation->invoice_module, (string) $lockedAllocation->invoice_id) === null) {
                    Treasury::fail('allocations', 'invoice_invalid');
                }
            }
            $preview = $this->preview($p);
            $threshold = app(TreasurySettings::class)->approvalThreshold();
            if ($threshold !== null && Treasury::decimal((string) $preview['amount_local'])->isGreaterThanOrEqualTo($threshold) && ($p->getAttribute('status') !== DocumentStatus::Approved->value || $p->getAttribute('approval_request_id') === null)) {
                Treasury::fail('status', 'approval_required');
            }
            foreach ($preview['allocations'] as $a) {
                app(TreasuryInvoiceAccess::class)->apply($a['invoice_module'], $p->company_id, $a['invoice_id'], $a['amount'], 'treasury:'.$a['id'], (string) $p->getAttribute('document_date'), $a['carrying_amount']);
                DB::table('treasury_allocations')->where('id', $a['id'])->where('company_id', $p->company_id)->update(['carrying_amount' => $a['carrying_amount'], 'settlement_amount' => $a['settlement_amount'], 'realized_fx' => $a['realized_fx']]);
            }
            $journal = app(PostingService::class)->post(new PostingRequest($p->company_id, new DateTimeImmutable((string) $p->getAttribute('document_date')), $p->getAttribute('direction') === FlowDirection::Receipt->value ? EntryType::Receipt : EntryType::Payment, $p->getAttribute('description'), 'treasury_payment', $p->id, $preview['lines'], postedBy: $actor));
            $amount = (string) $p->getAttribute('amount');
            $local = (string) $preview['amount_local'];
            if ($p->getAttribute('direction') === FlowDirection::Payment->value) {
                $amount = (string) Treasury::decimal($amount)->negated();
                $local = (string) Treasury::decimal($local)->negated();
            }app(AccountService::class)->record((string) $p->getAttribute('account_id'), 'treasury_payment', $p->id, (string) $p->getAttribute('document_date'), $amount, $local, $journal->id);
            $number = app(NumberSequenceService::class)->next($p->company_id, 'treasury_payment', 'TP', (int) substr((string) $p->getAttribute('document_date'), 0, 4));
            $p->fill(['status' => DocumentStatus::Posted->value, 'number' => $number, 'journal_entry_id' => $journal->id, 'amount_local' => $preview['amount_local'], 'allocated_local' => $preview['allocated_local'], 'advance_local' => $preview['advance_local'], 'realized_fx' => $preview['realized_fx']]);
            $p->save();

            return $p;
        });
    }

    public function reverse(string $id, int $version, string $date, string $reason): TreasuryPayment
    {
        return DB::transaction(function () use ($id, $version, $date, $reason): TreasuryPayment {
            $p = $this->lock($id);
            $p->assertVersion($version);
            $this->status($p, [DocumentStatus::Posted->value]);
            if ($date < (string) $p->getAttribute('document_date')) {
                Treasury::fail('date', 'date_invalid');
            }app(PaymentReversalGuard::class)->assertUnmatched($p->id);
            foreach (TreasuryPaymentPlan::query()->where('payment_id', $p->id)->where('status', PlanStatus::Fulfilled->value)->orderBy('id')->lockForUpdate()->get() as $plan) {
                $plan->fill(['payment_id' => null, 'status' => PlanStatus::Scheduled->value]);
                $plan->save();
            }
            app(AccountService::class)->account((string) $p->getAttribute('account_id'), true);
            $allocations = DB::table('treasury_allocations')->where('company_id', $p->company_id)->where('payment_id', $p->id)->orderBy('invoice_module')->orderBy('invoice_id')->get();
            foreach ($allocations as $a) {
                app(TreasuryInvoiceAccess::class)->unapply((string) $a->invoice_module, $p->company_id, (string) $a->invoice_id, 'treasury:'.$a->id, $date);
            }
            $journal = app(PostingService::class)->reverse((string) $p->getAttribute('journal_entry_id'), new DateTimeImmutable($date), $reason);
            $movement = DB::table('treasury_movements')->where('company_id', $p->company_id)->where('source_type', 'treasury_payment')->where('source_id', $p->id)->first();
            if ($movement === null) {
                throw new \LogicException('Posted payment movement missing.');
            }app(AccountService::class)->record((string) $p->getAttribute('account_id'), 'treasury_payment_reversal', $p->id, $date, (string) Treasury::decimal((string) $movement->amount)->negated(), (string) Treasury::decimal((string) $movement->local_amount)->negated(), $journal->id, (string) $movement->id);
            $p->fill(['status' => DocumentStatus::Reversed->value, 'reversal_journal_entry_id' => $journal->id, 'reversal_date' => $date, 'reversal_reason' => $reason]);
            $p->save();

            return $p;
        });
    }

    public function cancel(string $id, int $version): TreasuryPayment
    {
        return DB::transaction(function () use ($id, $version): TreasuryPayment {
            $p = $this->lock($id);
            $p->assertVersion($version);
            $this->status($p, [DocumentStatus::Draft->value, DocumentStatus::Approved->value]);
            $p->setAttribute('status', DocumentStatus::Cancelled->value);
            $p->save();

            return $p;
        });
    }

    public function delete(string $id, int $version): void
    {
        DB::transaction(function () use ($id, $version): void {
            $p = $this->lock($id);
            $this->assertDraft($p);
            $p->assertVersion($version);
            DB::table('treasury_allocations')->where('payment_id', $p->id)->where('company_id', $p->company_id)->delete();
            $p->delete();
        });
    }

    public function approval(string $id, string $requestId, bool $approved): void
    {
        DB::transaction(function () use ($id, $requestId, $approved): void {
            $p = TreasuryPayment::query()->whereKey($id)->lockForUpdate()->first();
            if ($p === null || $p->getAttribute('status') !== DocumentStatus::PendingApproval->value || $p->getAttribute('approval_request_id') !== $requestId) {
                return;
            }
            $p->setAttribute('status', $approved ? DocumentStatus::Approved->value : DocumentStatus::Draft->value);
            if (! $approved) {
                $p->setAttribute('approval_request_id', null);
            }
            $p->save();
        });
    }

    private function lock(string $id): TreasuryPayment
    {
        return TreasuryPayment::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Edits and deletes are for drafts only; any other status is locked (409 DOCUMENT_LOCKED,
     * REST and offline sync alike), checked before the version so a stale edit of a posted
     * payment is told it is locked, not merely stale.
     */
    private function assertDraft(TreasuryPayment $p): void
    {
        if ($p->getAttribute('status') !== DocumentStatus::Draft->value) {
            throw new ApiException(ErrorCode::DocumentLocked, (string) __('treasury::messages.document_locked'));
        }
    }

    /** @param list<string> $statuses */
    private function status(TreasuryPayment $p, array $statuses): void
    {
        if (! in_array($p->getAttribute('status'), $statuses, true)) {
            Treasury::fail('status', 'transition');
        }
    }

    private function data(TreasuryPayment $p): TreasuryPaymentData
    {
        return new TreasuryPaymentData($p->id, $p->company_id, (string) $p->getAttribute('account_id'), (string) $p->getAttribute('party_id'), (string) $p->getAttribute('direction'), (string) $p->getAttribute('amount'), (string) $p->getAttribute('currency_code'), (string) $p->getAttribute('document_date'), (string) $p->getAttribute('status'), $p->getAttribute('journal_entry_id'), $p->getAttribute('reference'), $p->currentVersion());
    }

    public function findForUpdate(string $id): ?TreasuryPaymentData
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Payment locks require a transaction.');
        }
        $p = TreasuryPayment::query()->whereKey($id)->where('status', DocumentStatus::Posted->value)->lockForUpdate()->first();

        return $p === null ? null : $this->data($p);
    }

    private function allocationRate(string $module, string $invoiceId, string $paymentCurrency, string $date, string $paymentRate): string
    {
        $invoice = app(TreasuryInvoiceAccess::class)->find($module, $invoiceId);
        if ($invoice === null) {
            Treasury::fail('allocations', 'invoice_invalid');
        }

        return $invoice->currencyCode === $paymentCurrency ? $paymentRate : app(TreasurySettings::class)->rate($invoice->currencyCode, $date, null);
    }
}
