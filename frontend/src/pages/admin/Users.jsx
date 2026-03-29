import { useFetch } from '../../hooks/useFetch'
import { adminService } from '../../services/adminService'
import Spinner from '../../components/ui/Spinner'
import Pagination, { usePagination } from '../../components/ui/Pagination'

export default function AdminUsers() {
  const { data, loading, error } = useFetch(adminService.getUsers)
  const users = data?.users ?? []
  const { page, setPage, paginated, totalPages, total } = usePagination(users, 20)

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
              <tr><th>#</th><th>Email</th><th>Balance</th><th>Verified</th><th>Bank Details</th><th>Registered</th></tr>
            </thead>
            <tbody>
              {paginated.map(u => (
                <tr key={u.id}>
                  <td>{u.id}</td>
                  <td>{u.email}</td>
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
