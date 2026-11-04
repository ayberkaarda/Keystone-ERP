import type { QueryClient, QueryKey } from '@tanstack/react-query'

/** Query key prefixes holding the data of one offline-manifest resource in one company. */
export type ResourceKeyRoots = (company: string) => readonly QueryKey[]

const catalog = (c: string, ...rest: string[]) => ['catalog', c, ...rest]
const reference = (name: string) => ['reference', name]
const sales = (c: string, ...rest: string[]) => ['sales', c, ...rest]
const purchasing = (c: string, branch: string) => ['purchasing', c, branch]
const invDocs = (c: string, ...rest: string[]) => ['inventory', c, 'docs', ...rest]

/**
 * Offline-manifest resource (contracts/offline-manifest.json) -> the query key prefixes the
 * feature code caches its rows under: the owning screens' branch and the lookup caches other
 * features fill from the same endpoint. Reference keys carry no company, the others do. Child
 * resources (`items.variants`) resolve to their parent. A test keeps this table in step with the
 * manifest and with the features' key factories.
 */
export const RESOURCE_KEY_ROOTS: Readonly<Record<string, ResourceKeyRoots>> = {
  parties: (c) => [['parties', c], catalog(c, 'lookups', 'party')],
  items: (c) => [
    catalog(c, 'items'),
    invDocs(c, 'lookups', 'item'),
    invDocs(c, 'lookups', 'items'),
    invDocs(c, 'lookups', 'item-units'),
    sales(c, 'lookups', 'item-units'),
  ],
  'item-categories': (c) => [
    catalog(c, 'categories'),
    invDocs(c, 'lookups', 'categories'),
    sales(c, 'reports', 'lookups', 'categories'),
  ],
  units: (c) => [
    catalog(c, 'units'),
    invDocs(c, 'lookups', 'units'),
    invDocs(c, 'lookups', 'item-units'),
    sales(c, 'lookups', 'units'),
    sales(c, 'lookups', 'item-units'),
  ],
  'unit-conversions': (c) => [catalog(c, 'unit-conversions')],
  'price-lists': (c) => [catalog(c, 'price-lists')],
  currencies: (c) => [reference('currencies'), catalog(c, 'lookups', 'currencies')],
  'exchange-rates': () => [reference('exchange-rates')],
  'tax-codes': (c) => [reference('tax-codes'), catalog(c, 'lookups', 'tax-code')],
  'payment-terms': () => [reference('payment-terms')],
  warehouses: (c) => [
    reference('warehouses'),
    reference('locations'),
    sales(c, 'reports', 'lookups', 'warehouses'),
  ],
  'cost-centers': () => [reference('cost-centers')],
  projects: () => [reference('projects')],
  'sales-quotes': (c) => [sales(c, 'quotes')],
  'sales-orders': (c) => [sales(c, 'orders')],
  'sales-deliveries': (c) => [sales(c, 'deliveries')],
  'sales-invoices': (c) => [sales(c, 'invoices')],
  'sales-returns': (c) => [sales(c, 'returns')],
  'purchase-requests': (c) => [purchasing(c, 'requests')],
  'purchase-rfqs': (c) => [purchasing(c, 'rfqs')],
  'purchase-orders': (c) => [purchasing(c, 'orders')],
  'purchase-receipts': (c) => [purchasing(c, 'receipts')],
  'purchase-invoices': (c) => [purchasing(c, 'invoices')],
  'purchase-returns': (c) => [purchasing(c, 'returns')],
  'stock-documents': (c) => [invDocs(c, 'documents')],
  'stock-balances': (c) => [
    ['inventory', c, 'stock-views', 'balances'],
    sales(c, 'deliveries', 'lots'),
  ],
  'treasury-payments': (c) => [
    ['treasury', c, 'payments'],
    ['treasury', c, 'payment-preview'],
  ],
  'treasury-reports.receivables-ageing': (c) => [['treasury', c, 'reports', 'receivables-ageing']],
  'treasury-reports.payables-ageing': (c) => [['treasury', c, 'reports', 'payables-ageing']],
}

function rootsOf(resource: string): ResourceKeyRoots | undefined {
  return Object.hasOwn(RESOURCE_KEY_ROOTS, resource) ? RESOURCE_KEY_ROOTS[resource] : undefined
}

/**
 * Query key prefixes of a manifest resource in `company`, or null when the resource is not
 * mapped (the caller then refreshes everything). An unknown dotted name resolves to its parent.
 */
export function resourceKeyRoots(resource: string, company: string): readonly QueryKey[] | null {
  const own = rootsOf(resource)
  if (own) return own(company)
  const dot = resource.lastIndexOf('.')
  const parent = dot > 0 ? rootsOf(resource.slice(0, dot)) : undefined
  return parent ? parent(company) : null
}

/**
 * Invalidates the queries of changed manifest resources in `company`. One unmapped resource
 * invalidates every query, which is always correct, only slower.
 */
export async function invalidateResources(
  client: QueryClient,
  resources: readonly string[],
  company: string,
): Promise<void> {
  const roots: QueryKey[] = []
  for (const resource of resources) {
    const mapped = resourceKeyRoots(resource, company)
    if (!mapped) {
      await client.invalidateQueries()
      return
    }
    roots.push(...mapped)
  }
  await Promise.all(roots.map((queryKey) => client.invalidateQueries({ queryKey })))
}
