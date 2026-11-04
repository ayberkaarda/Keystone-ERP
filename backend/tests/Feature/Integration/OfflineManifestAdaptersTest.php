<?php

declare(strict_types=1);

use App\Core\Sync\Contracts\SyncResource;
use App\Core\Sync\Contracts\SyncWriter;
use App\Modules\Inventory\Enums\DocumentStatus as StockDocumentStatus;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Purchasing\Enums\RequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Sales\Enums\OrderStatus;
use App\Modules\Sales\Enums\QuoteStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuote;
use App\Modules\Treasury\Enums\DocumentStatus as TreasuryDocumentStatus;
use App\Modules\Treasury\Models\TreasuryPayment;
use Illuminate\Database\Eloquent\Model;

/**
 * The offline manifest as shipped in contracts/.
 *
 * @return array<string, mixed>
 */
function offlineManifest(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 4).'/contracts/offline-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Pull resources of the manifest (children included): name => [module, scope, api_resource].
 *
 * @return array<string, array{module: string, scope: string, api_resource: string}>
 */
function offlineManifestPullResources(): array
{
    $out = [];

    foreach (offlineManifest()['resources'] as $resource) {
        if ($resource['source'] !== 'pull') {
            continue;
        }

        $out[$resource['resource']] = ['module' => $resource['module'], 'scope' => $resource['scope'], 'api_resource' => $resource['api_resource']];

        foreach ($resource['children'] ?? [] as $child) {
            $out[$child['resource']] = ['module' => $resource['module'], 'scope' => $resource['scope'], 'api_resource' => $child['api_resource']];
        }
    }

    return $out;
}

/** @return array<string, SyncResource> */
function taggedSyncResources(): array
{
    $out = [];

    foreach (app()->tagged(SyncResource::TAG) as $adapter) {
        expect($out)->not->toHaveKey($adapter->name());
        $out[$adapter->name()] = $adapter;
    }

    return $out;
}

/** @return array<string, SyncWriter> */
function taggedSyncWriters(): array
{
    $out = [];

    foreach (app()->tagged(SyncWriter::TAG) as $writer) {
        expect($out)->not->toHaveKey($writer->resource());
        $out[$writer->resource()] = $writer;
    }

    return $out;
}

it('has a registered read adapter for every pull resource of the manifest and no other', function (): void {
    $manifest = offlineManifestPullResources();
    $adapters = taggedSyncResources();

    expect(array_keys($adapters))->toEqualCanonicalizing(array_keys($manifest));

    foreach ($adapters as $name => $adapter) {
        expect($adapter::class)->toStartWith('App\\Modules\\'.$manifest[$name]['module'].'\\')
            ->and($adapter->scope()->value)->toBe($manifest[$name]['scope'], $name)
            ->and($adapter->resourceClass())->toBe($manifest[$name]['api_resource'], $name);
    }
});

it('serves snapshot resources without a pull adapter', function (): void {
    $snapshots = array_column(array_filter(offlineManifest()['resources'], static fn (array $r): bool => $r['source'] === 'snapshot'), 'resource');

    expect($snapshots)->not->toBeEmpty()
        ->and(array_intersect($snapshots, array_keys(taggedSyncResources())))->toBe([]);
});

it('has exactly one writer for every resource with a write definition and no other', function (): void {
    $resources = offlineManifest()['resources'];
    $writable = array_column(array_filter($resources, static fn (array $r): bool => isset($r['write'])), 'module', 'resource');
    $writers = taggedSyncWriters();

    expect(array_keys($writers))->toEqualCanonicalizing(array_keys($writable));

    foreach ($writers as $name => $writer) {
        expect($writer::class)->toStartWith('App\\Modules\\'.$writable[$name].'\\');
    }
});

it('locks exactly the statuses the manifest draft_only leaves editable', function (string $resource, string $model, string $enum): void {
    $entry = collect(offlineManifest()['resources'])->firstWhere('resource', $resource);
    $draftOnly = $entry['write']['draft_only'];
    $editable = [$draftOnly['value'], ...($draftOnly['also'] ?? [])];
    $writer = taggedSyncWriters()[$resource];

    expect($draftOnly['field'])->toBe('status');

    foreach ($enum::cases() as $case) {
        /** @var Model $target */
        $target = new $model;
        $target->setAttribute('status', $case);

        expect($writer->isDraft($target))->toBe(in_array($case->value, $editable, true), "{$resource} {$case->value}");
    }
})->with([
    'sales quotes' => ['sales-quotes', SalesQuote::class, QuoteStatus::class],
    'sales orders' => ['sales-orders', SalesOrder::class, OrderStatus::class],
    'purchase requests' => ['purchase-requests', PurchaseRequest::class, RequestStatus::class],
    'stock documents' => ['stock-documents', StockDocument::class, StockDocumentStatus::class],
    'treasury payments' => ['treasury-payments', TreasuryPayment::class, TreasuryDocumentStatus::class],
]);

it('declares draft_only for every writable resource except the create-only parties', function (): void {
    foreach (offlineManifest()['resources'] as $resource) {
        if (! isset($resource['write'])) {
            continue;
        }

        $ops = array_column($resource['write']['ops'], 'op');

        expect(isset($resource['write']['draft_only']))->toBe($ops !== ['create'], $resource['resource']);
    }
});
