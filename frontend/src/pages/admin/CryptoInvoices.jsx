import { useState, useEffect } from 'react'
import { api } from '../../api/client'
import Spinner from '../../components/ui/Spinner'
import Pagination from '../../components/ui/Pagination'

const INVOICE_STATUS_STYLES = {
  pending:        { bg: 'rgba(156,163,175,0.2)', color: '#9ca3af' },
  waiting:        { bg: 'rgba(234,179,8,0.2)',   color: '#facc15' },
  confirming:     { bg: 'rgba(59,130,246,0.2)',  color: '#60a5fa' },
  confirmed:      { bg: 'rgba(34,197,94,0.2)',   color: '#4ade80' },
  partially_paid: { bg: 'rgba(249,115,22,0.2)',  color: '#fb923c' },
  expired:        { bg: 'rgba(239,68,68,0.2)',   color: '#f87171' },
  failed:         { bg: 'rgba(239,68,68,0.2)',   color: '#f87171' },
}

const STATUS_OPTIONS = ['', 'pending', 'waiting', 'confirming', 'confirmed', 'partially_paid', 'expired', 'failed']

export default function CryptoInvoices() {
  const [page, setPage]       = useState(1)
  const [status, setStatus]   = useState('')
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    api.adminCryptoInvoices(page, status)
      .then(d => { if (!cancelled) { setData(d); setLoading(false) } })
      .catch(e => { if (!cancelled) { setError(e.message); setLoading(false) } })
    return () => { cancelled = true }
  }, [page, status])

  const handleStatusChange = e => {
    setStatus(e.target.value)
    setPage(1)
  }

  if (loading && !data) return <Spinner fullPage />
  if (error)            return <div className="alert alert-danger">{error}</div>

  const invoices   = data?.invoices ?? []
  const totalPages = data?.total_pages ?? 1
  const totalCount = data?.total_count ?? 0

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h4 className="mb-0"><i className="bi bi-currency-bitcoin me-2"></i>Crypto Invoices</h4>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{totalCount} total</span>
      </div>

      <div className="mb-3" style={{ maxWidth: 220 }}>
        <select className="form-select form-select-sm" value={status} onChange={handleStatusChange}>
          {STATUS_OPTIONS.map(s => (
            <option key={s} value={s}>{s ? s.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'All Statuses'}</option>
          ))}
        </select>
      </div>

      <div className="card p-3">
        {loading && <div className="mb-2"><Spinner /></div>}
        <div className="table-responsive">
          <table className="table table-hover align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>User</th>
                <th>Amount</th>
                <th>Credited</th>
                <th>Currency</th>
                <th>Status</th>
                <th>NP Invoice ID</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              {invoices.map(inv => {
                const s = INVOICE_STATUS_STYLES[inv.status] || INVOICE_STATUS_STYLES.pending
                return (
                  <tr key={inv.id}>
                    <td>{inv.id}</td>
                    <td>{inv.email}</td>
                    <td>${parseFloat(inv.amount_usd).toFixed(2)}</td>
                    <td>{inv.credited_usd != null ? `$${parseFloat(inv.credited_usd).toFixed(2)}` : '—'}</td>
                    <td>{inv.currency?.toUpperCase() || '—'}</td>
                    <td>
                      <span className="badge" style={{ background: s.bg, color: s.color, border: `1px solid ${s.color}` }}>
                        {inv.status.replace('_', ' ')}
                      </span>
                    </td>
                    <td>
                      {inv.nowpayments_invoice_id
                        ? <span title={inv.nowpayments_invoice_id} style={{ fontFamily: 'monospace', fontSize: '.85rem' }}>
                            {inv.nowpayments_invoice_id.length > 12
                              ? inv.nowpayments_invoice_id.slice(0, 12) + '…'
                              : inv.nowpayments_invoice_id}
                          </span>
                        : '—'}
                    </td>
                    <td className="text-muted small">{inv.created_at}</td>
                  </tr>
                )
              })}
              {invoices.length === 0 && (
                <tr><td colSpan={8} className="text-center text-muted">No invoices found</td></tr>
              )}
            </tbody>
          </table>
        </div>
        <Pagination page={page} totalPages={totalPages} onChange={setPage} />
      </div>
    </>
  )
}
