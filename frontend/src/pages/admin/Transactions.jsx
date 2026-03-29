import { useFetch } from '../../hooks/useFetch'
import { adminService } from '../../services/adminService'
import { TypeBadge, StatusBadge } from '../../components/ui/StatusBadge'
import Spinner from '../../components/ui/Spinner'
import Pagination, { usePagination } from '../../components/ui/Pagination'

export default function AdminTransactions() {
  const { data, loading, error } = useFetch(adminService.getTransactions)
  const txs = data?.transactions ?? []
  const { page, setPage, paginated, totalPages, total } = usePagination(txs, 25)

  if (loading) return <Spinner fullPage />
  if (error)   return <div className="alert alert-danger">{error}</div>

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h4 className="mb-0"><i className="bi bi-list-ul me-2"></i>All Transactions</h4>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{total} total</span>
      </div>
      <div className="card p-3">
        <div className="table-responsive">
          <table className="table table-hover align-middle">
            <thead>
              <tr><th>#</th><th>User</th><th>Type</th><th>Amount</th><th>Status</th><th>Note</th><th>Date</th></tr>
            </thead>
            <tbody>
              {paginated.map(tx => (
                <tr key={tx.id}>
                  <td>{tx.id}</td>
                  <td>{tx.email}</td>
                  <td><TypeBadge type={tx.type} /></td>
                  <td>${parseFloat(tx.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                  <td><StatusBadge status={tx.status} /></td>
                  <td>{tx.note || '—'}</td>
                  <td>{tx.created_at}</td>
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
