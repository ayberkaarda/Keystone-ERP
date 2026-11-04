import { QueryClient, QueryObserver, type QueryKey } from '@tanstack/react-query'
import manifestJson from '../../../../contracts/offline-manifest.json?raw'
import type { ListQueryState } from '@/components/shared/data-table'
import { catalogKeys } from '@/features/catalog/api/keys'
import { invDocsKeys } from '@/features/inventory/documents/api/keys'
import { invKeysFor } from '@/features/inventory/stock/lib/keys'
import { partyKeys } from '@/features/parties/api/keys'
import { purchKeys as purchDocsKeys } from '@/features/purchasing/invoices/kit/keys'
import { purchKeys } from '@/features/purchasing/shared/lib/keys'
import { refKeys } from '@/features/reference/hooks/keys'
import { salesDocsKeys } from '@/features/sales/invoices/kit/keys'
import { salesKeys } from '@/features/sales/shared/lib/keys'
import { treasuryKey } from '@/features/treasury/shared/keys'
import { useCompanyStore } from '@/stores/company'
import { RESOURCE_KEY_ROOTS, invalidateResources, resourceKeyRoots } from './resourceKeys'

interface ManifestResource {
  resource: string
  children?: { resource: string }[]
}

const manifest = JSON.parse(manifestJson) as { resources: ManifestResource[] }
const resources = manifest.resources.map((r) => r.resource)
const children = manifest.resources.flatMap((r) =>
  (r.children ?? []).map((c) => ({ parent: r.resource, child: c.resource })),
)

const C = 'company-1'
const q = { page: 1 } as unknown as ListQueryState

/**
 * Keys the feature code really builds for each resource (company C active). Every root of the
 * mapping must be a prefix of one of them, and each of them must fall under a root.
 */
function realKeys(): Record<string, QueryKey[]> {
  return {
    parties: [
      partyKeys.list(C, { page: 1 }),
      partyKeys.detail(C, 'p1'),
      partyKeys.sub(C, 'p1', 'addresses'),
      catalogKeys.party('p1'),
    ],
    items: [
      catalogKeys.itemList(q),
      catalogKeys.item('i1'),
      catalogKeys.variants('i1'),
      catalogKeys.barcodes('i1'),
      invDocsKeys.item('i1', C),
      invDocsKeys.items(['i1']),
      invDocsKeys.itemUnits('i1'),
      salesKeys.itemUnits('i1'),
    ],
    'item-categories': [
      catalogKeys.categories(),
      invDocsKeys.categories(),
      [...salesDocsKeys.reports(C), 'lookups', 'categories'],
    ],
    units: [
      catalogKeys.unitList(q),
      catalogKeys.unitAll(),
      invDocsKeys.units(C),
      invDocsKeys.itemUnits('i1'),
      salesKeys.units(),
      salesKeys.itemUnits('i1'),
    ],
    'unit-conversions': [catalogKeys.conversions()],
    'price-lists': [
      catalogKeys.priceListList(q),
      catalogKeys.priceList('l1'),
      catalogKeys.priceListLines('l1'),
    ],
    currencies: [
      refKeys.list('currencies', q),
      refKeys.full('currencies'),
      catalogKeys.currencies(),
    ],
    'exchange-rates': [
      refKeys.list('exchange-rates', q),
      refKeys.latestRates(),
      refKeys.rateStatus(),
    ],
    'tax-codes': [
      refKeys.list('tax-codes', q),
      refKeys.full('tax-codes'),
      catalogKeys.taxCode('t1'),
    ],
    'payment-terms': [refKeys.list('payment-terms', q)],
    warehouses: [
      refKeys.list('warehouses', q),
      refKeys.locations('w1'),
      [...salesDocsKeys.reports(C), 'lookups', 'warehouses'],
    ],
    'cost-centers': [refKeys.list('cost-centers', q)],
    projects: [refKeys.list('projects', q)],
    'sales-quotes': [salesKeys.list('quote', q), salesKeys.detail('quote', 'd1', C)],
    'sales-orders': [salesKeys.list('order', q), salesKeys.detail('order', 'd1', C)],
    'sales-deliveries': [
      salesDocsKeys.list('deliveries', q),
      salesDocsKeys.detail('deliveries', 'd1', C),
    ],
    'sales-invoices': [
      salesDocsKeys.list('invoices', q),
      salesDocsKeys.detail('invoices', 'd1', C),
    ],
    'sales-returns': [salesDocsKeys.list('returns', q), salesDocsKeys.detail('returns', 'd1', C)],
    'purchase-requests': [purchKeys.list('request', q), purchKeys.detail('request', 'd1', C)],
    'purchase-rfqs': [purchKeys.list('rfq', q), purchKeys.comparison('d1')],
    'purchase-orders': [purchKeys.list('order', q), purchKeys.detail('order', 'd1', C)],
    'purchase-receipts': [
      purchDocsKeys.list('receipts', q),
      purchDocsKeys.detail('receipts', 'd1', C),
    ],
    'purchase-invoices': [purchDocsKeys.list('invoices', q), purchDocsKeys.match('d1', C)],
    'purchase-returns': [
      purchDocsKeys.list('returns', q),
      purchDocsKeys.detail('returns', 'd1', C),
    ],
    'stock-documents': [invDocsKeys.documentList(q), invDocsKeys.document('d1', C)],
    'stock-balances': [
      invKeysFor(C).balanceList(q),
      invKeysFor(C).itemStock('i1'),
      salesDocsKeys.lots('i1', 'w1'),
    ],
    'treasury-payments': [
      treasuryKey(C, 'payments', 'list', { q: '', page: 1, per_page: 25 }),
      treasuryKey(C, 'payments', 'p1'),
      treasuryKey(C, 'payment-preview', 'p1', 1),
    ],
    'treasury-reports.receivables-ageing': [treasuryKey(C, 'reports', 'receivables-ageing', {}, 1)],
    'treasury-reports.payables-ageing': [treasuryKey(C, 'reports', 'payables-ageing', {}, 1)],
  }
}

