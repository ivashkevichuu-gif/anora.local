import { useFetch } from '../../hooks/useFetch'
import { adminService } from '../../services/adminService'
import Spinner from '../../components/ui/Spinner'
import Pagination, { usePagination } from '../../components/ui/Pagination'

const directionColors = {
  credit: 'var(--neon-green)',
  debit:  '#f87171',
}

const typeBadgeColors = {
  deposit:                   'bg-success',
  crypto_deposit:            'bg-success',
  bet:                       'bg-warning text-dark',
  win:                       'bg-info text-dark',
  system_fee:                'bg-secondary',
  referral_bonus:            'bg-primary',
  withdrawal:                'bg-danger',
  crypto_withdrawal:         'bg-danger',
  crypto_withdrawal_refund:  'bg-warning text-dark',
}

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
              <tr>
                <th>#</th><th>User</th><th>Type</th><th>Direction</th>
                <th>Amount</th><th>Balance After</th><th>Reference</th><th>Date</th>
              </tr>
            </thead>
            <tbody>
              {paginated.map(tx => (
                <tr key={tx.id}>
                  <td>{tx.id}</td>
                  <td>{tx.email}</td>
                  <td>
                    <span className={`badge ${typeBadgeColors[tx.type] ?? 'bg-secondary'}`}>
                      {tx.type}
                    </span>
                  </td>
                  <td style={{ color: directionColors[tx.direction] ?? 'var(--text)' }}>
                    {tx.direction === 'credit' ? '+' : '−'}
                  </td>
                  <td>${parseFloat(tx.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                  <td>${parseFloat(tx.balance_after).toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                  <td style={{ fontSize: '.75rem', color: 'var(--text-muted)' }}>
                    {tx.reference_type}:{tx.reference_id}
                  </td>
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
