import { screen } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import type { AppExtensions } from '@/app/extensions'
import { TREAS_INSTRUMENT, TREAS_PAYMENT, TREAS_STATEMENT } from '@/test/handlers/treasury'
import { server } from '@/test/msw/server'
import { renderTreasury, setupTreasury } from './testing'

// A runtime with an offline mode disables server-only actions through the online-only extension.
const ext = vi.hoisted((): { value: AppExtensions } => ({
  value: { routes: [], publicRoutes: [] },
}))
vi.mock('@/app/extensions', () => ({
  get appExtensions() {
    return ext.value
  },
}))

afterEach(() => {
  ext.value = { routes: [], publicRoutes: [] }
})

// Payments and transfers post money movements, and so does fulfilling a plan; cancelling a plan,
// instrument steps and bank reconciliation are not the treasuryPayment action.
const CASES: { area: string; id: string; disabled: string[]; enabled: string[] }[] = [
  {
    area: 'payments',
    id: TREAS_PAYMENT,
    disabled: ['Onaya gönder', 'Kaydet / işle', 'İptal'],
    enabled: [],
  },
  { area: 'transfers', id: 'transfer-1', disabled: ['Kaydet / işle', 'İptal'], enabled: [] },
  { area: 'plans', id: 'plan-1', disabled: ['Kaydedilmiş ödemeyi bağla'], enabled: ['İptal'] },
  {
    area: 'instruments',
    id: TREAS_INSTRUMENT,
    disabled: [],
    enabled: [
      'Tedarikçiye ciro et',
      'Tahsile ver',
      'Teminata ver',
      'Hesaptan tahsil / öde',
      'Karşılıksız işle',
      'İade et',
    ],
  },
  { area: 'banking', id: TREAS_STATEMENT, disabled: [], enabled: ['Mutabakatı tamamla'] },
]

describe('treasury record online-only actions', () => {
  it.each(CASES)(
    '$area disables exactly its payment actions while treasuryPayment is unavailable',
    async ({ area, id, disabled, enabled }) => {
      const asked = vi.fn((action: string) =>
        action === 'treasuryPayment'
          ? { disabled: true, reason: 'Bu işlem için bağlantı gerekir.' }
          : { disabled: false },
      )
      ext.value = { routes: [], publicRoutes: [], useOnlineOnly: asked }
      setupTreasury()
      renderTreasury(`/treasury/${area}/${id}`)
      const first = disabled[0] ?? enabled[0] ?? ''
      await screen.findByRole('button', { name: first })
      for (const name of disabled)
        expect(screen.getByRole('button', { name })).toHaveAttribute('aria-disabled', 'true')
      for (const name of enabled)
        expect(screen.getByRole('button', { name })).not.toHaveAttribute('aria-disabled')
      if (disabled.length > 0) expect(asked).toHaveBeenCalledWith('treasuryPayment')
    },
  )

  it('keeps payment actions available while the runtime allows them', async () => {
    setupTreasury()
    renderTreasury(`/treasury/payments/${TREAS_PAYMENT}`)
    const post = await screen.findByRole('button', { name: 'Kaydet / işle' })
    expect(post).not.toHaveAttribute('aria-disabled')
    expect((await screen.findAllByText('100.00')).length).toBeGreaterThan(0)
  })

  // Offline a payment draft is still edited and deleted (offline-manifest treasury-payments);
  // the posting preview is a server calculation and is not asked for.
  it('keeps draft edits available and skips the server preview while offline', async () => {
    const asked = vi.fn((action: string) =>
      ['treasuryPayment', 'serverCalculation'].includes(action)
        ? { disabled: true, reason: 'Bu işlem için bağlantı gerekir.' }
        : { disabled: false },
    )
    ext.value = { routes: [], publicRoutes: [], useOnlineOnly: asked }
    setupTreasury()
    const previews = vi.fn()
    server.use(
      http.get('*/api/v1/treasury-payments/:id/preview', () => {
        previews()
        return HttpResponse.json({ data: {} })
      }),
    )
    renderTreasury(`/treasury/payments/${TREAS_PAYMENT}`)
    expect(await screen.findByRole('button', { name: 'Düzenle' })).not.toHaveAttribute(
      'aria-disabled',
    )
    expect(screen.getByRole('button', { name: 'Sil' })).not.toHaveAttribute('aria-disabled')
    expect(screen.getByRole('button', { name: 'Kaydet / işle' })).toHaveAttribute(
      'aria-disabled',
      'true',
    )
    expect(
      screen.getByText('Kayıt önizlemesi sunucuda hesaplanır; bağlantı gelince görünür.'),
    ).toBeInTheDocument()
    expect(asked).toHaveBeenCalledWith('serverCalculation')
    expect(previews).not.toHaveBeenCalled()
  })

  it('treats a payment drafted on this device, not yet sent, as a draft', async () => {
    const db = setupTreasury()
    const [payment] = db.records.payments
    if (payment) delete payment.status
    renderTreasury(`/treasury/payments/${TREAS_PAYMENT}`)
    expect(await screen.findByRole('button', { name: 'Düzenle' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Sil' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Kaydet / işle' })).not.toBeInTheDocument()
  })
})