function isPrefix(prefix: QueryKey, key: QueryKey): boolean {
  return prefix.length <= key.length && prefix.every((part, i) => Object.is(part, key[i]))
}

beforeEach(() => {
  useCompanyStore.setState({ activeCompanyId: C })
})

afterEach(() => {
  useCompanyStore.setState({ activeCompanyId: null })
})

describe('resource -> query key roots', () => {
  it('maps every manifest resource', () => {
    expect(resources.length).toBeGreaterThan(0)
    expect(resources.filter((r) => (resourceKeyRoots(r, C) ?? []).length === 0)).toEqual([])
  })

  it('names only manifest resources', () => {
    expect(Object.keys(RESOURCE_KEY_ROOTS).filter((r) => !resources.includes(r))).toEqual([])
  })

  it('maps child resources to their parent', () => {
    expect(children.length).toBeGreaterThan(0)
    for (const { parent, child } of children) {
      expect(child.startsWith(`${parent}.`)).toBe(true)
      expect(resourceKeyRoots(child, C)).toEqual(resourceKeyRoots(parent, C))
    }
  })

  it('a dotted top-level resource is not read as a child', () => {
    expect(resourceKeyRoots('treasury-reports.receivables-ageing', C)).toEqual([
      ['treasury', C, 'reports', 'receivables-ageing'],
    ])
  })

  it('returns null for unknown resources', () => {
    expect(resourceKeyRoots('nope', C)).toBeNull()
    expect(resourceKeyRoots('nope.child', C)).toBeNull()
    expect(resourceKeyRoots('treasury-reports', C)).toBeNull()
  })

  it.each(['constructor', 'toString', '__proto__', 'hasOwnProperty', 'constructor.child'])(
    'does not read the prototype name %s as a resource',
    (name) => {
      expect(resourceKeyRoots(name, C)).toBeNull()
    },
  )

  it('puts the company into company-scoped roots', () => {
    expect(resourceKeyRoots('parties', 'other')).toContainEqual(['parties', 'other'])
    expect(resourceKeyRoots('sales-quotes', 'other')).toEqual([['sales', 'other', 'quotes']])
    expect(resourceKeyRoots('stock-documents', 'other')).toEqual([
      ['inventory', 'other', 'docs', 'documents'],
    ])
  })

  it('the parity table covers every manifest resource', () => {
    expect(Object.keys(realKeys()).sort()).toEqual([...resources].sort())
  })

  it.each(resources)('%s roots match the keys the feature code builds', (resource) => {
    const keys = realKeys()[resource] ?? []
    const roots = resourceKeyRoots(resource, C) ?? []
    expect(keys.length).toBeGreaterThan(0)
    for (const root of roots) expect(keys.some((key) => isPrefix(root, key))).toBe(true)
    for (const key of keys) expect(roots.some((root) => isPrefix(root, key))).toBe(true)
  })
})

