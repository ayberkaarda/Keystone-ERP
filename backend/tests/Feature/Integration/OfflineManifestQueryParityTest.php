<?php

declare(strict_types=1);

use App\Modules\Reference\Models\ExchangeRate;
use App\Modules\Reference\Models\Location;
use App\Modules\Reference\Models\PaymentTerm;
use App\Modules\Reference\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Actors;
use Tests\Support\Catalog\Catalog;
use Tests\Support\PlannerStatistics;
use Tests\Support\Purchasing\PurchKit;
use Tests\Support\Sales\SalesKit;
use Tests\Support\Treasury\TreasuryRows;

/*
 * The offline-manifest `query` sections against the server with data that tells the rules apart:
 * Turkish letters, mixed case, punctuation, decimals of different lengths, nulls, numbered and
 * unnumbered documents, documents of the same date created at different times. The local rules
 * are the ones docs/SYNC-PROTOCOL.md "Çevrimdışı liste sorguları" gives the desktop; text order is
 * the approximate rule there (NFKD, marks dropped, case folded, only letters and digits, dotless i
 * as its own letter after i; ties lower case first, then the raw string, then id).
 */
uses(RefreshDatabase::class);

const OQP_TEXT = [
    'Çelik', 'celik', 'Cam', 'çay', 'Zeytin', 'zebra', 'Gazi', 'ğ-test', 'Irmak', 'ırmak', 'İstanbul', 'istanbul',
    'Izmir', 'İzmir', 'Öztürk', 'oz', 'Ördek', 'Şeker', 'seker', 'Sabah', 'Ümit', 'umut', 'Ulus', 'a-02', 'A01',
    'a 03', 'A_04', 'ab', 'AB', '10', '9', 'Ab-c', 'abc', 'AB-1', 'A-B2',
];

/** @return array<string, array<string, mixed>> list streams of the manifest, children flattened */
function oqpResources(): array
{
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/contracts/offline-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $out = [];

    foreach ($manifest['resources'] as $resource) {
        $out[$resource['resource']] = $resource;

        foreach ($resource['children'] ?? [] as $child) {
            $out[$child['resource']] = [...$child, 'source' => 'pull', 'parent' => $resource['resource']];
        }
    }

    return $out;
}

/** Primary key of the desktop text rule. */
function oqpTextKey(string $value): string
{
    $value = (string) Normalizer::normalize($value, Normalizer::FORM_KD);
    $value = (string) preg_replace('/\p{M}/u', '', $value);
    $value = mb_strtolower($value);
    $value = (string) preg_replace('/[^\p{L}\p{N}]/u', '', $value);

    return str_replace('ı', "i\u{FFFF}", $value);
}

/** Case tie-break key: lower case sorts first. */
function oqpCaseKey(string $value): string
{
    $value = (string) preg_replace('/[^\p{L}\p{N}]/u', '', $value);

    return implode('', array_map(
        static fn (string $c): string => mb_strtolower($c) === $c ? mb_strtoupper($c) : mb_strtolower($c),
        mb_str_split($value),
    ));
}

function oqpText(string $a, string $b): int
{
    return strcmp(oqpTextKey($a), oqpTextKey($b)) ?: (strcmp(oqpCaseKey($a), oqpCaseKey($b)) ?: strcmp($a, $b));
}

function oqpCompare(mixed $a, mixed $b, string $type): int
{
    return match ($type) {
        'text' => oqpText((string) $a, (string) $b),
        'decimal' => bccomp((string) $a, (string) $b, 10),
        'datetime' => (new DateTimeImmutable((string) $a)) <=> (new DateTimeImmutable((string) $b)),
        'date' => strcmp((string) $a, (string) $b),
        'integer', 'bool' => (int) $a <=> (int) $b,
    };
}

/** The row value has the shape its declared sort type promises (null always allowed). */
function oqpHasShape(mixed $value, string $type): bool
{
    return $value === null || match ($type) {
        'text' => is_string($value),
        'decimal' => is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1,
        'datetime' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) === 1,
        'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
        'integer' => is_int($value),
        'bool' => is_bool($value),
    };
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @param  array<string, mixed>  $query
 * @param  list<string>  $parts
 * @return list<string>
 */
