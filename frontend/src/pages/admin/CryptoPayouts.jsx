import { useState, useEffect } from 'react'
import { api } from '../../api/client'
import Spinner from '../../components/ui/Spinner'
import Pagination from '../../components/ui/Pagination'

const PAYOUT_STATUS_STYLES = {
  pending:            { bg: 'rgba(156,163,175,0.2)', color: '#9ca3af' },
  awaiting_approval:  { bg: 'rgba(234,179,8,0.2)',   color: '#facc15' },
  processing:         { bg: 'rgba(59,130,246,0.2)',  color: '#60a5fa' },
  completed:          { bg: 'rgba(34,197,94,0.2)',   color: '#4ade80' },
  failed:             { bg: 'rgba(239,68,68,0.2)',   color: '#f87171' },
  rejected:           { bg: 'rgba(239,68,68,0.2)',   color: '#f87171' },
}

const STATUS_OPTIONS = ['', 'pending', 'awaiting_approval', 'processing', 'completed', 'failed', 'rejected']

export default function CryptoPayouts() {
  const [page, setPage]       = useState(1)
  const [status, setStatus]   = useState('')
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)
  const [acting, setActing]   = useState(null)
  const [actionError, setActionError] = useState(null)

  const fetchData = () => {
    setLoading(true)
    api.adminCryptoPayouts(page, status)
      .then(d => { setData(d); setLoading(false) })
      .catch(e => { setError(e.message); setLoading(false) })
  }

  useEffect(() => { fetchData() }, [page, status])

  const handleStatusChange = e => {
    setStatus(e.target.value)
    setPage(1)
  }

  const doAction = async (payoutId, action) => {
    if (!window.confirm(`${action.charAt(0).toUpperCase() + action.slice(1)} this payout?`)) return
    setActing(payoutId)
    setActionError(null)
    try {
      await api.adminCryptoPayoutAction({ action, payout_id: payoutId })
      fetchData()
    } catch (e) {
      setActionError(e.message)
    } finally {
      setActing(null)
    }
  }

  if (loading && !data) return <Spinner fullPage />
  if (error)            return <div className="alert alert-danger">{error}</div>

  const payouts    = data?.payouts ?? []
  const totalPages = data?.total_pages ?? 1
  const totalCount = data?.total_count ?? 0

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h4 className="mb-0"><i className="bi bi-wallet2 me-2"></i>Crypto Payouts</h4>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{totalCount} total</span>
      </div>

      {actionError && <div className="alert alert-danger py-2">{actionError}</div>}

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
                <th>Wallet</th>
                <th>Currency</th>
                <th>Status</th>
                <th>NP Payout ID</th>
                <th>Date</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {payouts.map(p => {
                const s = PAYOUT_STATUS_STYLES[p.status] || PAYOUT_STATUS_STYLES.pending
                return (
                  <tr key={p.id}>
                    <td>{p.id}</td>
                    <td>{p.email}</td>
                    <td>${parseFloat(p.amount_usd).toFixed(2)}</td>
                    <td>
                      <span title={p.wallet_address} style={{ fontFamily: 'monospace', fontSize: '.85rem' }}>
                        {p.wallet_address.length > 14
                          ? p.wallet_address.slice(0, 6) + '…' + p.wallet_address.slice(-6)
                          : p.wallet_address}
                      </span>
                    </td>
                    <td>{p.currency?.toUpperCase()}</td>
                    <td>
                      <span className="badge" style={{ background: s.bg, color: s.color, border: `1px solid ${s.color}` }}>
                        {p.status.replace('_', ' ')}
                      </span>
                    </td>
                    <td>
                      {p.nowpayments_payout_id
                        ? <span title={p.nowpayments_payout_id} style={{ fontFamily: 'monospace', fontSize: '.85rem' }}>
                            {p.nowpayments_payout_id.length > 12
                              ? p.nowpayments_payout_id.slice(0, 12) + '…'
                              : p.nowpayments_payout_id}
                          </span>
                        : '—'}
                    </td>
                    <td className="text-muted small">{p.created_at}</td>
                    <td>
                      {p.status === 'awaiting_approval' ? (
                        <>
                          <button
                            className="btn btn-sm btn-success me-1"
                            disabled={acting === p.id}
                            onClick={() => doAction(p.id, 'approve')}
                          >
                            {acting === p.id ? <span className="spinner-border spinner-border-sm" /> : <i className="bi bi-check-lg"></i>}
                            {' '}Approve
                          </button>
                          <button
                            className="btn btn-sm btn-danger"
                            disabled={acting === p.id}
                            onClick={() => doAction(p.id, 'reject')}
                          >
                            {acting === p.id ? <span className="spinner-border spinner-border-sm" /> : <i className="bi bi-x-lg"></i>}
                            {' '}Reject
                          </button>
                        </>
                      ) : <span className="text-muted small">—</span>}
                    </td>
                  </tr>
                )
              })}
              {payouts.length === 0 && (
                <tr><td colSpan={9} className="text-center text-muted">No payouts found</td></tr>
              )}
            </tbody>
          </table>
        </div>
        <Pagination page={page} totalPages={totalPages} onChange={setPage} />
      </div>
    </>
  )
}
