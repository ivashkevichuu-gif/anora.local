import { useFetch } from '../../hooks/useFetch'
import { api } from '../../api/client'
import Spinner from '../../components/ui/Spinner'
import Pagination, { usePagination } from '../../components/ui/Pagination'

export default function AdminLotteryGames() {
  const { data, loading, error } = useFetch(api.adminLotteryGames)
  const games = data?.games ?? []
  const { page, setPage, paginated, totalPages, total } = usePagination(games, 25)

  if (loading) return <Spinner fullPage />
  if (error)   return <div className="alert alert-danger">{error}</div>

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h4 className="mb-0"><i className="bi bi-dice-5-fill me-2"></i>Lottery Games</h4>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{total} total</span>
      </div>
      <div className="card p-3">
        <div className="table-responsive">
          <table className="table table-hover align-middle">
            <thead>
              <tr><th>#</th><th>Status</th><th>Players</th><th>Total Pot</th><th>Winner</th><th>Created</th><th>Finished</th></tr>
            </thead>
            <tbody>
              {paginated.map(g => (
                <tr key={g.id}>
                  <td>{g.id}</td>
                  <td>
                    <span className={`badge ${
                      g.status === 'finished'  ? 'badge-completed'
                    : g.status === 'countdown' ? 'badge-pending'
                    : 'bg-secondary'}`}>
                      {g.status}
                    </span>
                  </td>
                  <td>{g.player_count}</td>
                  <td>${parseFloat(g.total_pot).toFixed(2)}</td>
                  <td>{g.winner_email ?? '—'}</td>
                  <td>{g.created_at}</td>
                  <td>{g.finished_at ?? '—'}</td>
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