function oqpSort(array $rows, array $query, array $parts): array
{
    usort($rows, static function (array $x, array $y) use ($query, $parts): int {
        foreach ($parts as $part) {
            $descending = str_starts_with($part, '-');
            $name = ltrim($part, '-');
            $spec = $query['sort'][$name];
            $field = $spec['field'] ?? $name;
            $a = $x[$field] ?? null;
            $b = $y[$field] ?? null;
            $order = match (true) {
                $a === null && $b === null => 0,
                $a === null => 1,
                $b === null => -1,
                default => oqpCompare($a, $b, $spec['type']) <=> 0,
            };

            if ($order !== 0) {
                return $descending ? -$order : $order;
            }
        }

        return strcmp((string) $x['id'], (string) $y['id']);
    });

    return array_column($rows, 'id');
}

/** Search fold of the desktop: Turkish-aware lower case, marks dropped, dotless i as i. */
function oqpFold(string $value): string
{
    $value = str_replace(['İ', 'I'], ['i', 'ı'], $value);

    return str_replace('ı', 'i', (string) preg_replace('/\p{M}/u', '', (string) Normalizer::normalize(mb_strtolower($value), Normalizer::FORM_KD)));
}

/**
 * The desktop rule for one filter value; null when the value is refused offline.
 *
 * @param  list<array<string, mixed>>  $rows
 * @param  array<string, mixed>  $spec
 * @return list<string>|null
 */
function oqpFilter(array $rows, string $name, array $spec, string $value): ?array
{
    $values = explode(',', $value);

    if (isset($spec['max_values']) && count($values) > $spec['max_values']) {
        return null;
    }

    if ($spec['type'] === 'bool' && array_any($values, static fn (string $v): bool => ! in_array(strtolower($v), ['1', '0', 'true', 'false'], true))) {
        return null;
    }

    if ($spec['type'] === 'date' && array_any($values, static fn (string $v): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1)) {
        return null;
    }

    $field = $spec['field'] ?? $name;
    $same = static fn (mixed $actual, string $wanted): bool => match ($spec['type']) {
        'string', 'date' => is_string($actual) && $actual === $wanted,
        'uuid' => is_string($actual) && strtolower($actual) === strtolower($wanted),
        'bool' => is_bool($actual) && $actual === in_array(strtolower($wanted), ['1', 'true'], true),
        'integer' => is_int($actual) && (string) $actual === $wanted,
    };
    $ids = [];

    foreach ($rows as $row) {
        $actual = $row[$field] ?? null;
        $hit = match ($spec['match']) {
            'eq' => array_any($values, static fn (string $v): bool => $same($actual, $v)),
            'any_of' => is_array($actual) && array_any($actual, static fn (mixed $element): bool => array_any(
                $values,
                static fn (string $v): bool => $same(isset($spec['element_field']) ? (is_array($element) ? ($element[$spec['element_field']] ?? null) : null) : $element, $v),
            )),
            'gte' => is_string($actual) && $actual >= $value,
            'lte' => is_string($actual) && $actual <= $value,
            'contains' => is_string($actual) && str_contains(mb_strtolower($actual), mb_strtolower($value)),
        };

        if ($hit) {
            $ids[] = $row['id'];
        }
    }

    return $ids;
}

/**
 * @param  list<array{0: string, 1: string}>  $pairs
 * @return array{status: int, ids: list<string>}
 */
function oqpGet(object $ctx, string $path, array $pairs): array
{
    $query = implode('&', array_map(static fn (array $p): string => $p[0].'='.rawurlencode($p[1]), $pairs));
    $response = SalesKit::get($ctx->user, $ctx->company, $path.($query === '' ? '' : '?'.$query));

    return ['status' => $response->status(), 'ids' => $response->status() === 200 ? array_column($response->json('data'), 'id') : []];
}

/** The next record is created a minute later (created_at differs between same-date documents). */
function oqpTick(): void
{
    Carbon::setTestNow(Carbon::now()->addMinute());
}

