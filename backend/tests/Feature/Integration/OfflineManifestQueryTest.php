<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Reference\Models\CostCenter;
use App\Modules\Reference\Models\ExchangeRate;
use App\Modules\Reference\Models\PaymentTerm;
use App\Modules\Reference\Models\Project;
use App\Modules\Reference\Models\TaxCode;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Actors;
use Tests\Support\Catalog\Catalog;
use Tests\Support\Inventory\Inv;
use Tests\Support\PlannerStatistics;
use Tests\Support\Purchasing\PurchKit;
use Tests\Support\Sales\SalesKit;
use Tests\Support\Treasury\TreasuryRows;

/*
 * The `query` sections of contracts/offline-manifest.json: the sort and filter fields the desktop
 * applies to its local copy when it answers a list request offline.
 *
 * - Every declared name is one the server accepts on that list endpoint (the same request
 *   online gives 200, an unknown name gives 422).
 * - Applying the declaration to the rows the way the desktop does (oqLocal*) gives the same rows
 *   in the same order as the server.
 * - The list requests the web frontend sends (tests/Fixtures/Sync/web-list-queries.json) are
 *   covered: each sort and filter is declared, or the request is marked online_only and names a
 *   declared online_only parameter. A request that searches (`q`) a resource whose search has
 *   server_also, with everything else declared, is local_partial: offline it is answered from the
 *   local copy and marked meta.offline.search_partial (docs/SYNC-PROTOCOL.md).
 */
uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function oqManifest(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 4).'/contracts/offline-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Every list stream of the manifest, children flattened: name => entry (path, source, query).
 *
 * @return array<string, array<string, mixed>>
 */
function oqResources(): array
{
    $out = [];

    foreach (oqManifest()['resources'] as $resource) {
        $out[$resource['resource']] = $resource;

        foreach ($resource['children'] ?? [] as $child) {
            $out[$child['resource']] = [...$child, 'source' => 'pull', 'parent' => $resource['resource']];
        }
    }

    return $out;
}

/** @return list<array{resource: string, query: string, expect: string, source: string, evidence?: string}> */
function oqKnownQueries(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Sync/web-list-queries.json'), true, flags: JSON_THROW_ON_ERROR)['queries'];
}

/**
 * `a=1&filter[x]=b` as the pairs a browser sends (brackets kept literal).
 *
 * @return list<array{0: string, 1: string}>
 */
