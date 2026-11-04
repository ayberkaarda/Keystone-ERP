import manifestJson from '../../../../contracts/offline-manifest.json?raw'

// Every server-only action of the offline manifest is marked at its buttons in feature code, so
// a runtime with an offline mode can disable them. The table below is the expected call-site map
// with the number of wraps per file; a new manifest action, a removed wrapper (even one of several
// in the same file) or a wrapper in an unlisted file fails this test.

interface OnlineOnlyEntry {
  action: string
  i18n_key: string
}

const manifest = JSON.parse(manifestJson) as { online_only: OnlineOnlyEntry[] }
const actions = manifest.online_only.map((entry) => entry.action)

// Negated glob patterns are not applied to raw imports here, so tests are filtered out by name.
const sources = Object.fromEntries(
  Object.entries(
    import.meta.glob<string>(['/src/features/**/*.ts', '/src/features/**/*.tsx'], {
      query: '?raw',
      import: 'default',
      eager: true,
    }),
  ).filter(([file]) => !/\.test\.tsx?$|\/test\/|\.d\.ts$/.test(file)),
)

/** Action -> feature file -> number of wrapped buttons (or hook calls) with that action. */
const CALL_SITES: Record<string, Record<string, number>> = {
  finalizeSalesQuote: { '/src/features/sales/quotes/components/QuoteActions.tsx': 2 },
  confirmSalesOrder: { '/src/features/sales/orders/components/OrderActions.tsx': 6 },
  postSalesDelivery: { '/src/features/sales/deliveries/components/DeliveryView.tsx': 2 },
  issueSalesInvoice: { '/src/features/sales/invoices/components/InvoiceView.tsx': 1 },
  processSalesReturn: { '/src/features/sales/returns/components/ReturnView.tsx': 2 },
  submitPurchaseRequest: { '/src/features/purchasing/requests/components/RequestActions.tsx': 4 },
  processPurchaseRfq: {
    '/src/features/purchasing/rfqs/components/RfqActions.tsx': 3,
    '/src/features/purchasing/rfqs/pages/RfqPage.tsx': 1,
  },
  approvePurchaseOrder: { '/src/features/purchasing/orders/components/OrderActions.tsx': 7 },
  postPurchaseReceipt: { '/src/features/purchasing/receipts/components/ReceiptView.tsx': 2 },
  bookPurchaseInvoice: { '/src/features/purchasing/invoices/components/InvoiceView.tsx': 2 },
  processPurchaseReturn: { '/src/features/purchasing/returns/components/ReturnView.tsx': 1 },
  postStockDocument: {
    '/src/features/inventory/documents/components/DocumentView.tsx': 1,
    '/src/features/inventory/reservations/pages/ReservationListPage.tsx': 2,
  },
  treasuryPayment: { '/src/features/treasury/shared/RecordPage.tsx': 1 },
  sendEDocument: { '/src/features/edocuments/DocumentPage.tsx': 6 },
  postJournalEntry: { '/src/features/accounting/journal/components/JournalEntryView.tsx': 1 },
  closePeriod: {
    '/src/features/accounting/periods/pages/PeriodsBoardPage.tsx': 1,
    '/src/features/accounting/fiscal/pages/FiscalYearsPage.tsx': 2,
  },
  serverCalculation: {
    '/src/features/catalog/pages/PricingPlaygroundPage.tsx': 1,
    '/src/features/treasury/shared/RecordPage.tsx': 1,
  },
  liveReports: {
    '/src/features/accounting/reports/pages/TrialBalancePage.tsx': 1,
    '/src/features/accounting/reports/pages/GeneralLedgerPage.tsx': 1,
  },
}

/** Manifest actions with no button in the web app yet (and why). */
const NO_WEB_CALL_SITE: Record<string, string> = {
  decideApproval: 'no screen posts /approvals/{id}/decisions; approvals are only displayed',
}

