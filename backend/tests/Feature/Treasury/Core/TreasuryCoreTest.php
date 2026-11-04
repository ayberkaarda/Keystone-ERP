<?php

declare(strict_types=1);
use App\Modules\Accounting\Models\Account;
use App\Modules\Parties\Models\Party;
use App\Modules\Reference\Contracts\ExchangeRateProvider;
use App\Modules\Treasury\Contracts\TreasuryInvoiceAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Treasury\Core\TreasuryKit as T;
use Tests\Support\Actors;
use Tests\Support\Purchasing\PurchKit as P;

uses(RefreshDatabase::class);
beforeEach(function (): void {
    $this->w = T::world();
});
it('posts an explicit opening balance once with a balanced journal', function (): void {
    $w = $this->w;
    $a = T::opening($w, $w->cash);
    expect($a['balance'])->toBe('1000.0000')->and($a['version'])->toBe(2);
    $m = DB::table('treasury_movements')->where('account_id', $a['id'])->first();
    expect(P::journal($m->journal_entry_id))->toBe(['100' => ['debit' => '1000.00', 'credit' => '0.00'], '500' => ['debit' => '0.00', 'credit' => '1000.00']]);
    P::post($w->user, $w->company, 'treasury-accounts/'.$a['id'].'/opening-balance', ['version' => $a['version'], 'document_date' => '2027-06-01', 'amount' => '1', 'offset_account_id' => T::gl($w, '500'), 'reason' => 'Again'])->assertStatus(422);
});
it('rejects unsafe GL accounts and invalid IBAN without creating money', function (): void {
    $w = $this->w;
    P::post($w->user, $w->company, 'treasury-accounts', ['type' => 'cash', 'code' => 'BAD', 'name' => 'Bad', 'currency_code' => 'TRY', 'gl_account_id' => T::gl($w, '120')])->assertStatus(422);
    P::post($w->user, $w->company, 'treasury-accounts', ['type' => 'bank', 'code' => 'IBAN', 'name' => 'Bad', 'currency_code' => 'TRY', 'gl_account_id' => T::gl($w, '102'), 'iban' => 'TR000000000000000000000000'])->assertStatus(422);
    expect(DB::table('treasury_movements')->count())->toBe(0);
});
it('pays and reverses a supplier invoice atomically', function (): void {
    $w = $this->w;
    T::opening($w, $w->cash);
    $i = T::invoice($w);
    $p = T::payment($w, 'payment', '120', ['allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $i['id'], 'amount' => '120', 'fx_rate' => '1']]]);
    $preview = P::get($w->user, $w->company, 'treasury-payments/'.$p['id'].'/preview')->assertOk()->json('data');
    expect($preview['currency'])->toBe('TRY')->and($preview['advance_local'])->toBe('0.0000');
    $p = T::post($w, $p);
    expect(P::journal($p['journal_entry_id']))->toBe(['100' => ['debit' => '0.00', 'credit' => '120.00'], '320' => ['debit' => '120.00', 'credit' => '0.00']]);
    P::get($w->user, $w->company, 'purchase-invoices/'.$i['id'])->assertJsonPath('data.status', 'paid');
    $r = P::post($w->user, $w->company, 'treasury-payments/'.$p['id'].'/reverse', ['version' => $p['version'], 'date' => '2027-06-14', 'reason' => 'Returned'])->assertOk()->json('data');
    expect($r['status'])->toBe('reversed');
    P::get($w->user, $w->company, 'purchase-invoices/'.$i['id'])->assertJsonPath('data.balance_due', '120.0000')->assertJsonPath('data.status', 'booked');
    P::get($w->user, $w->company, 'treasury-accounts/'.$w->cash['id'].'/balance')->assertJsonPath('data.balance', '1000.0000');
});
it('collects sales receivables rather than treating them as payables', function (): void {
    $w = $this->w;
    $i = T::sale($w);
    $p = T::payment($w, 'receipt', '120', ['party_id' => $i['party_id'], 'allocations' => [['invoice_module' => 'sales', 'invoice_id' => $i['id'], 'amount' => '120', 'fx_rate' => '1']]]);
    $p = T::post($w, $p);
    expect(P::journal($p['journal_entry_id']))->toBe(['100' => ['debit' => '120.00', 'credit' => '0.00'], '120' => ['debit' => '0.00', 'credit' => '120.00']]);
    P::get($w->user, $w->company, 'sales-invoices/'.$i['id'])->assertJsonPath('data.status', 'paid');
});
it('handles partial payments and preserves unallocated advance value', function (): void {
    $w = $this->w;
    T::opening($w, $w->cash);
    $i = T::invoice($w);
    $p = T::post($w, T::payment($w, 'payment', '100', ['allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $i['id'], 'amount' => '60', 'fx_rate' => '1']]]));
    expect($p['allocated_local'])->toBe('60.0000')->and($p['advance_local'])->toBe('40.0000');
    P::get($w->user, $w->company, 'purchase-invoices/'.$i['id'])->assertJsonPath('data.paid_amount', '60.0000')->assertJsonPath('data.balance_due', '60.0000');
    expect(P::journal($p['journal_entry_id'])['320']['debit'])->toBe('100.00');
});
it('refuses over-allocation and mismatched invoice direction', function (): void {
    $w = $this->w;
    $i = T::invoice($w);
    foreach ([['payment', '121'], ['receipt', '120']] as [$direction,$amount]) {
        P::post($w->user, $w->company, 'treasury-payments', ['direction' => $direction, 'account_id' => $w->cash['id'], 'party_id' => $w->party->id, 'document_date' => '2027-06-12', 'amount' => $amount, 'allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $i['id'], 'amount' => $amount, 'fx_rate' => '1']]])->assertStatus(422);
    }expect(DB::table('treasury_payments')->count())->toBe(0);
});
it('rolls settlement and journals back on insufficient funds', function (): void {
    $w = $this->w;
    $i = T::invoice($w);
    $p = T::payment($w, 'payment', '120', ['allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $i['id'], 'amount' => '120', 'fx_rate' => '1']]]);
    $count = DB::table('journal_entries')->count();
    P::post($w->user, $w->company, 'treasury-payments/'.$p['id'].'/post', ['version' => $p['version']])->assertStatus(422);
    expect(DB::table('journal_entries')->count())->toBe($count)->and(DB::table('treasury_movements')->count())->toBe(0);
    P::get($w->user, $w->company, 'purchase-invoices/'.$i['id'])->assertJsonPath('data.balance_due', '120.0000');
});
it('prevents reversing a receipt after its money has been spent', function (): void {
    $w = $this->w;
    $r = T::post($w, T::payment($w, 'receipt', '100'));
    T::post($w, T::payment($w, 'payment', '80'));
    P::post($w->user, $w->company, 'treasury-payments/'.$r['id'].'/reverse', ['version' => $r['version'], 'date' => '2027-06-15', 'reason' => 'Undo'])->assertStatus(422);
    P::get($w->user, $w->company, 'treasury-payments/'.$r['id'])->assertJsonPath('data.status', 'posted');
    P::get($w->user, $w->company, 'treasury-accounts/'.$w->cash['id'].'/balance')->assertJsonPath('data.balance', '20.0000');
});
it('posts and reverses internal transfers including a bank fee', function (): void {
    $w = $this->w;
    T::opening($w, $w->cash);
    $t = P::post($w->user, $w->company, 'treasury-transfers', ['from_account_id' => $w->cash['id'], 'to_account_id' => $w->bank['id'], 'document_date' => '2027-06-12', 'amount' => '200', 'target_amount' => '200', 'source_fx_rate' => '1', 'target_fx_rate' => '1', 'fee_amount' => '5', 'expense_account_id' => T::gl($w, '770')])->assertCreated()->json('data');
    $t = P::post($w->user, $w->company, 'treasury-transfers/'.$t['id'].'/post', ['version' => $t['version']])->assertOk()->json('data');
    expect(P::journal($t['journal_entry_id']))->toBe(['100' => ['debit' => '0.00', 'credit' => '205.00'], '102' => ['debit' => '200.00', 'credit' => '0.00'], '770' => ['debit' => '5.00', 'credit' => '0.00']]);
    P::post($w->user, $w->company, 'treasury-transfers/'.$t['id'].'/reverse', ['version' => $t['version'], 'date' => '2027-06-15', 'reason' => 'Undo'])->assertOk();
    P::get($w->user, $w->company, 'treasury-accounts/'.$w->cash['id'].'/balance')->assertJsonPath('data.balance', '1000.0000');
});
it('requires a real compatible posted payment to fulfil a plan', function (): void {
    $w = $this->w;
    $plan = P::post($w->user, $w->company, 'treasury-payment-plans', ['party_id' => $w->party->id, 'direction' => 'receipt', 'currency_code' => 'TRY', 'amount' => '75', 'due_date' => '2027-06-20'])->assertCreated()->json('data');
    $p = T::payment($w, 'receipt', '75');
    P::post($w->user, $w->company, 'treasury-payment-plans/'.$plan['id'].'/fulfil', ['version' => $plan['version'], 'payment_id' => $p['id']])->assertStatus(422);
    $p = T::post($w, $p);
    P::post($w->user, $w->company, 'treasury-payment-plans/'.$plan['id'].'/fulfil', ['version' => $plan['version'], 'payment_id' => $p['id']])->assertOk()->assertJsonPath('data.status', 'fulfilled');
});
it('serves permission errors before validating an invalid payload', function (): void {
    $w = $this->w;
    $viewer = Actors::user(['treasury.accounts.view']);
    P::post($viewer, $w->company, 'treasury-payments', [])->assertForbidden();
    P::post($viewer, $w->company, 'treasury-accounts', [])->assertForbidden();
    P::get($viewer, $w->company, 'treasury-accounts')->assertOk();
});
it('isolates accounts and invoices between companies', function (): void {
    $w = $this->w;
    $foreign = P::company();
    $user = Actors::user(['treasury.accounts.view'], $foreign);
    P::get($user, $foreign, 'treasury-accounts/'.$w->cash['id'])->assertNotFound();
    $gl = DB::table('accounts')->where('company_id', $foreign)->where('code', '100')->value('id');
    P::post($w->user, $w->company, 'treasury-accounts', ['type' => 'cash', 'code' => 'FOREIGN', 'name' => 'Foreign', 'currency_code' => 'TRY', 'gl_account_id' => $gl])->assertStatus(422);
});
it('rejects stale action versions and preserves one posting on replay', function (): void {
    $w = $this->w;
    $p = T::payment($w, 'receipt', '100');
    P::post($w->user, $w->company, 'treasury-payments/'.$p['id'].'/post', ['version' => 99])->assertConflict();
    $key = (string) Str::uuid7();
    $payload = ['version' => $p['version']];
    $a = P::post($w->user, $w->company, 'treasury-payments/'.$p['id'].'/post', $payload, $key)->assertOk()->json('data');
    $b = P::post($w->user, $w->company, 'treasury-payments/'.$p['id'].'/post', $payload, $key)->assertOk()->json('data');
    expect($a['journal_entry_id'])->toBe($b['journal_entry_id'])->and(DB::table('treasury_movements')->count())->toBe(1);
});
it('keeps posted movements and allocations immutable under raw SQL', function (): void {
    $w = $this->w;
    $p = T::post($w, T::payment($w, 'receipt', '100'));
    expect(fn () => DB::transaction(fn () => DB::table('treasury_movements')->where('source_id', $p['id'])->update(['amount' => '1'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('treasury_payments')->where('id', $p['id'])->update(['amount' => '1'])))->toThrow(QueryException::class);
});
it('uses effective dates for backdated settlement and later reversal history', function (): void {
    $w = $this->w;
    T::opening($w, $w->cash);
    $i = T::invoice($w);
    $p = T::post($w, T::payment($w, 'payment', '60', ['allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $i['id'], 'amount' => '60', 'fx_rate' => '1']]]));
    $read = fn ($date) => P::in($w->company, fn () => app(TreasuryInvoiceAccess::class)->openForCompany('purchasing', 1, 100, $date));
    expect($read('2027-06-11')[0]->balanceDue)->toBe('120.0000')->and($read('2027-06-12')[0]->balanceDue)->toBe('60.0000');
    P::post($w->user, $w->company, 'treasury-payments/'.$p['id'].'/reverse', ['version' => $p['version'], 'date' => '2027-06-14', 'reason' => 'Undo'])->assertOk();
    expect($read('2027-06-13')[0]->balanceDue)->toBe('60.0000')->and($read('2027-06-14')[0]->balanceDue)->toBe('120.0000');
});
it('recognizes realised FX against net payable after withholding', function (): void {
    $w = $this->w;
    $usd = T::account($w, 'USD', 'bank', '102', 'USD');
    T::opening($w, $usd, '1000', '2027-06-01', '30');
    $i = T::invoice($w, '100', ['currency_code' => 'USD', 'fx_rate' => '30', 'lines' => [['item_id' => $w->service->id, 'quantity' => '1', 'unit_price' => '100', 'withholding_code_id' => $w->withholding->id]]]);
    $p = T::payment($w, 'payment', '110', ['account_id' => $usd['id'], 'currency_code' => 'USD', 'fx_rate' => '32', 'allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $i['id'], 'amount' => '110', 'fx_rate' => '32']]]);
    $p = T::post($w, $p);
    expect($p['realized_fx'])->toBe('-220.0000')->and(P::journal($p['journal_entry_id']))->toBe(['102' => ['debit' => '0.00', 'credit' => '3520.00'], '320' => ['debit' => '3300.00', 'credit' => '0.00'], '656' => ['debit' => '220.00', 'credit' => '0.00']]);
});

it('requires source read permission before validating open-item filters', function (): void {
    $w = $this->w;
    $viewer = Actors::user(['treasury.open_items.view']);
    P::get($viewer, $w->company, 'treasury-open-items?module=sales&party_id=invalid')->assertForbidden();
    P::get($viewer, $w->company, 'treasury-open-items?module=purchasing&party_id=invalid')->assertForbidden();
});
it('waits for workflow approval and prevents editing approved allocation payloads', function (): void {
    $w = $this->w;
    T::opening($w, $w->cash);
    P::setting($w->company, 'treasury.require_approval_over', '100');
    $approver = Actors::user(['approval_flows.manage', 'approvals.view', 'approvals.decide']);
    P::post($approver, $w->company, 'approval-flows', ['name' => 'Treasury payments', 'document_type' => 'treasury_payment', 'steps' => [['position' => 1, 'mode' => 'any_of', 'approver_type' => 'user', 'approver_ref' => $approver->id]]])->assertCreated();
    $p = T::payment($w, 'payment', '120');
    P::post($w->user, $w->company, 'treasury-payments/'.$p['id'].'/post', ['version' => $p['version']])->assertStatus(422);
    $p = P::post($w->user, $w->company, 'treasury-payments/'.$p['id'].'/submit', ['version' => $p['version']])->assertOk()->assertJsonPath('data.status', 'pending_approval')->json('data');
    P::post($w->user, $w->company, 'treasury-payments/'.$p['id'].'/post', ['version' => $p['version']])->assertStatus(422);
    P::patch($w->user, $w->company, 'treasury-payments/'.$p['id'], ['version' => $p['version'], 'amount' => '1'])->assertStatus(409)->assertJsonPath('errors.code', 'DOCUMENT_LOCKED');
    $a = P::get($approver, $w->company, 'approvals/'.$p['approval_request_id'])->assertOk()->json('data');
    P::post($approver, $w->company, 'approvals/'.$p['approval_request_id'].'/decisions', ['version' => $a['version'], 'step' => $a['current_step'], 'decision' => 'approve'])->assertOk();
    $p = P::get($w->user, $w->company, 'treasury-payments/'.$p['id'])->assertOk()->assertJsonPath('data.status', 'approved')->json('data');
    T::post($w, $p);
});
it('uses the party role for on-account refunds and requires explicit side for dual roles', function (): void {
    $w = $this->w;
    $supplierRefund = T::post($w, T::payment($w, 'receipt', '100'));
    expect(P::journal($supplierRefund['journal_entry_id'])['320']['credit'])->toBe('100.00');
    $customer = P::in($w->company, fn () => Party::factory()->create(['roles' => ['customer']]));
    $refund = T::post($w, T::payment($w, 'payment', '25', ['party_id' => $customer->id]));
    expect(P::journal($refund['journal_entry_id'])['120']['debit'])->toBe('25.00');
    $dual = P::in($w->company, fn () => Party::factory()->create(['roles' => ['customer', 'supplier']]));
    P::post($w->user, $w->company, 'treasury-payments', ['direction' => 'receipt', 'account_id' => $w->cash['id'], 'party_id' => $dual->id, 'document_date' => '2027-06-12', 'amount' => '10'])->assertStatus(422);
    $p = T::post($w, T::payment($w, 'receipt', '10', ['party_id' => $dual->id, 'counterparty_module' => 'purchasing']));
    expect(P::journal($p['journal_entry_id'])['320']['credit'])->toBe('10.00');
});
it('reopens a fulfilled plan when its posted payment is reversed', function (): void {
    $w = $this->w;
    $plan = P::post($w->user, $w->company, 'treasury-payment-plans', ['party_id' => $w->party->id, 'direction' => 'receipt', 'currency_code' => 'TRY', 'amount' => '75', 'due_date' => '2027-06-20'])->assertCreated()->json('data');
    $p = T::post($w, T::payment($w, 'receipt', '75'));
    P::post($w->user, $w->company, 'treasury-payment-plans/'.$plan['id'].'/fulfil', ['version' => $plan['version'], 'payment_id' => $p['id']])->assertOk();
    P::post($w->user, $w->company, 'treasury-payments/'.$p['id'].'/reverse', ['version' => $p['version'], 'date' => '2027-06-15', 'reason' => 'Undo'])->assertOk();
    P::get($w->user, $w->company, 'treasury-payment-plans/'.$plan['id'])->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.payment_id', null);
});
it('consumes exact remaining local carrying after reversing an earlier partial payment', function (): void {
    $w = $this->w;
    $usd = T::account($w, 'USDROUND', 'bank', '102', 'USD');
    T::opening($w, $usd, '100', '2027-06-01', '0.333333');
    $i = T::invoice($w, '2.5', ['currency_code' => 'USD', 'fx_rate' => '0.333333']);
    expect($i['total'])->toBe('3.0000')->and($i['total_local'])->toBe('1.0000');
    $pay = fn ($amount, $date) => T::post($w, T::payment($w, 'payment', $amount, ['account_id' => $usd['id'], 'currency_code' => 'USD', 'fx_rate' => '0.333333', 'document_date' => $date, 'allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $i['id'], 'amount' => $amount, 'fx_rate' => '0.333333']]]));
    $first = $pay('1', '2027-06-11');
    $second = $pay('1', '2027-06-12');
    expect($first['allocations'][0]['carrying_amount'])->toBe('0.3300')->and($second['allocations'][0]['carrying_amount'])->toBe('0.3400');
    P::post($w->user, $w->company, 'treasury-payments/'.$first['id'].'/reverse', ['version' => $first['version'], 'date' => '2027-06-13', 'reason' => 'Undo first'])->assertOk();
    $snapshot = P::in($w->company, fn () => app(TreasuryInvoiceAccess::class)->openForCompany('purchasing', 1, 100, '2027-06-13'));
    expect($snapshot[0]->balanceLocal)->toBe('0.6600')->and($snapshot[0]->balanceDue)->toBe('2.0000');
    $last = $pay('2', '2027-06-14');
    expect($last['allocations'][0]['carrying_amount'])->toBe('0.6600')->and(P::balance($w->company, '320'))->toBe('0.0000');
});

it('clears the original invoice control account after determination rules change', function (): void {
    $w = $this->w;
    T::opening($w, $w->cash);
    $invoice = T::invoice($w);
    $alternate = P::in($w->company, fn () => Account::factory()->create(['code' => '320.01', 'name' => 'Alternate payable', 'type' => 'liability', 'normal_balance' => 'credit', 'requires_party' => true, 'is_postable' => true, 'parent_id' => T::gl($w, '32')]));
    DB::table('account_determination_rules')->where('company_id', $w->company)->where('event_key', 'purchase.payable')->update(['account_id' => $alternate->id]);
    $payment = T::post($w, T::payment($w, 'payment', '120', ['allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $invoice['id'], 'amount' => '120', 'fx_rate' => '1']]]));
    expect(P::journal($payment['journal_entry_id']))->toBe(['100' => ['debit' => '0.00', 'credit' => '120.00'], '320' => ['debit' => '120.00', 'credit' => '0.00']])->and(P::balance($w->company, '320'))->toBe('0.0000');
});
it('resolves a missing allocation rate in invoice currency rather than bank currency', function (): void {
    $w = $this->w;
    $usd = T::account($w, 'USDMIX', 'bank', '102', 'USD');
    T::opening($w, $usd, '100', '2027-06-01', '30');
    $invoice = T::invoice($w, '2.5', ['currency_code' => 'EUR', 'fx_rate' => '35']);
    $rates = Mockery::mock(ExchangeRateProvider::class);
    $rates->shouldReceive('rate')->once()->with('EUR', 'TRY', Mockery::type(DateTimeInterface::class))->andReturn('40.000000');
    app()->instance(ExchangeRateProvider::class, $rates);
    $payment = T::post($w, T::payment($w, 'payment', '4', ['account_id' => $usd['id'], 'currency_code' => 'USD', 'fx_rate' => '30', 'allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $invoice['id'], 'amount' => '3']]]));
    expect($payment['allocations'][0]['fx_rate'])->toBe('40.000000')->and($payment['realized_fx'])->toBe('-15.0000');
});

it('rejects replacement settlements backdated before an already recorded future reversal', function (): void {
    $w = $this->w;
    T::opening($w, $w->cash, '1000', '2027-01-01');
    $invoice = T::invoice($w, '100', ['document_date' => '2027-01-01']);
    $first = T::post($w, T::payment($w, 'payment', '120', ['document_date' => '2027-01-10', 'allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $invoice['id'], 'amount' => '120', 'fx_rate' => '1']]]));
    P::post($w->user, $w->company, 'treasury-payments/'.$first['id'].'/reverse', ['version' => $first['version'], 'date' => '2027-02-01', 'reason' => 'Later reversal'])->assertOk();
    $replacement = T::payment($w, 'payment', '120', ['document_date' => '2027-01-20', 'allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $invoice['id'], 'amount' => '120', 'fx_rate' => '1']]]);
    $journals = DB::table('journal_entries')->count();
    P::post($w->user, $w->company, 'treasury-payments/'.$replacement['id'].'/post', ['version' => $replacement['version']])->assertStatus(422)->assertJsonStructure(['errors' => ['fields' => ['effective_date']]]);
    expect(DB::table('journal_entries')->count())->toBe($journals)->and(P::in($w->company, fn () => app(TreasuryInvoiceAccess::class)->openForCompany('purchasing', 1, 100, '2027-01-31')))->toBe([]);
    P::get($w->user, $w->company, 'purchase-invoices/'.$invoice['id'])->assertJsonPath('data.balance_due', '120.0000');
});
it('redacts allocation identities without source read permissions and prevents blind edits', function (): void {
    $w = $this->w;
    T::opening($w, $w->cash);
    $invoice = T::invoice($w);
    $payment = T::payment($w, 'payment', '120', ['allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $invoice['id'], 'amount' => '120', 'fx_rate' => '1']]]);
    $viewer = Actors::user(['treasury.payments.view', 'treasury.payments.update']);
    P::get($viewer, $w->company, 'treasury-payments/'.$payment['id'])->assertOk()->assertJsonPath('data.allocations', [])->assertJsonPath('data.allocations_redacted', true);
    P::patch($viewer, $w->company, 'treasury-payments/'.$payment['id'], ['version' => $payment['version'], 'description' => 'Blind edit'])->assertForbidden();
});

it('pages more than five hundred open invoices without per-invoice application queries', function (): void {
    $w = $this->w;
    $invoice = T::invoice($w);
    $template = (array) DB::table('purchase_invoices')->where('id', $invoice['id'])->first();
    $batch = [];
    for ($n = 1; $n <= 500; $n++) {
        $batch[] = [...$template, 'id' => (string) Str::uuid7(), 'number' => 'BULK-'.$n, 'supplier_invoice_no' => 'BULK-'.$n];
    }DB::table('purchase_invoices')->insert($batch);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $first = P::get($w->user, $w->company, 'treasury-open-items?module=purchasing&party_id='.$w->party->id.'&per_page=100&page=1')->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.pagination.total', 501)->assertJsonPath('meta.pagination.last_page', 6)->json('data');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count(array_filter($queries, fn ($q) => str_contains($q['query'], 'purchase_payable_applications'))))->toBeLessThanOrEqual(3);
    $last = P::get($w->user, $w->company, 'treasury-open-items?module=purchasing&party_id='.$w->party->id.'&per_page=100&page=6')->assertOk()->assertJsonCount(1, 'data')->json('data');
    expect(array_intersect(array_column($first, 'id'), array_column($last, 'id')))->toBe([]);
});

it('shows readable source references on opening payment reversal and transfer movements', function (): void {
    $w = $this->w;
    T::opening($w, $w->cash);
    $payment = T::post($w, T::payment($w, 'payment', '25'));
    P::post($w->user, $w->company, 'treasury-payments/'.$payment['id'].'/reverse', ['version' => $payment['version'], 'date' => '2027-06-13', 'reason' => 'Undo'])->assertOk();
    $transfer = P::post($w->user, $w->company, 'treasury-transfers', ['from_account_id' => $w->cash['id'], 'to_account_id' => $w->bank['id'], 'document_date' => '2027-06-14', 'amount' => '50', 'target_amount' => '50', 'source_fx_rate' => '1', 'target_fx_rate' => '1'])->assertCreated()->json('data');
    $transfer = P::post($w->user, $w->company, 'treasury-transfers/'.$transfer['id'].'/post', ['version' => $transfer['version']])->assertOk()->json('data');
    $page = P::get($w->user, $w->company, 'treasury-accounts/'.$w->cash['id'].'/movements')->assertOk()->assertJsonPath('meta.pagination.total', 4)->json('data');
    $refs = array_column($page, 'reference', 'source_type');
    expect($refs)->toBe(['treasury_opening' => 'CASH', 'treasury_payment' => $payment['number'], 'treasury_payment_reversal' => $payment['number'], 'treasury_transfer' => $transfer['number']]);
});