describe('invalidateResources', () => {
  function seeded() {
    const client = new QueryClient()
    const keys: QueryKey[] = [
      ['parties', C, 'list', {}],
      ['sales', C, 'quotes', 'detail', 'd1'],
      ['sales', C, 'orders', 'detail', 'd1'],
      ['catalog', C, 'items', 'detail', 'i1', 'variants'],
      ['reference', 'locations', 'w1'],
      ['dashboard'],
    ]
    for (const key of keys) client.setQueryData(key, 1)
    const stale = () =>
      keys.filter((key) => client.getQueryState(key)?.isInvalidated).map((key) => key.join('/'))
    return { client, stale }
  }

  it('invalidates only the mapped roots', async () => {
    const { client, stale } = seeded()
    await invalidateResources(client, ['sales-quotes', 'parties'], C)
    expect(stale()).toEqual([`parties/${C}/list/[object Object]`, `sales/${C}/quotes/detail/d1`])
  })

  it('invalidates through the parent for a child resource', async () => {
    const { client, stale } = seeded()
    await invalidateResources(client, ['items.variants', 'warehouses.locations'], C)
    expect(stale()).toEqual([`catalog/${C}/items/detail/i1/variants`, 'reference/locations/w1'])
  })

  it('invalidates everything for an unmapped resource', async () => {
    const { client, stale } = seeded()
    await invalidateResources(client, ['sales-quotes', 'something-new'], C)
    expect(stale()).toHaveLength(6)
  })

  it('does nothing for an empty list', async () => {
    const { client, stale } = seeded()
    await invalidateResources(client, [], C)
    expect(stale()).toEqual([])
  })

  it('treats a repeated resource like a single one', async () => {
    const { client, stale } = seeded()
    await invalidateResources(client, ['sales-quotes', 'sales-quotes'], C)
    expect(stale()).toEqual([`sales/${C}/quotes/detail/d1`])
  })

  it('invalidates everything for a prototype name', async () => {
    const { client, stale } = seeded()
    await invalidateResources(client, ['constructor'], C)
    expect(stale()).toHaveLength(6)
  })

  it('refetches an observed query and resolves only after the refetch', async () => {
    const client = new QueryClient()
    const key = ['sales', C, 'quotes', 'list']
    let release: (() => void) | undefined
    let calls = 0
    const queryFn = vi.fn(() =>
      ++calls === 1
        ? Promise.resolve(1)
        : new Promise<number>((resolve) => {
            release = () => resolve(2)
          }),
    )
    const observer = new QueryObserver(client, { queryKey: key, queryFn, staleTime: Infinity })
    const unsubscribe = observer.subscribe(vi.fn())
    await vi.waitFor(() => expect(client.getQueryData(key)).toBe(1))

    let done = false
    const pending = invalidateResources(client, ['sales-quotes'], C).then(() => {
      done = true
    })
    await vi.waitFor(() => expect(queryFn).toHaveBeenCalledTimes(2))
    await new Promise((resolve) => setTimeout(resolve, 10))
    expect(done).toBe(false)
    release?.()
    await pending
    expect(client.getQueryData(key)).toBe(2)
    unsubscribe()
  })
})
