<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Http\Controllers;

use App\Core\Company\CompanyContext;
use App\Core\Http\ApiResponse;
use App\Core\Http\ListQuery;
use App\Core\Sync\Rules\ClientKey;
use App\Modules\Treasury\Contracts\TreasuryInvoiceAccess;
use App\Modules\Treasury\Enums\AccountType;
use App\Modules\Treasury\Enums\FlowDirection;
use App\Modules\Treasury\Http\Resources\CoreResource;
use App\Modules\Treasury\Http\Resources\PaymentResource;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryPayment;
use App\Modules\Treasury\Models\TreasuryPaymentPlan;
use App\Modules\Treasury\Models\TreasuryRecord;
use App\Modules\Treasury\Models\TreasuryTransfer;
use App\Modules\Treasury\Services\AccountService;
use App\Modules\Treasury\Services\MovementReferences;
use App\Modules\Treasury\Services\PaymentService;
use App\Modules\Treasury\Services\PlanService;
use App\Modules\Treasury\Services\TransferService;
use App\Modules\Treasury\Support\Treasury;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class TreasuryCoreController
{
    public function index(Request $r): JsonResponse
    {
        $area = $this->area($r);
        $this->allow($area, 'view');
        $class = $this->model($area);
        $sorts = $area === 'accounts' ? ['code', 'name', 'type', 'created_at'] : ($area === 'plans' ? ['due_date', 'amount', 'status', 'created_at'] : ['document_date', 'amount', 'status', 'number', 'created_at']);
        $filters = match ($area) {
            'accounts' => ['type', 'currency_code', 'is_active'],'plans' => ['status', 'party_id', 'direction', 'currency_code'],'payments' => ['status', 'party_id', 'account_id', 'direction', 'currency_code'],default => ['status', 'from_account_id', 'to_account_id']
        };
        $search = $area === 'accounts' ? ['code', 'name'] : ($area === 'plans' ? ['description'] : ['number', 'description']);

        $query = $class::query();
        if ($area === 'accounts') {
            $company = app(CompanyContext::class)->id();
            $balances = DB::table('treasury_movements as m')->where('m.company_id', $company)->whereColumn('m.account_id', 'treasury_accounts.id')->where('m.document_date', '<=', now()->toDateString())->selectRaw('coalesce(sum(m.amount),0)');
            $query->select('treasury_accounts.*')->selectSub($balances, 'balance');
        }

        return ApiResponse::paginated(ListQuery::for($query, $r, $sorts, $filters, $search), CoreResource::class);
    }

    public function show(Request $r, string $id): JsonResponse
    {
        $area = $this->area($r);
        $this->allow($area, 'view');

        return ApiResponse::success($this->detail($this->record($area, $id)));
    }

    public function store(Request $r): JsonResponse
    {
        $area = $this->area($r);
        $this->allow($area, $area === 'accounts' ? 'manage' : 'create');
        $data = $this->validateData($r, $area, false);
        $this->sources($data['allocations'] ?? []);
        $record = match ($area) {
            'accounts' => app(AccountService::class)->save(null, $data, null),'payments' => app(PaymentService::class)->save(null, $data, null, $this->actor($r)),'transfers' => app(TransferService::class)->save(null, $data, null, $this->actor($r)),default => app(PlanService::class)->save(null, $data, null)
        };

        return ApiResponse::created($this->detail($record));
    }

    public function update(Request $r, string $id): JsonResponse
    {
        $area = $this->area($r);
        $this->allow($area, $area === 'accounts' ? 'manage' : 'update');
        $record = $this->record($area, $id);
        if ($record instanceof TreasuryPayment) {
            $this->sources(DB::table('treasury_allocations')->where('company_id', $record->company_id)->where('payment_id', $record->id)->get()->map(fn ($a): array => get_object_vars($a))->values()->all());
        }
        $data = $this->validateData($r, $area, true);
        $version = (int) $data['version'];
        unset($data['version']);
        $this->sources($data['allocations'] ?? []);
        $record = match (true) {
            $record instanceof TreasuryAccount => app(AccountService::class)->save($record, $data, $version),$record instanceof TreasuryPayment => app(PaymentService::class)->save($record, $data, $version, $this->actor($r)),$record instanceof TreasuryTransfer => app(TransferService::class)->save($record, $data, $version, $this->actor($r)),$record instanceof TreasuryPaymentPlan => app(PlanService::class)->save($record, $data, $version),
            default => throw new \LogicException('Unknown treasury record.')
        };

        return ApiResponse::success($this->detail($record));
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        $area = $this->area($r);
        $this->allow($area, $area === 'accounts' ? 'manage' : 'delete');
        $version = (int) $r->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $record = $this->record($area, $id);
        match (true) {
            $record instanceof TreasuryAccount => app(AccountService::class)->delete($record, $version),$record instanceof TreasuryPayment => app(PaymentService::class)->delete($id, $version),$record instanceof TreasuryTransfer => app(TransferService::class)->delete($id, $version),default => app(PlanService::class)->delete($id, $version)
        };

        return ApiResponse::noContent();
    }

    public function action(Request $r, string $id): JsonResponse
    {
        $area = $this->area($r);
        $action = (string) $r->route('action');
        $permission = match ($action) {
            'reverse' => 'reverse','opening-balance' => 'manage','fulfil' => 'fulfil','cancel' => 'update',default => 'post'
        };
        $this->allow($area, $permission);
        $this->record($area, $id);
        $rules = ['version' => ['required', 'integer', 'min:1']];
        if ($action === 'reverse') {
            $rules += ['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']];
        }if ($action === 'fulfil') {
            $rules['payment_id'] = ['required', 'uuid'];
        }if ($action === 'opening-balance') {
            $rules += ['document_date' => ['required', 'date_format:Y-m-d'], 'amount' => $this->money(), 'offset_account_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:1000'], 'fx_rate' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,6})?$/', 'gt:0']];
        }$data = $r->validate($rules);
        $version = (int) $data['version'];
        if ($area === 'payments') {
            $p = TreasuryPayment::query()->findOrFail($id);
            $this->sources(DB::table('treasury_allocations')->where('company_id', $p->company_id)->where('payment_id', $id)->get()->map(fn ($a): array => get_object_vars($a))->values()->all());
            $record = match ($action) {
                'submit' => app(PaymentService::class)->submit($id, $version),'post' => app(PaymentService::class)->post($id, $version, $this->actor($r)),'reverse' => app(PaymentService::class)->reverse($id, $version, $data['date'], $data['reason']),default => app(PaymentService::class)->cancel($id, $version)
            };
        } elseif ($area === 'transfers') {
            $record = match ($action) {
                'post' => app(TransferService::class)->post($id, $version, $this->actor($r)),'reverse' => app(TransferService::class)->reverse($id, $version, $data['date'], $data['reason']),default => app(TransferService::class)->cancel($id, $version)
            };
        } elseif ($area === 'plans') {
            $record = $action === 'fulfil' ? app(PlanService::class)->fulfil($id, $version, $data['payment_id']) : app(PlanService::class)->cancel($id, $version);
        } else {
            $record = app(AccountService::class)->opening($id, $data, $this->actor($r));
        }

        return ApiResponse::success($this->detail($record));
    }

    public function preview(Request $r, string $id): JsonResponse
    {
        $this->allow('payments', 'view');
        $p = TreasuryPayment::query()->findOrFail($id);
        $this->sources(DB::table('treasury_allocations')->where('company_id', $p->company_id)->where('payment_id', $id)->get()->map(fn ($a) => (array) $a)->all());
        $preview = app(PaymentService::class)->preview($p);
        $preview['lines'] = array_map(static fn ($line): array => ['account_id' => $line->accountId, 'debit' => $line->debit, 'credit' => $line->credit, 'party_id' => $line->partyId, 'currency_code' => $line->currency, 'fx_amount' => $line->fxAmount, 'fx_rate' => $line->fxRate], $preview['lines']);

        return ApiResponse::success($preview);
    }

    public function balance(Request $r, string $id): JsonResponse
    {
        $this->allow('accounts', 'view');
        $data = $r->validate(['as_of' => ['sometimes', 'date_format:Y-m-d']]);
        $a = app(AccountService::class)->account($id);
        $asOf = $data['as_of'] ?? now()->toDateString();

        return ApiResponse::success(['account_id' => $id, 'currency_code' => $a->getAttribute('currency_code'), 'as_of' => $asOf, 'balance' => app(AccountService::class)->balance($id, $asOf)]);
    }

    public function movements(Request $r, string $id): JsonResponse
    {
        $this->allow('accounts', 'view');
        app(AccountService::class)->account($id);
        $data = $r->validate(['date_from' => ['sometimes', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $q = DB::table('treasury_movements')->where('company_id', app(CompanyContext::class)->id())->where('account_id', $id)->when(isset($data['date_from']), fn ($q) => $q->where('document_date', '>=', $data['date_from']))->when(isset($data['date_to']), fn ($q) => $q->where('document_date', '<=', $data['date_to']));
        $p = $q->orderBy('document_date')->orderBy('id')->paginate($data['per_page'] ?? 25);

        return ApiResponse::success(app(MovementReferences::class)->annotate(array_values($p->items())), ['pagination' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()]]);
    }

    public function openItems(Request $r): JsonResponse
    {
        $this->allow('open_items', 'view');
        $module = $r->input('module');
        if (in_array($module, ['sales', 'purchasing'], true)) {
            Gate::authorize('treasury-access', [$module === 'sales' ? 'sales.invoices.view' : 'purchasing.invoices.view']);
        }
        $data = $r->validate(['module' => ['required', 'in:sales,purchasing'], 'party_id' => ['required', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        Gate::authorize('treasury-access', [$data['module'] === 'sales' ? 'sales.invoices.view' : 'purchasing.invoices.view']);
        $company = app(CompanyContext::class)->id();
        $page = app(TreasuryInvoiceAccess::class)->openForPartyPage($data['module'], $company, $data['party_id'], (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25));
        $rows = $page->items;

        return ApiResponse::success(array_map(fn ($i): array => ['id' => $i->id, 'module' => $i->module, 'number' => $i->number, 'invoice_type' => $i->invoiceType, 'status' => $i->status, 'party_id' => $i->partyId, 'party_name' => $i->partyName, 'document_date' => $i->documentDate, 'due_date' => $i->dueDate, 'currency_code' => $i->currencyCode, 'fx_rate' => $i->fxRate, 'total' => $i->total, 'historical_local' => $i->historicalLocal, 'paid_amount' => $i->paidAmount, 'balance_due' => $i->balanceDue, 'balance_local' => $i->balanceLocal], $rows), ['pagination' => ['current_page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total, 'last_page' => max(1, (int) ceil($page->total / $page->perPage))]]);
    }

    private function area(Request $r): string
    {
        return (string) $r->route('area');
    }

    private function allow(string $area, string $action): void
    {
        Gate::authorize('treasury-access', ['treasury.'.$area.'.'.$action]);
    }

    /** @return class-string<TreasuryRecord> */
    private function model(string $area): string
    {
        return match ($area) {
            'accounts' => TreasuryAccount::class,'payments' => TreasuryPayment::class,'transfers' => TreasuryTransfer::class,default => TreasuryPaymentPlan::class
        };
    }

    private function record(string $area, string $id): TreasuryRecord
    {
        $class = $this->model($area);

        return $class::query()->findOrFail($id);
    }

    private function actor(Request $r): ?string
    {
        $id = $r->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    /** @return array<string,mixed> */
    private function detail(TreasuryRecord $record): array
    {
        $data = $record->toArray();
        if ($record instanceof TreasuryAccount) {
            $data['balance'] = app(AccountService::class)->balance($record->id);
            $data['has_movements'] = DB::table('treasury_movements')->where('company_id', $record->company_id)->where('account_id', $record->id)->exists();
        }if ($record instanceof TreasuryPayment) {
            // The offline sync pulls the same shape (offline-manifest `treasury-payments`).
            $data = (new PaymentResource($record))->resolve();
        }

        return $data;
    }

    /** @param array<int,array<string,mixed>> $allocations */
    private function sources(array $allocations): void
    {
        foreach (array_unique(array_map(fn ($a) => (string) ($a['invoice_module'] ?? ''), $allocations)) as $module) {
            if (in_array($module, ['sales', 'purchasing'], true)) {
                Gate::authorize('treasury-access', [$module === 'sales' ? 'sales.invoices.view' : 'purchasing.invoices.view']);
            }
        }
    }

    /** @return list<string> */
    private function money(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'regex:/^\d{1,16}(\.\d{1,4})?$/', 'gt:0'];
    }

    /** @return array<string,mixed> */
    private function validateData(Request $r, string $area, bool $update): array
    {
        $required = $update ? 'sometimes' : 'required';
        $uuid = [$required, 'uuid'];
        $money = $this->money(! $update);
        $rate = ['sometimes', 'nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,6})?$/', 'gt:0'];
        $rules = match ($area) {
            'accounts' => ['type' => [$required, Rule::enum(AccountType::class)], 'code' => [$required, 'string', 'max:30'], 'name' => [$required, 'string', 'max:200'], 'currency_code' => [$required, 'string', 'size:3'], 'gl_account_id' => $uuid, 'bank_name' => ['sometimes', 'nullable', 'string', 'max:200'], 'branch' => ['sometimes', 'nullable', 'string', 'max:100'], 'iban' => ['sometimes', 'nullable', 'string', 'max:42'], 'is_active' => ['sometimes', 'boolean'], 'allow_negative' => ['sometimes', 'boolean']],
            'payments' => ['counterparty_module' => ['sometimes', 'nullable', 'in:sales,purchasing'], 'direction' => [$required, Rule::enum(FlowDirection::class)], 'account_id' => $uuid, 'party_id' => $uuid, 'document_date' => [$required, 'date_format:Y-m-d'], 'currency_code' => ['sometimes', 'string', 'size:3'], 'amount' => $money, 'fx_rate' => $rate, 'reference' => ['sometimes', 'nullable', 'string', 'max:100'], 'description' => ['sometimes', 'nullable', 'string', 'max:5000'], 'allocations' => ['sometimes', 'array', 'max:100'], 'allocations.*' => ['array:invoice_module,invoice_id,amount,fx_rate'], 'allocations.*.invoice_module' => ['required', 'in:sales,purchasing'], 'allocations.*.invoice_id' => ['required', 'uuid', 'distinct'], 'allocations.*.amount' => $this->money(), 'allocations.*.fx_rate' => $rate],
            'transfers' => ['from_account_id' => $uuid, 'to_account_id' => $uuid, 'document_date' => [$required, 'date_format:Y-m-d'], 'amount' => $money, 'target_amount' => $money, 'source_fx_rate' => $rate, 'target_fx_rate' => $rate, 'fee_amount' => ['sometimes', 'string', 'regex:/^\d{1,16}(\.\d{1,4})?$/'], 'expense_account_id' => ['sometimes', 'nullable', 'uuid'], 'description' => ['sometimes', 'nullable', 'string', 'max:5000']],
            default => ['party_id' => $uuid, 'account_id' => ['sometimes', 'nullable', 'uuid'], 'direction' => [$required, Rule::enum(FlowDirection::class)], 'currency_code' => [$required, 'string', 'size:3'], 'amount' => $money, 'due_date' => [$required, 'date_format:Y-m-d'], 'description' => ['sometimes', 'nullable', 'string', 'max:5000']]
        };
        if ($update) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        } elseif ($area === 'payments') {
            // A client-generated key, as the offline draft carries it (SYNC-PROTOCOL "Mutasyon").
            $rules['id'] = ['sometimes', 'bail', new ClientKey('treasury_payments'), 'string'];
        }$unknown = array_diff(array_keys($r->all()), array_keys($rules));
        if ($unknown !== []) {
            Treasury::fail((string) reset($unknown), 'unknown');
        }

        return $r->validate($rules);
    }
}
