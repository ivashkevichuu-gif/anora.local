import { useState } from 'react'
import { useFetch } from '../../hooks/useFetch'
import { adminService } from '../../services/adminService'
import { api } from '../../api/client'
import Spinner from '../../components/ui/Spinner'
import Pagination, { usePagination } from '../../components/ui/Pagination'

export default function AdminUsers() {
  const { data, loading, error, refetch } = useFetch(adminService.getUsers)
  const users = data?.users ?? []
  const { page, setPage, paginated, totalPages, total } = usePagination(users, 20)
  const [acting, setActing] = useState(null)

  const handleAction = async (action, userId) => {
    setActing(`${action}-${userId}`)
    try {
      await api.adminAction({ action, id: userId })
      await refetch()
    } catch (e) {
      alert(`Action failed: ${e.message}`)
    } finally {
      setActing(null)
    }
  }

  if (loading) return <Spinner fullPage />
  if (error)   return <div className="alert alert-danger">{error}</div>

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h4 className="mb-0"><i className="bi bi-people-fill me-2"></i>All Users</h4>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{total} total</span>
      </div>
      <div className="card p-3">
        <div className="table-responsive">
          <table className="table table-hover align-middle">
            <thead>
              <tr>
                <th>#</th><th>Email</th><th>Status</th><th>Balance</th>
                <th>Verified</th><th>Bank Details</th><th>Registered</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {paginated.map(u => (
                <tr key={u.id}>
                  <td>{u.id}</td>
                  <td>{u.email}</td>
                  <td>
                    <div className="d-flex gap-1 flex-wrap">
                      {u.fraud_flagged == 1 && (
                        <span className="badge" style={{ background: '#dc3545', color: '#fff' }}>FRAUD</span>
                      )}
                      {u.is_banned == 1 && (
                        <span className="badge" style={{ background: '#fd7e14', color: '#fff' }}>BANNED</span>
                      )}
                      {u.fraud_flagged != 1 && u.is_banned != 1 && (
                        <span className="text-muted">—</span>
                      )}
                    </div>
                  </td>
                  <td>${parseFloat(u.balance).toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                  <td>
                    <span className={`badge ${u.is_verified == 1 ? 'bg-success' : 'bg-secondary'}`}>
                      {u.is_verified == 1 ? 'Yes' : 'No'}
                    </span>
                  </td>
                  <td>
                    {u.bank_details
                      ? <span className="text-muted small" style={{ whiteSpace: 'pre-wrap', maxWidth: 200, display: 'block' }}>{u.bank_details}</span>
                      : <span className="text-muted">—</span>}
                  </td>
                  <td>{u.created_at}</td>
                  <td>
                    <div className="d-flex gap-1">
                      {u.fraud_flagged == 1 && (
                        <button
                          className="btn btn-sm btn-outline-warning"
                          disabled={acting === `clear_fraud_flag-${u.id}`}
                          onClick={() => handleAction('clear_fraud_flag', u.id)}
                        >
                          {acting === `clear_fraud_flag-${u.id}` ? '…' : 'Clear Flag'}
                        </button>
                      )}
                      {u.is_banned != 1 && (
                        <button
                          className="btn btn-sm btn-outline-danger"
                          disabled={acting === `ban-${u.id}`}
                          onClick={() => handleAction('ban', u.id)}
                        >
                          {acting === `ban-${u.id}` ? '…' : 'Ban'}
                        </button>
                      )}
                    </div>
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
