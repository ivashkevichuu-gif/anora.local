import { useState, useEffect } from 'react'
import { api } from '../../api/client'
import Spinner from '../../components/ui/Spinner'
import Pagination from '../../components/ui/Pagination'

const typeBadge = {
  system_fee:      'badge-completed',
  referral_bonus:  'badge-pending',
}

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

      <div className="card p-3">
        {loading && <div className="mb-2"><Spinner /></div>}
        <div className="table-responsive">
          <table className="table table-hover align-middle">
            <thead>
              <tr>
                <th>#</th><th>Type</th><th>Direction</th><th>Amount</th>
                <th>Balance After</th><th>Reference</th><th>Date</th>
              </tr>
            </thead>
            <tbody>
              {transactions.map(tx => (
                <tr key={tx.id}>
                  <td>{tx.id}</td>
                  <td>
                    <span className={`badge ${typeBadge[tx.type] ?? 'bg-secondary'}`}>
                      {tx.type}
                    </span>
                  </td>
                  <td style={{ color: tx.direction === 'credit' ? 'var(--neon-green)' : '#f87171' }}>
                    {tx.direction === 'credit' ? '+' : '−'}
                  </td>
                  <td>${parseFloat(tx.amount).toFixed(2)}</td>
                  <td>${parseFloat(tx.balance_after).toFixed(2)}</td>
                  <td style={{ fontSize: '.75rem', color: 'var(--text-muted)' }}>
                    {tx.reference_type}:{tx.reference_id}
                  </td>
                  <td>{tx.created_at}</td>
                </tr>
              ))}
              {transactions.length === 0 && (
                <tr><td colSpan={7} className="text-center text-muted">No transactions yet</td></tr>
              )}
            </tbody>
          </table>
        </div>
        <Pagination page={page} totalPages={totalPages} onChange={setPage} />
      </div>
    </>
  )
}