/**
 * Two companies (purchasing chart and sales chart) with the collation-sensitive data.
 *
 * @return object{sales: object, purchasing: object, warehouse: string, item: string}
 */
function oqpWorld(): object
{
    $permissions = [];

    foreach (oqpResources() as $resource) {
        array_push($permissions, ...($resource['requires']['all_of'] ?? []), ...($resource['requires']['any_of'] ?? []));
    }

    $p = PurchKit::world();
    $p->user = Actors::user(array_values(array_unique([...$permissions, ...PurchKit::ALL])));
    PlannerStatistics::refresh(PlannerStatistics::PERMISSION_TABLES);
    // The planner now counts the user's grants instead of taking the table for one row.
    expect((float) DB::selectOne("select reltuples from pg_class where oid = 'role_has_permissions'::regclass")->reltuples)->toBeGreaterThan(1.0);

    foreach ([
        ['2027-06-02', null, '9.5', 'Çay talebi'], ['2027-06-02', '2027-06-30', '100', 'cam'], ['2027-06-03', '2027-06-04', '1000.25', 'İzmir'],
        ['2027-06-05', null, '0.5', 'ırmak'], ['2027-06-01', '2027-07-01', '240', 'Zeytin'], ['2027-06-04', '2027-06-04', '20', null],
    ] as [$date, $needed, $price, $notes]) {
        oqpTick();
        PurchKit::post($p->user, $p->company, 'purchase-requests', [
            'document_date' => $date, 'needed_by' => $needed, 'notes' => $notes,
            'lines' => [['item_id' => $p->item->id, 'quantity' => '1', 'estimated_price' => $price]],
        ])->assertCreated();
    }

    foreach (['9.5', '100', '96', '288'] as $price) {
        oqpTick();
        PurchKit::post($p->user, $p->company, 'purchase-orders', [
            'party_id' => $p->party->id, 'document_date' => '2027-06-10',
            'lines' => [['item_id' => $p->service->id, 'quantity' => '1', 'unit_price' => $price]],
        ])->assertCreated();
    }

    TreasuryRows::seed($p, [
        ['payment', '2027-06-02', '9.5', 'Çay talebi', false], ['receipt', '2027-06-02', '100', 'cam', true],
        ['payment', '2027-06-03', '1000.25', 'İzmir', false], ['receipt', '2027-06-05', '0.5', 'ırmak', true],
        ['payment', '2027-06-01', '240', 'Zeytin Lq7x', false], ['receipt', '2027-06-04', '20', null, false],
    ], oqpTick(...));

    $w = SalesKit::world();
    $company = $w->company;
    $user = Actors::user(array_values(array_unique([...$permissions, ...SalesKit::ALL])));
    $parties = [];

    foreach (OQP_TEXT as $i => $text) {
        $parties[] = SalesKit::party($company, [
            'name' => $text.' Ltd', 'code' => $text, 'country' => ['TR', 'DE', 'tr', 'US'][$i % 4],
            'tax_number' => $i % 5 === 0 ? null : (string) (1000000000 + $i * 7919),
            'roles' => $i % 3 === 0 ? ['supplier'] : ($i % 3 === 1 ? ['customer'] : ['customer', 'supplier']),
            'is_active' => $i % 4 !== 3, 'legal_name' => $i % 2 === 1 ? "Unvan {$text} Lq{$i}x" : null,
        ]);
    }

    $customers = array_values(array_filter($parties, static fn ($party): bool => in_array('customer', (array) $party->roles, true) && $party->is_active));

    foreach (OQP_TEXT as $i => $text) {
        SalesKit::item($company, ['code' => 'I'.$text, 'name' => $text, 'type' => $i % 2 === 1 ? 'stock' : 'service', 'is_active' => $i % 5 !== 0], $w->unit);
    }

    foreach (array_slice(OQP_TEXT, 0, 16) as $i => $text) {
        SalesKit::in($company, static fn () => Warehouse::factory()->create(['code' => 'W'.$text, 'name' => $text, 'is_active' => $i % 3 !== 0]));
    }

    foreach (OQP_TEXT as $i => $text) {
        SalesKit::in($company, static fn () => Location::factory()->create([
            'warehouse_id' => $w->wh->id, 'code' => $text, 'name' => 'L '.implode('', array_reverse(mb_str_split($text))), 'is_active' => $i % 4 !== 0,
        ]));
    }

    foreach (array_slice(OQP_TEXT, 0, 20) as $i => $text) {
        SalesKit::in($company, static fn () => PaymentTerm::factory()->create(['company_id' => $company, 'code' => 'T'.$text, 'name' => $text, 'is_active' => $i % 3 !== 0]));
    }

    foreach (['USD', 'EUR', 'CHF'] as $code) {
        Catalog::currency($code);
    }

    foreach (array_slice(OQP_TEXT, 0, 12) as $text) {
        Catalog::category($company, 'K'.$text);
    }

    foreach ([['USD', '2027-06-10'], ['EUR', '2027-06-10'], ['CHF', '2027-06-11'], ['USD', '2027-06-08']] as [$currency, $date]) {
        SalesKit::in($company, static fn () => ExchangeRate::factory()->create(['company_id' => $company, 'currency_code' => $currency, 'rate_date' => $date, 'source' => 'manual']));
    }

    $prices = ['9.5', '9.2', '100', '1000.25', '0.5', '240', '96', '288'];

    foreach (array_slice($customers, 0, 12) as $i => $party) {
        foreach (['sales-quotes', 'sales-orders'] as $uri) {
            oqpTick();
            $document = SalesKit::post($user, $company, $uri, [
                'party_id' => $party->id, 'document_date' => '2027-06-0'.(1 + $i % 3),
                'due_date' => $i % 3 === 0 ? null : '2027-07-0'.(1 + $i % 4),
                'notes' => OQP_TEXT[($i + 3) % count(OQP_TEXT)],
                'lines' => [['item_id' => $w->service->id, 'quantity' => '1', 'unit_price' => $prices[$i % count($prices)]]],
            ])->assertCreated()->json('data');

            if ($uri === 'sales-orders' && $i % 2 === 0) {
                SalesKit::post($user, $company, "sales-orders/{$document['id']}/confirm")->assertOk();
            }
        }
    }

    $issued = [];

    foreach (array_slice($customers, 0, 9) as $i => $party) {
        oqpTick();
        $invoice = SalesKit::post($user, $company, 'sales-invoices', [
            'party_id' => $party->id, 'document_date' => '2027-06-1'.($i % 3),
            'notes' => OQP_TEXT[($i + 7) % count(OQP_TEXT)],
            'lines' => [['item_id' => $w->service->id, 'quantity' => '1', 'unit_price' => $prices[($i + 2) % count($prices)]]],
        ])->assertCreated()->json('data');

        if ($i % 3 !== 2) {
            $issued[] = SalesKit::post($user, $company, "sales-invoices/{$invoice['id']}/issue")->assertOk()->json('data');
        }
    }

    foreach (array_slice($issued, 0, 4) as $invoice) {
        oqpTick();
        SalesKit::post($user, $company, "sales-invoices/{$invoice['id']}/returns", ['lines' => [['invoice_line_id' => $invoice['lines'][0]['id'], 'quantity' => '1']]])->assertCreated();
    }

    PlannerStatistics::refresh();

    return (object) [
        'sales' => (object) ['company' => $company, 'user' => $user],
        'purchasing' => (object) ['company' => $p->company, 'user' => $p->user],
        'warehouse' => $w->wh->id,
        'item' => $w->item->id,
    ];
}