// The action is the first prop, so a plain scan finds it without parsing JSX.
const BUTTON = /<OnlineOnlyButton\b/g
const BUTTON_ACTION = /<OnlineOnlyButton\s+action="([^"]+)"/g
const HOOK_ACTION = /useOnlineOnly\(\s*'([^']+)'\s*\)/g
// Block comments (JSX comments included) and line comments; `://` in URLs is not a comment.
const COMMENT = /\/\*[\s\S]*?\*\/|(?<!:)\/\/[^\n]*/g

function code(source: string): string {
  return source.replace(COMMENT, '')
}

/** Action -> number of wraps in one source file, comments ignored. */
function actionCounts(source: string): Map<string, number> {
  const counts = new Map<string, number>()
  const stripped = code(source)
  for (const match of [...stripped.matchAll(BUTTON_ACTION), ...stripped.matchAll(HOOK_ACTION)]) {
    const action = match[1] ?? ''
    counts.set(action, (counts.get(action) ?? 0) + 1)
  }
  return counts
}

function sitesOf(action: string): Record<string, number> {
  const sites: Record<string, number> = {}
  for (const [file, source] of Object.entries(sources)) {
    const count = actionCounts(source).get(action)
    if (count) sites[file] = count
  }
  return sites
}

describe('online-only call sites', () => {
  it('reads the manifest and the feature sources', () => {
    expect(actions.length).toBeGreaterThan(0)
    expect(Object.keys(sources)).toContain('/src/features/sales/quotes/components/QuoteActions.tsx')
    expect(Object.keys(sources)).toContain('/src/features/sales/shared/lib/keys.ts')
    expect(Object.keys(sources).filter((file) => file.includes('.test.'))).toEqual([])
  })

  it('counts wraps and hook calls per action, ignoring comments', () => {
    expect(
      actionCounts(`
        <OnlineOnlyButton action="a" x={1}>
        <OnlineOnlyButton
          action="a">
        const s = useOnlineOnly('b')
        // <OnlineOnlyButton action="a">
        // useOnlineOnly('b')
        {/* <OnlineOnlyButton action="c"> */}
        /* useOnlineOnly('c')
           <OnlineOnlyButton action="c"> */
        const url = 'https://example.test'; useOnlineOnly('b')
      `),
    ).toEqual(
      new Map([
        ['a', 2],
        ['b', 2],
      ]),
    )
  })

  it('every manifest action has a call site or a stated reason', () => {
    const missing = actions.filter((a) => !(a in CALL_SITES) && !(a in NO_WEB_CALL_SITE))
    expect(missing).toEqual([])
  })

  it('the tables name only manifest actions, each once', () => {
    const listed = [...Object.keys(CALL_SITES), ...Object.keys(NO_WEB_CALL_SITE)]
    expect(listed.filter((a) => !actions.includes(a))).toEqual([])
    expect(new Set(listed).size).toBe(listed.length)
  })

  it.each(Object.entries(CALL_SITES))(
    '%s is wrapped exactly as listed, per file',
    (action, files) => {
      for (const file of Object.keys(files)) expect(Object.keys(sources)).toContain(file)
      expect(sitesOf(action)).toEqual(files)
    },
  )

  it.each(Object.keys(NO_WEB_CALL_SITE))('%s has no call site yet', (action) => {
    expect(sitesOf(action)).toEqual({})
  })

  it('feature code uses only manifest action names', () => {
    const unknown = Object.entries(sources).flatMap(([file, source]) =>
      [...actionCounts(source).keys()]
        .filter((a) => !actions.includes(a))
        .map((a) => `${file}: ${a}`),
    )
    expect(unknown).toEqual([])
  })

  it('every OnlineOnlyButton names its action as the first prop', () => {
    const loose = Object.entries(sources)
      .map(([file, source]) => [file, code(source)] as const)
      .filter(
        ([, source]) =>
          (source.match(BUTTON) ?? []).length !== [...source.matchAll(BUTTON_ACTION)].length,
      )
      .map(([file]) => file)
    expect(loose).toEqual([])
  })

  it('every manifest action keeps its desktop translation key', () => {
    for (const entry of manifest.online_only)
      expect(entry.i18n_key).toBe(`desktop.onlineOnly.${entry.action}`)
  })
})
