import React, { useState, useEffect } from 'react'
import { api } from '../../api/client'
import Spinner from '../ui/Spinner'
import Pagination from '../ui/Pagination'

const INVOICE_STATUS_STYLES = {
  pending:        { bg: 'rgba(156,163,175,0.2)', color: '#9ca3af' },
  waiting:        { bg: 'rgba(234,179,8,0.2)',   color: '#facc15' },
  confirming:     { bg: 'rgba(59,130,246,0.2)',  color: '#60a5fa' },
  confirmed:      { bg: 'rgba(34,197,94,0.2)',   color: '#4ade80' },
  partially_paid: { bg: 'rgba(249,115,22,0.2)',  color: '#fb923c' },
  expired:        { bg: 'rgba(239,68,68,0.2)',   color: '#f87171' },
  failed:         { bg: 'rgba(239,68,68,0.2)',   color: '#f87171' },
}

export default function CryptoDepositForm({ onSuccess }) {
  const [amount, setAmount]       = useState('')
  const [loading, setLoading]     = useState(false)
  const [error, setError]         = useState(null)
  const [invoiceUrl, setInvoiceUrl] = useState(null)

  const [invoices, setInvoices]   = useState([])
  const [invPage, setInvPage]     = useState(1)
  const [invTotal, setInvTotal]   = useState(1)
  const [invLoading, setInvLoading] = useState(true)

  const fetchInvoices = (page = 1) => {
    setInvLoading(true)
    api.cryptoInvoices(page)
      .then(d => {
        setInvoices(d.invoices ?? [])
        setInvTotal(d.total_pages ?? 1)
      })
      .catch(() => {})
      .finally(() => setInvLoading(false))
  }

  useEffect(() => { fetchInvoices(invPage) }, [invPage])

  const submit = async e => {
    e.preventDefault()
    setError(null)
    setInvoiceUrl(null)
    setLoading(true)
    try {
      const d = await api.cryptoDeposit({ amount: parseFloat(amount) })
      setInvoiceUrl(d.invoice_url)
      setAmount('')
      fetchInvoices(1)
      setInvPage(1)
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div>
      <div className="card p-4 mb-4" style={{ maxWidth: 480 }}>
        <h5 className="mb-3">
          <i className="bi bi-currency-bitcoin me-2" style={{ color: 'var(--accent)' }} />
          Crypto Deposit
        </h5>

        {error && <div className="alert alert-danger py-2">{error}</div>}

        {invoiceUrl && (
          <div className="alert py-2" style={{ background: 'rgba(34,197,94,0.15)', border: '1px solid rgba(34,197,94,0.3)', color: '#4ade80' }}>
            <i className="bi bi-check-circle me-2" />
            Invoice created!{' '}
            <a href={invoiceUrl} target="_blank" rel="noopener noreferrer" style={{ color: '#4ade80', textDecoration: 'underline' }}>
              Pay here <i className="bi bi-box-arrow-up-right ms-1" />
            </a>
          </div>
        )}

        <form onSubmit={submit}>
          <div className="mb-3">
            <label className="form-label">Amount (USD)</label>
            <input
              type="number" className="form-control"
              min="1" step="0.01" required
              placeholder="Minimum $1.00"
              value={amount} onChange={e => setAmount(e.target.value)}
            />
          </div>
          <button type="submit" className="btn btn-accent w-100" disabled={loading}>
            {loading && <span className="spinner-border spinner-border-sm me-2" />}
            Create Invoice
          </button>
        </form>
      </div>

      {/* Recent invoices */}
      <div className="card p-3">
        <h6 className="mb-3">Recent Crypto Invoices</h6>
        {invLoading ? <Spinner /> : invoices.length === 0 ? (
          <p className="text-muted mb-0">No crypto invoices yet.</p>
        ) : (
          <>
            <div className="table-responsive">
              <table className="table table-hover align-middle mb-0">
                <thead>
                  <tr><th>ID</th><th>Amount</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>
                  {invoices.map(inv => {
                    const s = INVOICE_STATUS_STYLES[inv.status] || INVOICE_STATUS_STYLES.pending
                    return (
                      <tr key={inv.id}>
                        <td>{inv.id}</td>
                        <td>${parseFloat(inv.amount_usd).toFixed(2)}</td>
                        <td>
                          <span className="badge" style={{ background: s.bg, color: s.color, border: `1px solid ${s.color}` }}>
                            {inv.status.replace('_', ' ')}
                          </span>
                        </td>
                        <td className="text-muted small">{inv.created_at}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <Pagination page={invPage} totalPages={invTotal} onChange={setInvPage} />
          </>
        )}
      </div>
    </div>
  )
}