function oqPairs(string $query, string $uuid = '00000000-0000-7000-8000-000000000000'): array
{
    $pairs = [];

    foreach (explode('&', $query) as $part) {
        if ($part === '') {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
        $pairs[] = [$name, str_replace('@uuid', $uuid, $value)];
    }

    return $pairs;
}

/**
 * The names in a request the manifest has to know: sort fields and filters.
 *
 * @param  list<array{0: string, 1: string}>  $pairs
 * @return list<string> 'sort=<field>' and 'filter[<name>]'
 */
function oqQueryNames(array $pairs): array
{
    $names = [];

    foreach ($pairs as [$name, $value]) {
        if ($name === 'sort') {
            foreach (array_filter(explode(',', $value)) as $part) {
                $names[] = 'sort='.ltrim($part, '-');
            }
        } elseif (str_starts_with($name, 'filter[')) {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * How the desktop treats one request name: 'local', 'online_only' or 'unsupported'.
 *
 * @param  array<string, mixed>  $query
 */
function oqCoverage(array $query, string $name): string
{
    if (in_array($name, array_column($query['online_only'] ?? [], 'param'), true)) {
        return 'online_only';
    }

    if (str_starts_with($name, 'sort=')) {
        return isset($query['sort'][substr($name, 5)]) ? 'local' : 'unsupported';
    }

    return isset($query['filters'][substr($name, 7, -1)]) ? 'local' : 'unsupported';
}

/**
 * How the desktop treats a non-empty `q`: 'local_partial' when the server also searches what is
 * not in the row (search.server_also), so the local answer may miss rows and is marked as partial;
 * otherwise 'local'. An empty or missing `q` is no search (null).
 *
 * @param  array<string, mixed>  $query
 * @param  list<array{0: string, 1: string}>  $pairs
 */
function oqSearchCoverage(array $query, array $pairs): ?string
{
    foreach ($pairs as [$name, $value]) {
        if ($name === 'q' && $value !== '') {
            return isset($query['search']['server_also']) ? 'local_partial' : 'local';
        }
    }

    return null;
}

/**
 * A user with every view permission the manifest names, in a sales world with more master data
 * and documents so that every filter and sort has rows to tell apart.
 *
 * Purchasing documents live in a second company bootstrapped with the purchasing chart.
 *
 * @return object{sales: object, purchasing: object}
 */
function oqWorld(): object
{
    $permissions = [];

    foreach (oqManifest()['resources'] as $resource) {
        array_push($permissions, ...($resource['requires']['all_of'] ?? []), ...($resource['requires']['any_of'] ?? []));
    }

    $p = PurchKit::world();
    $p->user = Actors::user(array_values(array_unique([...$permissions, ...PurchKit::ALL])));
    PlannerStatistics::refresh(PlannerStatistics::PERMISSION_TABLES);
    PurchKit::post($p->user, $p->company, 'purchase-requests', [
        'document_date' => '2027-06-02', 'notes' => 'Talep bir',
        'lines' => [['item_id' => $p->item->id, 'quantity' => '1', 'estimated_price' => '9']],
    ])->assertCreated();
    PurchKit::post($p->user, $p->company, 'purchase-requests', [
        'document_date' => '2027-06-09', 'needed_by' => '2027-06-30',
        'lines' => [['item_id' => $p->service->id, 'quantity' => '4', 'estimated_price' => '25']],
    ])->assertCreated();
    PurchKit::post($p->user, $p->company, 'purchase-rfqs', [
        'document_date' => '2027-06-03', 'supplier_ids' => [$p->party->id], 'lines' => [['item_id' => $p->item->id, 'quantity' => '1']],
    ])->assertCreated();
    PurchKit::post($p->user, $p->company, 'purchase-rfqs', [
        'document_date' => '2027-06-08', 'response_due' => '2027-06-20', 'supplier_ids' => [$p->party->id], 'lines' => [['item_id' => $p->service->id, 'quantity' => '2']],
    ])->assertCreated();
    $order = PurchKit::approvedOrder($p, [[$p->item->id, '3', '100']]);
    PurchKit::post($p->user, $p->company, 'purchase-orders', [
        'party_id' => PurchKit::supplier($p->company, ['name' => 'Supplier B'])->id, 'document_date' => '2027-06-11',
        'lines' => [['item_id' => $p->service->id, 'quantity' => '1', 'unit_price' => '80']],
    ])->assertCreated();
    $receipt = PurchKit::receive($p, $order['id'], [$order['lines'][0]['id'] => '2']);
    $invoice = PurchKit::invoiceReceipt($p, $receipt['id'], 'SUP-QUERY-1');
    PurchKit::post($p->user, $p->company, "purchase-invoices/{$invoice['id']}/returns", [
        'lines' => [['invoice_line_id' => $invoice['lines'][0]['id'], 'quantity' => '1']],
    ])->assertCreated();
    PurchKit::receive($p, $order['id'], [$order['lines'][0]['id'] => '1'], '2027-06-14');
    TreasuryRows::seed($p, [
        ['payment', '2027-06-03', '9.5', 'Çay masrafı', false], ['receipt', '2027-06-04', '1000.25', null, true],
        ['payment', '2027-06-05', '100', 'İzmir kargo', false], ['receipt', '2027-06-05', '0.5', 'ırmak', true],
    ]);

    $w = SalesKit::world();
    $company = $w->company;
    $user = Actors::user(array_values(array_unique([...$permissions, ...SalesKit::ALL])));
    $parent = Catalog::category($company, 'CAT-B');
    Catalog::category($company, 'CAT-A', $parent);
    SalesKit::in($company, fn () => TaxCode::factory()->create(['company_id' => $company, 'type' => 'withholding', 'code' => 'WH-1', 'rate' => null, 'numerator' => 5, 'denominator' => 10, 'effective_from' => '2023-07-10', 'is_active' => false]));

    SalesKit::party($company, ['name' => 'Bravo Tedarik', 'code' => 'P-BRAVO', 'roles' => ['supplier'], 'country' => 'DE', 'is_active' => false]);
    SalesKit::party($company, ['name' => 'Charlie Ortak', 'code' => 'P-CHARLIE', 'roles' => ['customer', 'supplier'], 'country' => 'TR']);
    $lot = SalesKit::item($company, ['code' => 'BOLT', 'name' => 'Bolt', 'tracking' => 'lot', 'is_active' => false], $w->unit);
    SalesKit::unit($company, 'BOX');
    $list = SalesKit::priceList($company, ['code' => 'PL-B', 'name' => 'Bayi', 'tax_inclusive' => true]);
    Catalog::line($company, $list, $w->item, $w->unit, '120');
    SalesKit::priceList($company, ['code' => 'PL-A', 'name' => 'Alpha', 'is_active' => false]);
    $second = SalesKit::warehouse($company, 'AUX', false);
    Inv::location($company, $w->wh, 'B-01');
    Inv::location($company, $w->wh, 'A-01');

    SalesKit::in($company, function () use ($company): void {
        PaymentTerm::factory()->create(['company_id' => $company, 'code' => 'NET30', 'name' => 'Net 30', 'is_active' => true]);
        PaymentTerm::factory()->create(['company_id' => $company, 'code' => 'CASH', 'name' => 'Cash', 'is_active' => false]);
        Project::factory()->create(['company_id' => $company, 'code' => 'PRJ-B', 'name' => 'Beta', 'status' => 'active']);
        Project::factory()->create(['company_id' => $company, 'code' => 'PRJ-A', 'name' => 'Alpha', 'status' => 'closed']);
        CostCenter::factory()->create(['company_id' => $company, 'code' => 'CC-2', 'name' => 'Satış']);
        CostCenter::factory()->create(['company_id' => $company, 'code' => 'CC-1', 'name' => 'Yönetim', 'is_active' => false]);
        ExchangeRate::factory()->create(['company_id' => $company, 'currency_code' => 'USD', 'rate_date' => '2027-06-10', 'source' => 'manual']);
        ExchangeRate::factory()->create(['company_id' => $company, 'currency_code' => 'EUR', 'rate_date' => '2027-06-11', 'source' => 'manual']);
    });

    Inv::receive($company, $second->id, $w->item->id, '5', '10', [], '2027-06-05');

    foreach ([['2027-06-01', '3', 'Q1 ilk'], ['2027-06-12', '0.5', 'Q2 ikinci'], ['2027-06-12', '7', null]] as [$date, $qty, $notes]) {
        SalesKit::post($user, $company, 'sales-quotes', [
            'party_id' => $w->party->id, 'document_date' => $date, 'notes' => $notes,
            'lines' => [['item_id' => $w->item->id, 'quantity' => $qty, 'unit_price' => '100']],
        ])->assertCreated();
    }

    $order = SalesKit::confirmedOrder((object) [...(array) $w, 'user' => $user], [[$w->item->id, '2', '100'], [$w->service->id, '1', '40']]);
    SalesKit::post($user, $company, 'sales-orders', [
        'party_id' => $w->party->id, 'document_date' => '2027-06-14',
        'lines' => [['item_id' => $w->service->id, 'quantity' => '1', 'unit_price' => '80']],
    ])->assertCreated();
    $delivery = SalesKit::deliver((object) [...(array) $w, 'user' => $user], $order['id'], [$order['lines'][0]['id'] => '2']);
    $invoice = SalesKit::invoiceDelivery((object) [...(array) $w, 'user' => $user], $delivery['id']);
    SalesKit::post($user, $company, "sales-invoices/{$invoice['id']}/returns", ['lines' => [['invoice_line_id' => $invoice['lines'][0]['id'], 'quantity' => '1']]])->assertCreated();

    PlannerStatistics::refresh();

    return (object) [
        'sales' => (object) [
            'company' => $company,
            'user' => $user,
            'ids' => ['{item}' => $w->item->id, '{priceList}' => $list->id, '{warehouse}' => $w->wh->id],
        ],
        'purchasing' => (object) ['company' => $p->company, 'user' => $p->user, 'ids' => []],
    ];
}

/** The company whose rows a resource is checked against (purchasing and treasury: the purchasing chart). */
function oqCtx(object $world, string $resource): object
{
    return str_starts_with($resource, 'purchase-') || str_starts_with($resource, 'treasury-') ? $world->purchasing : $world->sales;
}

function oqPath(object $world, string $path): string
{
    return strtr(substr($path, strlen('/api/v1/')), $world->ids);
}

/**
 * @param  list<array{0: string, 1: string}>  $pairs
 * @return array{status: int, ids: list<string>, rows: list<array<string, mixed>>}
 */
function oqGet(object $world, string $path, array $pairs): array
{
    $query = implode('&', array_map(static fn (array $p): string => $p[0].'='.rawurlencode($p[1]), $pairs));
    $response = SalesKit::get($world->user, $world->company, oqPath($world, $path).($query === '' ? '' : '?'.$query));
    $rows = $response->status() === 200 ? $response->json('data') : [];

    return ['status' => $response->status(), 'ids' => array_column($rows, 'id'), 'rows' => $rows];
}

/**
 * Every row of a list in the server's default order, shaped like the local copy: a resource with
 * embeds (document lines) is pulled with them, so its rows are read through the detail endpoint.
 *
 * @param  array<string, mixed>  $resource
 * @return list<array<string, mixed>>
 */
function oqAll(object $world, array $resource): array
{
    $all = oqGet($world, $resource['path'], [['per_page', '100']]);

    expect($all['status'])->toBe(200, $resource['path'])
        ->and(count($all['rows']))->toBeLessThan(100, $resource['path']);

    if (($resource['embeds'] ?? []) === [] || ! isset($resource['detail_path'])) {
        return $all['rows'];
    }

    return array_map(static function (array $row) use ($world, $resource): array {
        $detail = SalesKit::get($world->user, $world->company, oqPath($world, (string) preg_replace('/\{[a-zA-Z]+\}$/', $row['id'], $resource['detail_path'])))->assertOk()->json('data');

        return [...$row, ...array_intersect_key($detail, array_flip($resource['embeds']))];
    }, $all['rows']);
}

/**
 * The desktop rule for one filter (contracts/offline-manifest.schema.json $defs/queryFilter).
 *
 * @param  array<string, mixed>  $row
 * @param  array<string, mixed>  $filter
 */
function oqLocalMatch(array $row, string $name, array $filter, string $value): bool
{
    $field = $filter['field'] ?? $name;
    $actual = $row[$field] ?? null;
    $values = explode(',', $value);
    $same = static function (mixed $actual, string $wanted) use ($filter): bool {
        return match ($filter['type']) {
            'string', 'date' => is_string($actual) && $actual === $wanted,
            'uuid' => is_string($actual) && strtolower($actual) === strtolower($wanted),
            'bool' => is_bool($actual) && in_array(strtolower($wanted), ['1', 'true', '0', 'false'], true)
                && $actual === in_array(strtolower($wanted), ['1', 'true'], true),
            'integer' => is_int($actual) && (string) $actual === $wanted,
        };
    };

    return match ($filter['match']) {
        'eq' => array_any($values, static fn (string $v): bool => $same($actual, $v)),
        'any_of' => is_array($actual) && array_any($actual, static fn (mixed $element): bool => array_any(
            $values,
            static fn (string $v): bool => $same(isset($filter['element_field']) ? (is_array($element) ? ($element[$filter['element_field']] ?? null) : null) : $element, $v),
        )),
        'gte' => is_string($actual) && $actual >= $value,
        'lte' => is_string($actual) && $actual <= $value,
        'contains' => is_string($actual) && str_contains(mb_strtolower($actual), mb_strtolower($value)),
    };
}

/**
 * The desktop rule for sorting: typed comparison, nulls last ascending (first descending), id
 * ascending last.
 *
 * @param  list<array<string, mixed>>  $rows
 * @param  array<string, mixed>  $query
 * @param  list<string>  $sort  '-field' or 'field'
 * @return list<string>
 */
function oqLocalSort(array $rows, array $query, array $sort): array
{
    $collator = new Collator('en_US');
    $compare = static function (mixed $a, mixed $b, string $type) use ($collator): int {
        return match ($type) {
            'text' => $collator->compare((string) $a, (string) $b) ?: 0,
            'decimal' => BigDecimal::of((string) $a)->compareTo(BigDecimal::of((string) $b)),
            'datetime' => (new DateTimeImmutable((string) $a)) <=> (new DateTimeImmutable((string) $b)),
            default => $a <=> $b,
        };
    };

    usort($rows, static function (array $x, array $y) use ($query, $sort, $compare): int {
        foreach ($sort as $part) {
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
                default => $compare($a, $b, $spec['type']),
            };

            if ($order !== 0) {
                return $descending ? -$order : $order;
            }
        }

        return strcmp((string) $x['id'], (string) $y['id']);
    });

    return array_column($rows, 'id');
}

/**
 * Values worth filtering on for a declared filter, taken from the rows.
 *
 * @param  list<array<string, mixed>>  $rows
 * @param  array<string, mixed>  $filter
 * @return list<string>
 */
function oqSamples(array $rows, string $name, array $filter): array
{
    $field = $filter['field'] ?? $name;
    $seen = [];

    foreach ($rows as $row) {
        $value = $row[$field] ?? null;
        $values = match (true) {
            isset($filter['element_field']) && is_array($value) => array_column($value, $filter['element_field']),
            is_array($value) => $value,
            default => [$value],
        };

        foreach ($values as $v) {
            if (is_bool($v)) {
                array_push($seen, $v ? '1' : '0', $v ? 'true' : 'false');
            } elseif (is_string($v) || is_int($v)) {
                $seen[] = match ($filter['match']) {
                    'contains' => mb_substr((string) $v, 1, 3),
                    default => (string) $v,
                };
            }
        }
    }

    $seen = array_values(array_unique(array_filter($seen, static fn (string $v): bool => $v !== '')));

    if ($filter['match'] === 'eq' && $filter['type'] !== 'bool' && count($seen) >= 2 && ($filter['max_values'] ?? 2) >= 2) {
        $seen[] = $seen[0].','.$seen[1];
    }

    return $seen;
}

/** @return list<string> resources with a declared query */
function oqQueried(): array
{
    return array_keys(array_filter(oqResources(), static fn (array $r): bool => isset($r['query'])));
}

it('declares a query for every list stream the web reads with a sort or filter', function (): void {
    $resources = oqResources();
    $needed = [];

    foreach (oqKnownQueries() as $known) {
        expect($resources)->toHaveKey($known['resource']);

        if ($known['expect'] !== 'server_rejects' && oqQueryNames(oqPairs($known['query'])) !== []) {
            $needed[] = $known['resource'];
        }
    }

    foreach (array_unique($needed) as $name) {
        expect(isset($resources[$name]['query']))->toBeTrue("{$name} has no query section");
    }

    foreach (oqQueried() as $name) {
        expect($resources[$name]['source'])->toBe('pull', $name);
    }
});

it('covers every list request the web sends, as the fixture classifies it', function (): void {
    $resources = oqResources();

    foreach (oqKnownQueries() as $known) {
        $query = $resources[$known['resource']]['query'] ?? [];
        $pairs = oqPairs($known['query']);
        $coverage = array_map(static fn (string $name): string => oqCoverage($query, $name), oqQueryNames($pairs));
        $search = oqSearchCoverage($query, $pairs);

        if ($search !== null) {
            $coverage[] = $search;
        }

        $label = "{$known['resource']}?{$known['query']}";

        $kinds = array_values(array_unique($coverage));
        sort($kinds);
        // The kind that names the class, with those of $also the request carries next to it.
        $with = static fn (string $kind, array $also): array => array_values(array_filter(
            ['local', 'local_partial', 'online_only', 'unsupported'],
            static fn (string $k): bool => $k === $kind || (in_array($k, $also, true) && in_array($k, $kinds, true)),
        ));

        expect($kinds)->toBe(match ($known['expect']) {
            'local' => $coverage === [] ? [] : ['local'],
            'local_partial' => $with('local_partial', ['local']),
            'online_only' => $with('online_only', ['local', 'local_partial']),
            'server_rejects' => $with('unsupported', ['local']),
        }, $label);
    }
});

it('keeps the web sources of the known requests in place', function (): void {
    $root = dirname(__DIR__, 4).'/';

    foreach (oqKnownQueries() as $known) {
        expect(is_file($root.$known['source']))->toBeTrue($known['source']);

        if (isset($known['evidence'])) {
            expect((string) file_get_contents($root.$known['source']))->toContain($known['evidence']);
        }
    }
});

it('knows every filter the web names next to a mirrored list path', function (): void {
    $root = dirname(__DIR__, 4).'/frontend/src';
    $paths = [];

    foreach (oqResources() as $name => $resource) {
        if (isset($resource['parent'])) {
            continue;
        }

        $paths[$name] = substr($resource['path'], strlen('/api/v1'));
    }

    $known = [];

    foreach (oqKnownQueries() as $entry) {
        foreach (oqQueryNames(oqPairs($entry['query'])) as $name) {
            $known[$name] = true;
        }
    }

    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Sync/web-list-queries.json'), true, flags: JSON_THROW_ON_ERROR);
    $other = array_map(static fn (array $e): array => [$e['source'], $e['filter']], $fixture['other_endpoints']);

    foreach ($fixture['other_endpoints'] as $entry) {
        expect((string) file_get_contents(dirname($root, 2).'/'.$entry['source']))
            ->toContain("'filter[{$entry['filter']}]'")
            ->toContain("'{$entry['endpoint']}'");
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $missing = [];

    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', (string) $file);

        if (! preg_match('/\.tsx?$/', $path) || preg_match('#\.test\.tsx?$|/test/|/_showcase/#', $path)) {
            continue;
        }

        $source = (string) file_get_contents($path);
        $mirrored = array_filter($paths, static fn (string $p): bool => str_contains($source, "'{$p}'") || str_contains($source, "`{$p}/"));

        if ($mirrored === []) {
            continue;
        }

        preg_match_all("/'filter\\[([a-z_]+)\\]'/", $source, $matches);
        $relative = substr($path, strlen($root) + 1);

        foreach (array_unique($matches[1]) as $filter) {
            if (! isset($known["filter[{$filter}]"]) && ! in_array(['frontend/src/'.$relative, $filter], $other, true)) {
                $missing[] = substr($path, strlen($root) + 1).": filter[{$filter}]";
            }
        }
    }

    expect($missing)->toBe([]);
})->skip(fn (): bool => ! is_dir(dirname(__DIR__, 4).'/frontend/src'), 'frontend sources not present');

it('declares only fields the server accepts on the list endpoint', function (): void {
    $world = oqWorld();
    $resources = oqResources();

    foreach (oqQueried() as $name) {
        $resource = $resources[$name];
        $query = $resource['query'];

        foreach (array_keys($query['sort'] ?? []) as $sort) {
            foreach ([$sort, "-{$sort}"] as $value) {
                expect(oqGet(oqCtx($world, $name), $resource['path'], [['sort', $value]])['status'])->toBe(200, "{$name} sort={$value}");
            }
        }

        $rows = oqAll(oqCtx($world, $name), $resource);

        foreach ($query['filters'] ?? [] as $filter => $spec) {
            $sample = oqSamples($rows, $filter, $spec)[0] ?? match ($spec['type']) {
                'uuid' => (string) Str::uuid7(),
                'bool' => '1',
                'integer' => '1',
                'date' => '2027-06-10',
                'string' => 'x',
            };
            expect(oqGet(oqCtx($world, $name), $resource['path'], [["filter[{$filter}]", $sample]])['status'])->toBe(200, "{$name} filter[{$filter}]");
        }

        foreach ($query['online_only'] ?? [] as $entry) {
            $param = str_starts_with($entry['param'], 'sort=') ? ['sort', substr($entry['param'], 5)] : [$entry['param'], '1'];
            $param[1] = str_contains($param[0], 'supplier') || str_contains($param[0], 'category') ? (string) Str::uuid7() : $param[1];
            expect(oqGet(oqCtx($world, $name), $resource['path'], [$param])['status'])->toBe(200, "{$name} {$entry['param']}");
        }

        foreach (['sort' => 'no_such_field', 'filter[no_such_field]' => 'x'] as $param => $value) {
            expect(oqGet(oqCtx($world, $name), $resource['path'], [[$param, $value]])['status'])->toBe(422, "{$name} {$param} control");
        }
    }
});

it('answers every known web request online as the fixture expects', function (): void {
    $world = oqWorld();
    $resources = oqResources();

    foreach (oqKnownQueries() as $known) {
        $status = oqGet(oqCtx($world, $known['resource']), $resources[$known['resource']]['path'], oqPairs($known['query'], (string) Str::uuid7()))['status'];

        expect($status)->toBe($known['expect'] === 'server_rejects' ? 422 : 200, "{$known['resource']}?{$known['query']}");
    }
});

it('gives the server answer when the declared filters are applied to the rows', function (): void {
    $world = oqWorld();
    $resources = oqResources();
    $checked = 0;

    foreach (oqQueried() as $name) {
        $resource = $resources[$name];
        $rows = oqAll(oqCtx($world, $name), $resource);

        foreach ($resource['query']['filters'] ?? [] as $filter => $spec) {
            $field = $spec['field'] ?? $filter;

            if ($rows !== []) {
                expect(array_key_exists($field, $rows[0]))->toBeTrue("{$name} filter[{$filter}] reads {$field}");
            }

            foreach (oqSamples($rows, $filter, $spec) as $value) {
                $server = oqGet(oqCtx($world, $name), $resource['path'], [['per_page', '100'], ["filter[{$filter}]", $value]]);
                $local = array_column(array_filter($rows, static fn (array $row): bool => oqLocalMatch($row, $filter, $spec, $value)), 'id');

                expect($server['status'])->toBe(200, "{$name} filter[{$filter}]={$value}");
                expect($server['ids'])->toEqualCanonicalizing($local, "{$name} filter[{$filter}]={$value}");
                $checked++;
            }
        }
    }

    expect($checked)->toBeGreaterThan(60);
});

it('gives the server order when the declared sorts are applied to the rows', function (): void {
    $world = oqWorld();
    $resources = oqResources();
    $checked = 0;

    foreach (oqQueried() as $name) {
        $resource = $resources[$name];
        $query = $resource['query'];
        $rows = oqAll(oqCtx($world, $name), $resource);

        if (count($rows) < 2) {
            continue;
        }

        foreach ($query['sort'] ?? [] as $sort => $spec) {
            expect(array_key_exists($spec['field'] ?? $sort, $rows[0]))->toBeTrue("{$name} sort={$sort}");

            foreach ([$sort, "-{$sort}"] as $value) {
                $server = oqGet(oqCtx($world, $name), $resource['path'], [['per_page', '100'], ['sort', $value]]);

                expect($server['ids'])->toBe(oqLocalSort($rows, $query, [$value]), "{$name} sort={$value}");
                $checked++;
            }
        }

        $default = $query['default_sort'] ?? [];

        foreach ($default as $part) {
            expect(isset($query['sort'][ltrim($part, '-')]))->toBeTrue("{$name} default_sort {$part} is a declared sort");
        }

        expect(array_column($rows, 'id'))->toBe(oqLocalSort($rows, $query, $default), "{$name} default order");
    }

    expect($checked)->toBeGreaterThan(40);
});
