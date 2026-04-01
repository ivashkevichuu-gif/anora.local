import { useState } from 'react'
import { useFetch } from '../../hooks/useFetch'
import { api } from '../../api/client'
import Spinner from '../../components/ui/Spinner'
import Pagination, { usePagination } from '../../components/ui/Pagination'

function RoundDetail({ roundId }) {
  const { data, loading, error } = useFetch(() => api.adminLotteryGameDetail(roundId), [roundId])

  if (loading) return <tr><td colSpan={14}><Spinner /></td></tr>
  if (error) return <tr><td colSpan={14} className="text-danger small">{error}</td></tr>
  if (!data) return null

  const { round, bets } = data

  return (
    <tr>
      <td colSpan={14} style={{ background: 'rgba(124,58,237,0.05)' }}>
        <div className="p-2">
          <div className="mb-3">
            <strong className="text-xs">Provably Fair</strong>
            <div className="small mt-1" style={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>
              <div><span className="text-muted">Server Seed:</span> {round?.server_seed ?? '—'}</div>
              <div><span className="text-muted">Combined Hash:</span> {round?.final_combined_hash ?? '—'}</div>
            </div>
          </div>
          <strong className="text-xs">Bets ({bets?.length ?? 0})</strong>
          <table className="table table-sm mt-1 mb-0">
            <thead>
              <tr><th>Player</th><th>Amount</th><th>Chance</th><th>Client Seed</th></tr>
            </thead>
            <tbody>
              {(bets ?? []).map((b, i) => (
                <tr key={i}>
                  <td>{b.display_name}</td>
                  <td>${parseFloat(b.amount).toFixed(2)}</td>
                  <td>{(b.chance * 100).toFixed(2)}%</td>
                  <td style={{ fontFamily: 'monospace', fontSize: '.8rem' }}>{b.client_seed ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </td>
    </tr>
  )
}

export default function AdminLotteryGames() {
  const { data, loading, error } = useFetch(api.adminLotteryGames)
  const games = data?.games ?? []
  const { page, setPage, paginated, totalPages, total } = usePagination(games, 25)
  const [expanded, setExpanded] = useState(null)

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
                <th>#</th><th>Room</th><th>Status</th><th>Players</th><th>Total Pot</th>
                <th>Winner</th><th>Winner Net</th><th>Commission</th><th>Referral</th>
                <th>Payout ID</th><th>Created</th><th>Finished</th><th></th>
              </tr>
            </thead>
            <tbody>
              {paginated.map(g => {
                const finished = g.status === 'finished'
                const isExpanded = expanded === g.id
                return (
                  <>
                    <tr key={g.id} style={{ cursor: finished ? 'pointer' : 'default' }}
                      onClick={() => finished && setExpanded(isExpanded ? null : g.id)}>
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
                      <td>{g.winner_name ?? g.winner_email ?? '—'}</td>
                      <td>{finished && g.winner_net != null ? `$${parseFloat(g.winner_net).toFixed(2)}` : '—'}</td>
                      <td>{finished && g.commission != null ? `$${parseFloat(g.commission).toFixed(2)}` : '—'}</td>
                      <td>{finished && g.referral_bonus != null ? `$${parseFloat(g.referral_bonus).toFixed(2)}` : '—'}</td>
                      <td>
                        {finished && g.payout_id
                          ? <span title={g.payout_id} style={{ fontFamily: 'monospace', fontSize: '.85rem' }}>
                              {g.payout_id.slice(0, 8)}…
                            </span>
                          : '—'}
                      </td>
                      <td>{g.created_at}</td>
                      <td>{g.finished_at ?? '—'}</td>
                      <td>
                        {finished && (
                          <i className={`bi ${isExpanded ? 'bi-chevron-up' : 'bi-chevron-down'}`}></i>
                        )}
                      </td>
                    </tr>
                    {isExpanded && <RoundDetail key={`detail-${g.id}`} roundId={g.id} />}
                  </>
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