/**
 * Every queried stream with its company, list path and rows shaped like the local copy (embeds
 * read through the detail endpoint).
 *
 * @return array<string, array{ctx: object, path: string, query: array<string, mixed>, rows: list<array<string, mixed>>}>
 */
function oqpStreams(object $world): array
{
    $out = [];

    foreach (oqpResources() as $name => $resource) {
        if (! isset($resource['query'])) {
            continue;
        }

        $ctx = str_starts_with($name, 'purchase-') || str_starts_with($name, 'treasury-') ? $world->purchasing : $world->sales;
        $path = str_replace(['{warehouse}', '{item}'], [$world->warehouse, $world->item], substr($resource['path'], strlen('/api/v1/')));
        $rows = SalesKit::get($ctx->user, $ctx->company, $path.'?per_page=100')->assertOk()->json('data');

        if (($resource['embeds'] ?? []) !== [] && isset($resource['detail_path'])) {
            $rows = array_map(static fn (array $row): array => [...$row, ...array_intersect_key(
                SalesKit::get($ctx->user, $ctx->company, $path.'/'.$row['id'])->assertOk()->json('data'),
                array_flip($resource['embeds']),
            )], $rows);
        }

        $out[$name] = ['ctx' => $ctx, 'path' => $path, 'query' => $resource['query'], 'rows' => $rows];
    }

    return $out;
}

