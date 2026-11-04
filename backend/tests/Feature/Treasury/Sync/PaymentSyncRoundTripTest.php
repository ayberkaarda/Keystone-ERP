<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sync\Support\SyncKit;
use Tests\Feature\Treasury\Core\TreasuryKit as T;
use Tests\Fixtures\Sync\SyncDatabase;
use Tests\Support\Actors;
use Tests\Support\Identity;
use Tests\Support\Purchasing\PurchKit as P;

/*
 * The desktop round trip of offline payment drafts over committed data: device login, manifest,
 * full download, push of an offline draft, incremental pull and the tombstone of a deleted
 * draft, every body checked against the wire schemas and the REST detail.
 */
uses(SyncDatabase::class);

beforeEach(function (): void {
    $this->w = T::world();
});

/** @return array<string, string> */
function tprHeaders(object $test, string $token): array
{
    return ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'X-Company-Id' => $test->w->company];
}

function tprLogin(object $test, string $email): string
{
    Identity::fresh();

    return (string) $test->postJson('/api/v1/auth/device', [
        'email' => $email, 'password' => Identity::PASSWORD, 'device_name' => 'Kasa PC', 'fingerprint' => 'fp-treasury-e2e-'.substr(md5($email), 0, 8),
    ])->assertOk()->json('data.token');
}

/**
 * @param  array<string, mixed>|null  $cursor
 * @return array<string, mixed>
 */
function tprPull(object $test, string $token, ?array $cursor): array
{
    $response = SyncKit::pull($test, [['resource' => 'treasury-payments', 'cursor' => $cursor, 'limit' => 200]], tprHeaders($test, $token))->assertOk();

    return SyncKit::wire($response, 'sync/pull.response.schema.json')['data']['resources'][0];
}

/**
 * @param  list<array<string, mixed>>  $mutations
 * @return list<array<string, mixed>>
 */
function tprPush(object $test, string $token, array $mutations): array
{
    return SyncKit::wire(SyncKit::push($test, $mutations, tprHeaders($test, $token))->assertOk(), 'sync/push.response.schema.json')['data']['results'];
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function tprMutation(string $op, string $id, array $payload = [], ?int $base = null): array
{
    return [
        'idempotency_key' => (string) Str::uuid7(), 'op' => $op, 'resource' => 'treasury-payments', 'id' => $id,
        'base_version' => $op === 'create' ? null : $base, 'changed_fields' => $op === 'update' ? array_keys($payload) : [],
        'occurred_at' => '2027-06-12T10:00:00+03:00', 'payload' => $payload === [] ? new stdClass : $payload,
    ];
}

it('downloads, pushes and pulls payment drafts, and keeps posted payments read only', function (): void {
    $w = $this->w;
    T::opening($w, $w->cash);
    $invoice = T::invoice($w);
    $draft = T::payment($w, 'payment', '120', ['description' => 'Web taslağı', 'allocations' => [['invoice_module' => 'purchasing', 'invoice_id' => $invoice['id'], 'amount' => '100', 'fx_rate' => '1']]]);
    $posted = T::post($w, T::payment($w, 'payment', '40'));
    $viewer = Actors::user(['treasury.payments.view'], $w->company);
    SyncKit::waitVisible();

    $token = tprLogin($this, $w->user->email);
    $manifest = SyncKit::wire(SyncKit::manifestOf($this, tprHeaders($this, $token))->assertOk(), 'sync/manifest.response.schema.json')['data'];
    $viewerManifest = SyncKit::manifestOf($this, tprHeaders($this, tprLogin($this, $viewer->email)))->assertOk()->json('data');
    expect(array_column($manifest['resources'], 'writable', 'resource')['treasury-payments'])->toBe(['create', 'update', 'delete'])
        ->and(array_column($viewerManifest['resources'], 'writable', 'resource')['treasury-payments'])->toBe([]);

    // Full download: every status, each row exactly the REST detail.
    $full = tprPull($this, $token, null);
    $rows = array_column($full['rows'], null, 'id');
    expect(array_keys($rows))->toEqualCanonicalizing([$draft['id'], $posted['id']]);

    foreach ($rows as $id => $row) {
        expect($row)->toBe(P::get($w->user, $w->company, "treasury-payments/{$id}")->assertOk()->json('data'));
    }

    expect($rows[$draft['id']]['allocations'][0]['invoice_id'])->toBe($invoice['id'])
        ->and($rows[$posted['id']]['number'])->toStartWith('TP')
        ->and($rows[$posted['id']]['status'])->toBe('posted');

    // Push: an offline draft, an edit of the web draft, an edit of the posted payment.
    $offline = (string) Str::uuid7();
    $journals = DB::table('journal_entries')->count();
    $results = tprPush($this, $token, [
        tprMutation('create', $offline, ['direction' => 'payment', 'account_id' => $w->cash['id'], 'party_id' => $w->party->id, 'document_date' => '2027-06-12', 'amount' => '35', 'fx_rate' => '1', 'description' => 'Çevrimdışı masraf', 'currency_code' => 'TRY', 'allocations' => []]),
        tprMutation('update', $draft['id'], ['reference' => 'DESK-1'], $draft['version']),
        tprMutation('update', $posted['id'], ['description' => 'late'], $posted['version']),
    ]);

    expect(array_column($results, 'status'))->toBe(['applied', 'applied', 'conflict'])
        ->and($results[0]['data'])->toMatchArray(['id' => $offline, 'status' => 'draft', 'number' => null, 'amount' => '35.0000'])
        ->and($results[1]['data']['allocations'])->toHaveCount(1)
        ->and($results[2]['conflict']['code'])->toBe('DOCUMENT_LOCKED')
        ->and(DB::table('journal_entries')->count())->toBe($journals)
        ->and(DB::table('treasury_movements')->where('source_id', $offline)->exists())->toBeFalse();

    // Incremental pull: the two written drafts, as pushed.
    SyncKit::waitVisible();
    $next = tprPull($this, $token, $full['next_cursor']);
    $changed = array_column($next['rows'], null, 'id');
    expect(array_keys($changed))->toEqualCanonicalizing([$offline, $draft['id']])
        ->and($changed[$offline])->toBe($results[0]['data'])
        ->and($changed[$draft['id']])->toBe($results[1]['data']);

    // Delete of the offline draft: a tombstone in the next pull.
    $deleted = tprPush($this, $token, [tprMutation('delete', $offline, [], $results[0]['version'])]);
    expect($deleted[0]['status'])->toBe('applied');
    SyncKit::waitVisible();
    $gone = tprPull($this, $token, $next['next_cursor']);
    expect($gone['rows'])->toBe([])
        ->and(array_column($gone['tombstones'], 'id'))->toBe([$offline]);
});
