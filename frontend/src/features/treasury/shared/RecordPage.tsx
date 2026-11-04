import { scalar } from './format'
import { lifecycleActions } from './lifecycle'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { Button } from '@/components/ui/button'
import { OnlineOnlyButton, useOnlineOnly } from '@/lib/online-only'
import { partiesApi } from '@/features/parties'
import { hasPermission, usePermissionContext } from '@/lib/permissions'
import { AccountEditor } from '../accounts/AccountEditor'
import { StatementDetail } from '../banking/StatementDetail'
import { StatementImport } from '../banking/StatementImport'
import { InstrumentEditor } from '../instruments/InstrumentEditor'
import { PaymentEditor } from '../payments/PaymentEditor'
import { PlanEditor } from '../plans/PlanEditor'
import { TransferEditor } from '../transfers/TransferEditor'
import { ActionDialog } from './ActionDialog'
import { treasuryApi } from './api'
import { treasuryKey } from './keys'
import {
  permission,
  type Account,
  type Area,
  type Instrument,
  type Payment,
  type Plan,
  type Statement,
  type Transfer,
  type TreasuryRecord,
} from './types'
import { Amount, BackLink, ErrorNotice, Loading, Panel, Status, TreasuryHeader } from './ui'
import { useRecord, useAccounts, useTreasuryCompany, useTreasuryT } from './hooks'
function isPaymentAction(area: Area, action: string): boolean {
  return area === 'payments' || area === 'transfers' || (area === 'plans' && action === 'fulfil')
}
export function RecordPage({ area }: { area: Area }) {
  const { id = 'new' } = useParams()
  const company = useTreasuryCompany()
  return <RecordContent key={`${company}:${area}:${id}`} area={area} id={id} />
}
function Editor({
  area,
  record,
  onCancel,
  onSaved,
}: {
  area: Area
  record?: TreasuryRecord
  onCancel: () => void
  onSaved: (record: TreasuryRecord) => void
}) {
  if (area === 'accounts')
    return (
      <AccountEditor record={record as Account | undefined} onCancel={onCancel} onSaved={onSaved} />
    )
  if (area === 'payments')
    return (
      <PaymentEditor record={record as Payment | undefined} onCancel={onCancel} onSaved={onSaved} />
    )
  if (area === 'transfers')
    return (
      <TransferEditor
        record={record as Transfer | undefined}
        onCancel={onCancel}
        onSaved={onSaved}
      />
    )
  if (area === 'instruments')
    return (
      <InstrumentEditor
        record={record as Instrument | undefined}
        onCancel={onCancel}
        onSaved={onSaved}
      />
    )
  if (area === 'plans')
    return <PlanEditor record={record as Plan | undefined} onCancel={onCancel} onSaved={onSaved} />
  return <StatementImport />
}
function RecordContent({ area, id }: { area: Area; id: string | undefined }) {
  const t = useTreasuryT()
  const company = useTreasuryCompany()
  const navigate = useNavigate()
  const client = useQueryClient()
  const ctx = usePermissionContext()
  const [editing, setEditing] = useState(false)
  const [action, setAction] = useState<string | null>(null)
  const query = useRecord(area, id)
  const record = query.data
  const accounts = useAccounts()
  const partyId = record && 'party_id' in record ? record.party_id : ''
  const party = useQuery({
    queryKey: treasuryKey(company, 'party-label', partyId),
    queryFn: ({ signal }) => partiesApi.get(partyId, signal),
    enabled:
      Boolean(partyId) &&
      hasPermission(ctx, 'parties.view') &&
      !(record && 'party_name' in record && record.party_name),
    retry: false,
  })
  const canEdit = hasPermission(
    ctx,
    area === 'accounts' ? permission(area, 'manage') : permission(area, 'update'),
  )
  // A payment drafted offline has no status until the server takes it: it is still a draft.
  const draft =
    record?.status === 'draft' ||
    record?.status === 'scheduled' ||
    area === 'accounts' ||
    (area === 'payments' && record !== undefined && record.status == null)
  const onSaved = (result: TreasuryRecord) => {
    client.setQueryData(treasuryKey(company, area, result.id), result)
    setEditing(false)
    setAction(null)
    if (id === 'new') void navigate(`/treasury/${area}/${result.id}`)
    else void query.refetch()
  }
  if (id === 'new')
    return (
      <>
        <TreasuryHeader area={area} title={`${t('new')} · ${t(area)}`} />
        <Editor area={area} onCancel={() => void navigate(`/treasury/${area}`)} onSaved={onSaved} />
      </>
    )
  if (query.isPending) return <Loading />
  if (query.error || !record)
    return (
      <>
        <TreasuryHeader area={area} />
        <ErrorNotice error={query.error} reload={() => void query.refetch()} />
      </>
    )
  const data = record as unknown as Record<string, unknown>
  const accountLabel = (value: unknown) => {
    const found = accounts.data?.data.find((a) => a.id === value)
    return found ? `${found.code} · ${found.name}` : scalar(value)
  }
  const valueLabel = (key: string, value: unknown) =>
    ['account_id', 'from_account_id', 'to_account_id'].includes(key)
      ? accountLabel(value)
      : key === 'party_id'
        ? (('party_name' in record ? record.party_name : undefined) ??
          party.data?.name ??
          scalar(value))
        : ['type', 'kind', 'direction'].includes(key)
          ? t(scalar(value))
          : scalar(value)
  const title = scalar(
    area === 'plans'
      ? scalar(data.description).trim() || t('plans')
      : (data.name ??
          data.number ??
          data.instrument_number ??
          data.bank_reference ??
          t('unnumbered')),
  )
  const fieldNames =
    area === 'accounts'
      ? ['code', 'type', 'currency_code', 'bank_name', 'branch', 'iban', 'balance']
      : area === 'banking'
        ? ['bank_reference', 'currency_code', 'opening_balance', 'closing_balance']
        : area === 'transfers'
          ? [
              'from_account_id',
              'to_account_id',
              'document_date',
              'amount',
              'target_amount',
              'fee_amount',
            ]
          : area === 'instruments'
            ? [
                'kind',
                'direction',
                'party_id',
                'amount',
                'currency_code',
                'issue_date',
                'due_date',
                'bank_name',
                'drawer',
                'reference',
              ]
            : area === 'plans'
              ? ['direction', 'party_id', 'currency_code', 'amount', 'due_date', 'description']
              : [
                  'direction',
                  'party_id',
                  'account_id',
                  'document_date',
                  'amount',
                  'currency_code',
                  'fx_rate',
                  'reference',
                  'description',
                ]
  return (
    <div className="space-y-4">
      <TreasuryHeader area={area} title={title}>
        <div className="flex flex-wrap items-center gap-2">
          <Status value={record.status} />
          {canEdit && draft && area !== 'banking' && (
            <Button variant="secondary" onClick={() => setEditing(!editing)}>
              {t(editing ? 'cancel' : 'edit')}
            </Button>
          )}
          {lifecycleActions(area, record)
            .filter((a) => hasPermission(ctx, a.permission))
            .map((a) => {
              const props = {
                variant: ['reverse', 'cancel', 'return', 'dishonour'].includes(a.action)
                  ? ('secondary' as const)
                  : ('primary' as const),
                onClick: () => setAction(a.action),
                children: t(a.action),
              }
              // Payments, transfers and plan fulfilment post money movements on the server.
              return isPaymentAction(area, a.action) ? (
                <OnlineOnlyButton action="treasuryPayment" key={a.action} {...props} />
              ) : (
                <Button key={a.action} {...props} />
              )
            })}
          {area === 'accounts' &&
            (record as Account).has_movements === false &&
            hasPermission(ctx, 'treasury.accounts.manage') && (
              <Button onClick={() => setAction('opening-balance')}>{t('opening-balance')}</Button>
            )}
          {draft &&
            area !== 'banking' &&
            hasPermission(
              ctx,
              area === 'accounts' ? 'treasury.accounts.manage' : permission(area, 'delete'),
            ) && (
              <Button variant="ghost" onClick={() => setAction('delete')}>
                {t('delete')}
              </Button>
            )}
        </div>
      </TreasuryHeader>
      <BackLink area={area} />
      {editing ? (
        <Editor
          key={record.version}
          area={area}
          record={record}
          onCancel={() => setEditing(false)}
          onSaved={onSaved}
        />
      ) : (
        <>
          <Panel>
            <dl className="grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
              {fieldNames
                .filter((key) => data[key] !== null && data[key] !== undefined)
                .map((key) => (
                  <div key={key}>
                    <dt className="text-xs text-muted-foreground">{t(key)}</dt>
                    <dd className="mt-1 break-words text-sm font-medium">
                      {[
                        'amount',
                        'target_amount',
                        'fee_amount',
                        'balance',
                        'opening_balance',
                        'closing_balance',
                      ].includes(key) ? (
                        <Amount
                          value={scalar(data[key])}
                          currency={
                            typeof data.currency_code === 'string'
                              ? data.currency_code
                              : accounts.data?.data.find(
                                  (a) =>
                                    a.id ===
                                    (key === 'target_amount'
                                      ? data.to_account_id
                                      : data.from_account_id),
                                )?.currency_code
                          }
                        />
                      ) : (
                        valueLabel(key, data[key])
                      )}
                    </dd>
                  </div>
                ))}
            </dl>
            {record.journal_entry_id && hasPermission(ctx, 'accounting.entries.view') && (
              <Link
                className="mt-4 inline-block text-sm text-primary hover:underline"
                to={`/accounting/journal-entries/${record.journal_entry_id}`}
              >
                {t('journal')}
              </Link>
            )}
          </Panel>
          {area === 'accounts' && <AccountMovements account={record as Account} />}
          {area === 'payments' && <PaymentPreview payment={record as Payment} />}
          {'allocations' in record && record.allocations.length > 0 && (
            <AllocationSummary record={record} />
          )}
          {area === 'banking' && <StatementDetail record={record as Statement} onSaved={onSaved} />}
          {area === 'instruments' && Boolean((record as Instrument).events?.length) && (
            <Panel title={t('movements')}>
              <ol className="space-y-3">
                {(record as Instrument).events?.map((event, i) => (
                  <li key={i} className="border-l-2 border-primary/30 pl-3 text-sm">
                    <strong>{t(event.action)}</strong> · {event.document_date}
                    {event.reason && <p className="text-muted-foreground">{event.reason}</p>}
                  </li>
                ))}
              </ol>
            </Panel>
          )}
        </>
      )}
      {action && (
        <ActionDialog
          key={`${record.id}:${record.version}:${action}`}
          area={area}
          record={record}
          action={action}
          onClose={() => setAction(null)}
          onReload={() => {
            setAction(null)
            void query.refetch()
          }}
          onDone={(result) => (result ? onSaved(result) : void navigate(`/treasury/${area}`))}
        />
      )}
    </div>
  )
}
function AccountMovements({ account }: { account: Account }) {
  const t = useTreasuryT()
  const company = useTreasuryCompany()
  const [page, setPage] = useState(1)
  const movements = useQuery({
    queryKey: treasuryKey(company, 'account-movements', account.id, page),
    queryFn: ({ signal }) => treasuryApi.movements(account.id, { page, per_page: 25 }, signal),
  })
  return (
    <Panel title={t('movements')}>
      <ErrorNotice error={movements.error} />
      {movements.isPending ? (
        <Loading />
      ) : (
        <>
          <table className="w-full text-left text-sm">
            <thead>
              <tr>
                {['date', 'reference', 'amount'].map((k) => (
                  <th key={k} className="p-2">
                    {t(k)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {movements.data?.data.map((row) => (
                <tr key={row.id} className="border-t border-border">
                  <td className="p-2">{row.document_date}</td>
                  <td className="p-2">{row.reference}</td>
                  <td className="p-2">
                    <Amount value={row.amount} currency={account.currency_code} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="mt-4 flex justify-end gap-2">
            <Button variant="secondary" disabled={page === 1} onClick={() => setPage(page - 1)}>
              {t('previous')}
            </Button>
            <Button
              variant="secondary"
              disabled={page >= (movements.data?.meta.pagination.last_page ?? 1)}
              onClick={() => setPage(page + 1)}
            >
              {t('next')}
            </Button>
          </div>
        </>
      )}
    </Panel>
  )
}
function PaymentPreview({ payment }: { payment: Payment }) {
  const t = useTreasuryT()
  const company = useTreasuryCompany()
  // The posting preview is computed by the server; an offline runtime leaves it for later.
  const server = useOnlineOnly('serverCalculation')
  const open = ['draft', 'approved'].includes(payment.status ?? '')
  const preview = useQuery({
    queryKey: treasuryKey(company, 'payment-preview', payment.id, payment.version),
    queryFn: ({ signal }) => treasuryApi.previewPayment(payment.id, signal),
    enabled: open && !server.disabled,
  })
  if (!open) return null
  if (server.disabled)
    return (
      <Panel title={t('preview')}>
        <p role="status" className="text-sm text-muted-foreground">
          {t('previewOnline')}
        </p>
      </Panel>
    )
  return (
    <Panel title={t('preview')}>
      <ErrorNotice error={preview.error} />
      {preview.isPending ? (
        <Loading />
      ) : (
        preview.data && (
          <>
            <dl className="mb-4 grid gap-4 sm:grid-cols-4">
              {(['amount_local', 'allocated_local', 'advance_local', 'realized_fx'] as const).map(
                (key) => (
                  <div key={key}>
                    <dt className="text-xs text-muted-foreground">{t(key)}</dt>
                    <dd className="mt-1 text-sm font-semibold">
                      <Amount value={preview.data[key]} currency={preview.data.currency} />
                    </dd>
                  </div>
                ),
              )}
            </dl>
            <table className="w-full text-left text-sm">
              <thead>
                <tr>
                  {['gl_account_id', 'debit', 'credit'].map((k) => (
                    <th key={k} className="p-2">
                      {t(k)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {preview.data.lines.map((line, i) => (
                  <tr key={i} className="border-t border-border">
                    <td className="p-2 font-code">{line.account_id}</td>
                    <td className="p-2">{line.debit}</td>
                    <td className="p-2">{line.credit}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        )
      )}
    </Panel>
  )
}
function AllocationSummary({ record }: { record: Payment | Instrument }) {
  const t = useTreasuryT()
  const isInstrument = 'kind' in record
  return (
    <Panel title={t('allocations')}>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr>
              {(isInstrument
                ? ['invoice', 'amount']
                : ['invoice', 'amount', 'fx_rate', 'realized_fx']
              ).map((k) => (
                <th key={k} className="p-2">
                  {t(k)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {record.allocations.map((line) => (
              <tr
                key={`${line.invoice_module}:${line.invoice_id}`}
                className="border-t border-border"
              >
                <td className="p-2 font-code">{line.invoice_id}</td>
                <td className="p-2">{line.amount}</td>
                {!isInstrument && (
                  <>
                    <td className="p-2">{line.fx_rate}</td>
                    <td className="p-2">{line.realized_fx ?? '—'}</td>
                  </>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  )
}