it('orders rows as the server does for every declared sort and the default order', function (): void {
    $bad = [];
    $checked = 0;

    foreach (oqpStreams(oqpWorld()) as $name => ['ctx' => $ctx, 'path' => $path, 'query' => $query, 'rows' => $rows]) {
        if ($rows === []) {
            continue;
        }

        foreach ($query['sort'] ?? [] as $sort => $spec) {
            $field = $spec['field'] ?? $sort;

            foreach ($rows as $row) {
                if (! oqpHasShape($row[$field] ?? null, $spec['type'])) {
                    $bad[] = "{$name} sort {$sort}: ".json_encode($row[$field] ?? null)." is not {$spec['type']}";
                    break;
                }
            }

            if (count($rows) < 2) {
                continue;
            }

            foreach ([$sort, "-{$sort}"] as $value) {
                $server = oqpGet($ctx, $path, [['per_page', '100'], ['sort', $value]]);

                if ($server['status'] !== 200 || $server['ids'] !== oqpSort($rows, $query, [$value])) {
                    $bad[] = "{$name} sort={$value} ({$server['status']})";
                }

                $checked++;
            }
        }

        if (count($rows) >= 2 && array_column($rows, 'id') !== oqpSort($rows, $query, $query['default_sort'] ?? [])) {
            $bad[] = "{$name} default order";
        }
    }

    expect($bad)->toBe([])->and($checked)->toBeGreaterThan(100);
});

it('answers equality and substring filters as the server does, whatever the case of the value', function (): void {
    $bad = [];
    $checked = 0;

    foreach (oqpStreams(oqpWorld()) as $name => ['ctx' => $ctx, 'path' => $path, 'query' => $query, 'rows' => $rows]) {
        foreach ($query['filters'] ?? [] as $filter => $spec) {
            $field = $spec['field'] ?? $filter;
            $values = array_filter(array_map(static fn (array $row): mixed => $row[$field] ?? null, $rows), 'is_string');

            if ($spec['match'] === 'eq' && $values !== [] && array_all($values, static fn (string $v): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1)) {
                expect($spec['type'])->toBe('date', "{$name} filter[{$filter}] holds dates");
            }

            if ($spec['type'] !== 'string') {
                continue;
            }

            $samples = [];

            foreach ($values as $value) {
                if (mb_strlen($value) >= 3) {
                    array_push($samples, mb_substr($value, 1, 2), mb_strtoupper(mb_substr($value, 0, 3)), mb_strtolower($value));
                }
            }

            foreach (array_slice(array_values(array_unique($samples)), 0, 8) as $value) {
                $local = oqpFilter($rows, $filter, $spec, $value);
                $server = oqpGet($ctx, $path, [['per_page', '100'], ["filter[{$filter}]", $value]]);

                if ($local !== null && ($server['status'] !== 200 || array_diff($server['ids'], $local) !== [] || array_diff($local, $server['ids']) !== [])) {
                    $bad[] = "{$name} filter[{$filter}]={$value}: server ".count($server['ids']).', local '.count($local);
                }

                $checked++;
            }
        }
    }

    expect($bad)->toBe([])->and($checked)->toBeGreaterThan(40);
});

