import { useState } from 'react'
import { useFetch } from '../../hooks/useFetch'
import { useAsync } from '../../hooks/useAsync'
import { adminService } from '../../services/adminService'
import { StatusBadge } from '../../components/ui/StatusBadge'
import StatusMessage from '../../components/ui/StatusMessage'
import Spinner from '../../components/ui/Spinner'
import Pagination, { usePagination } from '../../components/ui/Pagination'

export default function AdminWithdrawals() {
  const { data, loading, error } = useFetch(adminService.getWithdrawals)
  const [reqs, setReqs] = useState(null)
  const { execute: processAction, loading: acting, error: actionError, success: actionSuccess, reset } = useAsync(
    (id, action) => adminService.processWithdrawal(id, action)
  )

  const requests = reqs ?? data?.requests ?? []
  const { page, setPage, paginated, totalPages, total } = usePagination(requests, 20)

  const doAction = async (id, action) => {
    if (!window.confirm(`${action.charAt(0).toUpperCase() + action.slice(1)} this request?`)) return
    reset()
    try {
      await processAction(id, action)
      setReqs((reqs ?? data?.requests ?? []).map(r =>
        r.id === id ? { ...r, status: action === 'approve' ? 'approved' : 'rejected' } : r
      ))
    } catch { /* shown via StatusMessage */ }
  }

  if (loading) return <Spinner fullPage />
  if (error)   return <div className="alert alert-danger">{error}</div>

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h4 className="mb-0"><i className="bi bi-arrow-up-circle me-2"></i>Withdrawal Requests</h4>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{total} total</span>
      </div>
      <StatusMessage error={actionError} success={actionSuccess} />
      <div className="card p-3">
        <div className="table-responsive">
          <table className="table table-hover align-middle">
            <thead>
              <tr><th>#</th><th>User</th><th>Amount</th><th>Bank Details</th><th>Status</th><th>Date</th><th>Actions</th></tr>
            </thead>
            <tbody>
              {paginated.map(r => (
                <tr key={r.id}>
                  <td>{r.id}</td>
                  <td>{r.email}</td>
                  <td>${parseFloat(r.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                  <td style={{ maxWidth: 200, whiteSpace: 'pre-wrap', fontSize: '.85rem' }}>{r.bank_details}</td>
                  <td><StatusBadge status={r.status} /></td>
                  <td>{r.created_at}</td>
                  <td>
                    {r.status === 'pending' ? (
                      <>
                        <button className="btn btn-sm btn-success me-1" disabled={acting} onClick={() => doAction(r.id, 'approve')}>
                          <i className="bi bi-check-lg"></i> Approve
                        </button>
                        <button className="btn btn-sm btn-danger" disabled={acting} onClick={() => doAction(r.id, 'reject')}>
                          <i className="bi bi-x-lg"></i> Reject
                        </button>
                      </>
                    ) : <span className="text-muted small">—</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={page} totalPages={totalPages} onChange={setPage} />
      </div>
    </>
  )
}
