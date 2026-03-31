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
              <tr>
                <th>#</th>
                <th>Room</th>
                <th>Status</th>
                <th>Players</th>
                <th>Total Pot</th>
                <th>Winner</th>
                <th>Commission</th>
                <th>Referral Bonus</th>
                <th>Winner Net</th>
                <th>Payout ID</th>
                <th>Created</th>
                <th>Finished</th>
              </tr>
            </thead>
            <tbody>
              {paginated.map(g => {
                const finished = g.status === 'finished'
                return (
                  <tr key={g.id}>
                    <td>{g.id}</td>
                    <td>${g.room ?? 1}</td>
                    <td>
                      <span className={`badge ${
                        finished           ? 'badge-completed'
                      : g.status === 'countdown' ? 'badge-pending'
                      : 'bg-secondary'}`}>
                        {g.status}
                      </span>
                    </td>
                    <td>{g.player_count}</td>
                    <td>${parseFloat(g.total_pot).toFixed(2)}</td>
                    <td>{g.winner_email ?? '—'}</td>
                    <td>{finished && g.commission != null ? `$${parseFloat(g.commission).toFixed(2)}` : '—'}</td>
                    <td>{finished && g.referral_bonus != null ? `$${parseFloat(g.referral_bonus).toFixed(2)}` : '—'}</td>
                    <td>{finished && g.winner_net != null ? `$${parseFloat(g.winner_net).toFixed(2)}` : '—'}</td>
                    <td>
                      {finished && g.payout_id
                        ? <span title={g.payout_id} style={{ fontFamily: 'monospace', fontSize: '.85rem' }}>
                            {g.payout_id.slice(0, 8)}…
                          </span>
                        : '—'}
                    </td>
                    <td>{g.created_at}</td>
                    <td>{g.finished_at ?? '—'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        <Pagination page={page} totalPages={totalPages} onChange={setPage} />
      </div>
    </>
  )
}