it('declares max_values as the number of values the server takes', function (): void {
    $world = oqpWorld();
    $bad = [];

    foreach (oqpStreams($world) as $name => ['ctx' => $ctx, 'path' => $path, 'query' => $query]) {
        foreach ($query['filters'] ?? [] as $filter => $spec) {
            if ($spec['type'] !== 'uuid' || ! in_array($spec['match'], ['eq', 'any_of'], true)) {
                continue;
            }

            $limit = $spec['max_values'] ?? 2;
            $ids = array_map(static fn (): string => (string) Str::uuid7(), range(1, $limit + 1));

            if (oqpGet($ctx, $path, [["filter[{$filter}]", implode(',', array_slice($ids, 0, $limit))]])['status'] !== 200) {
                $bad[] = "{$name} filter[{$filter}]: the server refuses {$limit} values";
            }

            if (isset($spec['max_values']) && oqpGet($ctx, $path, [["filter[{$filter}]", implode(',', $ids)]])['status'] === 200) {
                $bad[] = "{$name} filter[{$filter}]: the server takes more than {$limit} values";
            }
        }
    }

    expect($bad)->toBe([]);
});

it('searches only declared row fields, and finds at least what the server finds', function (): void {
    $bad = [];
    $checked = 0;

    foreach (oqpStreams(oqpWorld()) as $name => ['ctx' => $ctx, 'path' => $path, 'query' => $query, 'rows' => $rows]) {
        if (! isset($query['search']) || $rows === []) {
            continue;
        }

        foreach ($query['search']['fields'] as $field) {
            if (! array_key_exists($field, $rows[0])) {
                $bad[] = "{$name} search field {$field} is not in the row";
            }
        }

        $probes = ['i', 'I', 'ş', 'ü', 'ltd', '-', '%', 'İZ', 'CEL'];

        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_string($value) && preg_match('/Lq\d+x/', $value, $match) === 1) {
                    $probes[] = $match[0];
                }
            }
        }

        foreach (array_unique($probes) as $q) {
            $server = oqpGet($ctx, $path, [['per_page', '100'], ['q', $q]]);
            $local = array_column(array_filter($rows, static fn (array $row): bool => array_any(
                $query['search']['fields'],
                static fn (string $field): bool => is_string($row[$field] ?? null) && str_contains(oqpFold($row[$field]), oqpFold($q)),
            )), 'id');

            if ($server['status'] !== 200 || array_diff($server['ids'], $local) !== []) {
                $bad[] = "{$name} q={$q}: the server finds rows the local search misses";
            }

            $checked++;
        }
    }

    expect($bad)->toBe([])->and($checked)->toBeGreaterThan(100);
});

it('refuses an empty sort parameter online, so the desktop refuses it too', function (): void {
    $sales = SalesKit::world();
    $ctx = (object) ['company' => $sales->company, 'user' => Actors::user(array_values(array_unique(array_merge(...array_map(
        static fn (array $r): array => [...$r['requires']['all_of'] ?? [], ...$r['requires']['any_of'] ?? []],
        array_values(oqpResources()),
    )))))];
    PlannerStatistics::refresh();

    foreach (oqpResources() as $name => $resource) {
        if (isset($resource['query'])) {
            $path = str_replace(['{warehouse}', '{item}'], [$sales->wh->id, $sales->item->id], substr($resource['path'], strlen('/api/v1/')));

            expect(oqpGet($ctx, $path, [['sort', '']])['status'])->toBe(422, $name);
        }
    }
});

it('keeps manifest_version in step with the protocol text that describes query', function (): void {
    $root = dirname(__DIR__, 4);
    $version = json_decode((string) file_get_contents($root.'/contracts/offline-manifest.json'), true, flags: JSON_THROW_ON_ERROR)['manifest_version'];
    $doc = (string) file_get_contents($root.'/docs/SYNC-PROTOCOL.md');

    expect(preg_match('/yalnız kaynağın `query` bölümünde bildirilen adları uygular \(`offline-manifest\.json`, `manifest_version` (\d+)\)/u', $doc, $match))->toBe(1)
        ->and($version)->toBe((int) $match[1]);
});
