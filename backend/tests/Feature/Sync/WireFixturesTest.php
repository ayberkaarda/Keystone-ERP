<?php

declare(strict_types=1);

use App\Core\Http\ErrorCode;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Parties\Models\Party;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuote;
use App\Modules\Treasury\Models\TreasuryPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Sync\Support\WireSchema;

/*
 * The contract files themselves: every fixture passes its schema and every invalid fixture
 * fails it (opis, draft 2020-12, the same files the CI ajv step and the Rust tests read), and
 * the resource rows inside the fixtures carry exactly the fields the real API Resource renders.
 *
 * SYNC_WRITE_FIXTURES=1 rewrites those rows from the rendered Resource output (timestamps keep
 * their example offsets: the test app runs in UTC).
 */

/**
 * Schema of a fixture file: <schema>.<state>.json.
 */
function schemaFor(string $file): string
{
    $name = basename($file);

    if (str_starts_with($name, 'offline-manifest.')) {
        return 'offline-manifest.schema.json';
    }

    $base = explode('.', $name);
    array_pop($base);
    array_pop($base);

    return 'sync/'.(implode('.', $base) === 'common' ? 'common' : implode('.', $base)).'.schema.json';
}

/**
 * @return array<string, array{0: string}>
 */
function fixtureFiles(string $folder): array
{
    $files = glob(WireSchema::contracts()."/sync/fixtures/{$folder}*.json") ?: [];
    $out = [];

    foreach ($files as $file) {
        $out[basename($file)] = [$file];
    }

    return $out;
}

it('accepts every valid fixture', function (string $file): void {
    WireSchema::assertValid(schemaFor($file), json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR));
})->with(fixtureFiles(''));

it('rejects every invalid fixture', function (string $file): void {
    expect(WireSchema::errors(schemaFor($file), json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR)))->not->toBeNull();
})->with(fixtureFiles('invalid/'));

it('validates the offline manifest against its schema', function (): void {
    WireSchema::assertValid('offline-manifest.schema.json', json_decode((string) file_get_contents(WireSchema::contracts().'/offline-manifest.json'), false, 512, JSON_THROW_ON_ERROR));
});

it('knows every server error code', function (): void {
    foreach (ErrorCode::cases() as $code) {
        expect(WireSchema::errors('sync/error-codes.schema.json', $code->value))->toBeNull();
    }
});

/**
 * Resource rows inside the fixtures: file, JSON path, manifest resource, model class.
 *
 * @return array<string, array{0: string, 1: list<string|int>, 2: string, 3: class-string<Model>}>
 */
function fixtureRows(): array
{
    return [
        'pull parties row' => ['pull.response.page.json', ['data', 'resources', 0, 'rows', 0], 'parties', Party::class],
        'push applied quote' => ['push.response.mixed.json', ['data', 'results', 0, 'data'], 'sales-quotes', SalesQuote::class],
        'push locked order' => ['push.response.mixed.json', ['data', 'results', 1, 'conflict', 'server_data'], 'sales-orders', SalesOrder::class],
        'push counted stock document' => ['push.response.mixed.json', ['data', 'results', 2, 'data'], 'stock-documents', StockDocument::class],
        'push conflicting request' => ['push.response.rejected-and-merged.json', ['data', 'results', 3, 'conflict', 'server_data'], 'purchase-requests', PurchaseRequest::class],
        'push merged order' => ['push.response.rejected-and-merged.json', ['data', 'results', 4, 'data'], 'sales-orders', SalesOrder::class],
        'push applied payment draft' => ['push.response.mixed.json', ['data', 'results', 5, 'data'], 'treasury-payments', TreasuryPayment::class],
        'push locked posted payment' => ['push.response.rejected-and-merged.json', ['data', 'results', 6, 'conflict', 'server_data'], 'treasury-payments', TreasuryPayment::class],
    ];
}

/**
 * Model hydrated from a Resource row the way it comes from the database.
 *
 * @param  class-string<Model>  $class
 * @param  array<string, mixed>  $row
 * @param  list<string>  $embeds
 */
function hydrate(string $class, array $row, array $embeds): Model
{
    $model = new $class;
    $relations = [];

    foreach ($embeds as $embed) {
        if (array_key_exists($embed, $row)) {
            $relations[$embed] = is_array($row[$embed]) ? $row[$embed] : [];
            unset($row[$embed]);
        }
    }

    $model = $model->newFromBuilder(rawAttributes($model, $row));

    foreach ($relations as $embed => $items) {
        $relation = Str::camel($embed);
        $related = $model->{$relation}()->getRelated();
        $model->setRelation($relation, $related->newCollection(array_map(
            static fn (array $item): Model => $related->newFromBuilder(rawAttributes($related, $item)),
            array_values(array_filter($items, 'is_array')),
        )));
    }

    return $model;
}

/**
 * @param  array<string, mixed>  $row
 * @return array<string, mixed>
 */
function rawAttributes(Model $model, array $row): array
{
    $out = [];

    foreach ($row as $key => $value) {
        $out[$key] = match (true) {
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) === 1 => Carbon::parse($value)->format($model->getDateFormat()),
            default => $value,
        };
    }

    return $out;
}

/**
 * Field names of a row and of its embedded rows (keys of the first item of each list).
 *
 * @param  array<string, mixed>  $row
 * @return array<string, list<string>>
 */
function fieldSets(array $row): array
{
    $sets = ['' => array_keys($row)];

    foreach ($row as $key => $value) {
        if (is_array($value) && array_is_list($value) && isset($value[0]) && is_array($value[0])) {
            $sets[$key] = array_keys($value[0]);
        }
    }

    foreach ($sets as &$set) {
        sort($set);
    }

    return $sets;
}

/**
 * Rendered values over the example, except timestamps (*_at), which keep the example offset.
 */
function mergeRendered(mixed $example, mixed $rendered, string $key = ''): mixed
{
    if (is_array($example) && is_array($rendered)) {
        foreach ($rendered as $k => $value) {
            $example[$k] = array_key_exists($k, $example) ? mergeRendered($example[$k], $value, (string) $k) : $value;
        }

        return $example;
    }

    return str_ends_with($key, '_at') ? $example : $rendered;
}

it('carries exactly the fields of the real API Resource', function (string $file, array $path, string $resource, string $model): void {
    $manifest = json_decode((string) file_get_contents(WireSchema::contracts().'/offline-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $entry = collect($manifest['resources'])->firstWhere('resource', $resource);
    $fixturePath = WireSchema::contracts()."/sync/fixtures/{$file}";
    $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

    $row = $fixture;
    foreach ($path as $segment) {
        $row = $row[$segment];
    }

    /** @var class-string<JsonResource> $resourceClass */
    $resourceClass = $entry['api_resource'];
    $rendered = json_decode(json_encode((new $resourceClass(hydrate($model, $row, $entry['embeds'] ?? [])))->resolve(new Request), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    if (getenv('SYNC_WRITE_FIXTURES') === '1') {
        $target = &$fixture;
        foreach ($path as $segment) {
            $target = &$target[$segment];
        }
        $target = mergeRendered($target, $rendered);
        unset($target);
        $json = (string) json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($fixturePath, preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)), $json)."\n");
    }

    expect(fieldSets($row))->toBe(fieldSets($rendered));
})->with(fixtureRows());
