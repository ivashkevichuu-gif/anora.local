import { useState, useEffect } from 'react'
import { api } from '../../api/client'
import Spinner from '../../components/ui/Spinner'
import Pagination from '../../components/ui/Pagination'

export default function SystemBalance() {
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    api.adminSystemBalance(page)
      .then(d => { if (!cancelled) { setData(d); setLoading(false) } })
      .catch(e => { if (!cancelled) { setError(e.message); setLoading(false) } })
    return () => { cancelled = true }
  }, [page])

  if (loading && !data) return <Spinner fullPage />
  if (error)            return <div className="alert alert-danger">{error}</div>

  const sb = data?.system_balance ?? {}
  const transactions = data?.transactions ?? []
  const totalPages = data?.total_pages ?? 1
  const totalCount = data?.total_count ?? 0

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h4 className="mb-0"><i className="bi bi-cash-stack me-2"></i>System Balance</h4>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{totalCount} transactions</span>
      </div>

      {/* Summary cards */}
      <div className="row g-3 mb-4">
        <div className="col-md-4">
          <div className="card p-3 text-center">
            <div className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Current Balance</div>
            <div className="fs-4 fw-bold" style={{ color: 'var(--neon-purple)' }}>
              ${parseFloat(sb.balance ?? 0).toFixed(2)}
            </div>
          </div>
        </div>
        <div className="col-md-4">
          <div className="card p-3 text-center">
            <div className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Total Commission</div>
            <div className="fs-4 fw-bold text-success">
              ${parseFloat(sb.total_commission ?? 0).toFixed(2)}
            </div>
          </div>
        </div>
        <div className="col-md-4">
          <div className="card p-3 text-center">
            <div className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Referral Unclaimed</div>
            <div className="fs-4 fw-bold text-warning">
              ${parseFloat(sb.total_referral_unclaimed ?? 0).toFixed(2)}
            </div>
          </div>
        </div>
      </div>

      {/* Transactions table */}
      <div className="card p-3">
        {loading && <div className="mb-2"><Spinner /></div>}
        <div className="table-responsive">
          <table className="table table-hover align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Source User</th>
                <th>Payout ID</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              {transactions.map(tx => (
                <tr key={tx.id}>
                  <td>{tx.id}</td>
                  <td>
                    <span className={`badge ${tx.type === 'commission' ? 'badge-completed' : 'badge-pending'}`}>
                      {tx.type}
                    </span>
                  </td>
                  <td>${parseFloat(tx.amount).toFixed(2)}</td>
                  <td>{tx.source_email ?? '—'}</td>
                  <td>
                    {tx.payout_id
                      ? <span title={tx.payout_id} style={{ fontFamily: 'monospace', fontSize: '.85rem' }}>
                          {tx.payout_id.slice(0, 8)}…
                        </span>
                      : '—'}
                  </td>
                  <td>{tx.created_at}</td>
                </tr>
              ))}
              {transactions.length === 0 && (
                <tr><td colSpan={6} className="text-center text-muted">No transactions yet</td></tr>
              )}
            </tbody>
          </table>
        </div>
        <Pagination page={page} totalPages={totalPages} onChange={setPage} />
      </div>
    </>
  )
}
