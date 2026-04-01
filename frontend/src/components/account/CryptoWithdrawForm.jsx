import React, { useState, useEffect } from 'react'
import { api } from '../../api/client'
import Spinner from '../ui/Spinner'
import Pagination from '../ui/Pagination'

const CRYPTO_OPTIONS = ['btc','eth','ltc','usdt','trx','sol','bnb','matic','doge']

const PAYOUT_STATUS_STYLES = {
  pending:            { bg: 'rgba(156,163,175,0.2)', color: '#9ca3af' },
  awaiting_approval:  { bg: 'rgba(234,179,8,0.2)',   color: '#facc15' },
  processing:         { bg: 'rgba(59,130,246,0.2)',  color: '#60a5fa' },
  completed:          { bg: 'rgba(34,197,94,0.2)',   color: '#4ade80' },
  failed:             { bg: 'rgba(239,68,68,0.2)',   color: '#f87171' },
  rejected:           { bg: 'rgba(239,68,68,0.2)',   color: '#f87171' },
}

export default function CryptoWithdrawForm({ defaultWallet, defaultCurrency }) {
  const [form, setForm] = useState({
    amount: '',
    wallet_address: defaultWallet || '',
    currency: defaultCurrency || 'btc',
  })
  const [loading, setLoading]   = useState(false)
  const [error, setError]       = useState(null)
  const [success, setSuccess]   = useState(null)

  const [payouts, setPayouts]   = useState([])
  const [payPage, setPayPage]   = useState(1)
  const [payTotal, setPayTotal] = useState(1)
  const [payLoading, setPayLoading] = useState(true)

  const fetchPayouts = (page = 1) => {
    setPayLoading(true)
    api.cryptoPayouts(page)
      .then(d => {
        setPayouts(d.payouts ?? [])
        setPayTotal(d.total_pages ?? 1)
      })
      .catch(() => {})
      .finally(() => setPayLoading(false))
  }

  useEffect(() => { fetchPayouts(payPage) }, [payPage])

  const handle = e => setForm(f => ({ ...f, [e.target.name]: e.target.value }))

  const submit = async e => {
    e.preventDefault()
    setError(null)
    setSuccess(null)
    setLoading(true)
    try {
      const d = await api.cryptoWithdraw({
        amount: parseFloat(form.amount),
        wallet_address: form.wallet_address,
        currency: form.currency,
      })
      setSuccess(d.message || `Withdrawal submitted (${d.status})`)
      setForm(f => ({ ...f, amount: '' }))
      fetchPayouts(1)
      setPayPage(1)
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div>
      <div className="card p-4 mb-4" style={{ maxWidth: 480 }}>
        <h5 className="mb-3" style={{ color: 'var(--text)' }}>
          <i className="bi bi-wallet2 me-2" style={{ color: 'var(--accent)' }} />
          Crypto Withdrawal
        </h5>

        {error && <div className="alert alert-danger py-2">{error}</div>}
        {success && (
          <div className="alert py-2" style={{ background: 'rgba(34,197,94,0.15)', border: '1px solid rgba(34,197,94,0.3)', color: '#4ade80' }}>
            <i className="bi bi-check-circle me-2" />{success}
          </div>
        )}

        <form onSubmit={submit}>
          <div className="mb-3">
            <label className="form-label">Amount (USD)</label>
            <input
              type="number" name="amount" className="form-control"
              min="5" step="0.01" required
              placeholder="Minimum $5.00"
              value={form.amount} onChange={handle}
            />
          </div>
          <div className="mb-3">
            <label className="form-label">Wallet Address</label>
            <input
              type="text" name="wallet_address" className="form-control"
              required placeholder="Your crypto wallet address"
              value={form.wallet_address} onChange={handle}
            />
          </div>
          <div className="mb-3">
            <label className="form-label">Cryptocurrency</label>
            <select name="currency" className="form-select" value={form.currency} onChange={handle}>
              {CRYPTO_OPTIONS.map(c => (
                <option key={c} value={c}>{c.toUpperCase()}</option>
              ))}
            </select>
          </div>
          <button type="submit" className="btn btn-accent w-100" disabled={loading}>
            {loading && <span className="spinner-border spinner-border-sm me-2" />}
            Submit Withdrawal
          </button>
        </form>
      </div>

      {/* Recent payouts */}
      <div className="card p-3">
        <h6 className="mb-3">Recent Crypto Payouts</h6>
        {payLoading ? <Spinner /> : payouts.length === 0 ? (
          <p className="text-muted mb-0">No crypto payouts yet.</p>
        ) : (
          <>
            <div className="table-responsive">
              <table className="table table-hover align-middle mb-0">
                <thead>
                  <tr><th>ID</th><th>Amount</th><th>Currency</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>
                  {payouts.map(p => {
                    const s = PAYOUT_STATUS_STYLES[p.status] || PAYOUT_STATUS_STYLES.pending
                    return (
                      <tr key={p.id}>
                        <td>{p.id}</td>
                        <td>${parseFloat(p.amount_usd).toFixed(2)}</td>
                        <td>{p.currency?.toUpperCase()}</td>
                        <td>
                          <span className="badge" style={{ background: s.bg, color: s.color, border: `1px solid ${s.color}` }}>
                            {p.status.replace('_', ' ')}
                          </span>
                        </td>
                        <td className="text-muted small">{p.created_at}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <Pagination page={payPage} totalPages={payTotal} onChange={setPayPage} />
          </>
        )}
      </div>
    </div>
  )
}
