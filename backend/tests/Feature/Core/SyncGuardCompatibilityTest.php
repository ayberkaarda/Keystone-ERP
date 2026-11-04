<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Companies;
use Tests\Support\Sync;

/*
 * The real treasury_payments_immutable guard compares to_jsonb(OLD) and to_jsonb(NEW) minus
 * the reversal fields. With the sync tracking of migration 2027_08_01_200700 bound to the table,
 * a posted -> reversed transition must still pass and any other change must still fail.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->id = (string) Str::uuid7();
    $company = Companies::make();

    // Bare account and party rows; their own references are irrelevant here, so foreign keys
    // and triggers are off while they and the posted payment are written.
    $account = (string) Str::uuid7();
    $party = (string) Str::uuid7();
    DB::statement('SET LOCAL session_replication_role = replica');
    DB::table('treasury_accounts')->insert([
        'id' => $account, 'company_id' => $company, 'type' => 'cash', 'code' => 'K1', 'name' => 'Cash',
        'currency_code' => 'TRY', 'gl_account_id' => (string) Str::uuid7(),
    ]);
    DB::table('parties')->insert(['id' => $party, 'company_id' => $company, 'code' => 'P1', 'name' => 'Party', 'type' => 'company', 'roles' => '["customer"]']);
    DB::table('treasury_payments')->insert([
        'id' => $this->id, 'company_id' => $company, 'number' => 'TP-1', 'document_date' => '2027-08-01',
        'status' => 'posted', 'journal_entry_id' => (string) Str::uuid7(), 'version' => 3,
        'direction' => 'receipt', 'account_id' => $account, 'party_id' => $party,
        'currency_code' => 'TRY', 'amount' => '100.0000', 'fx_rate' => '1.000000',
    ]);
    // A position from an earlier transaction, so a restamp would be visible to the guard.
    DB::table('treasury_payments')->where('id', $this->id)->update(['sync_txid' => DB::raw("'1'::xid8")]);
    DB::statement('SET LOCAL session_replication_role = origin');
});

it('lets a posted payment be reversed and restamps it', function (): void {
    DB::table('treasury_payments')->where('id', $this->id)->update([
        'status' => 'reversed',
        'reversal_journal_entry_id' => (string) Str::uuid7(),
        'reversal_date' => '2027-08-02',
        'reversal_reason' => 'Entered twice',
        'version' => 4,
    ]);

    expect(DB::table('treasury_payments')->where('id', $this->id)->value('status'))->toBe('reversed')
        ->and(Sync::txidOf('treasury_payments', $this->id))->toEqual(Sync::currentTxid());
});

it('still refuses other changes to a posted payment', function (): void {
    expect(fn () => DB::table('treasury_payments')->where('id', $this->id)->update(['amount' => '90.0000']))
        ->toThrow(QueryException::class, 'Posted treasury documents are immutable');
});
