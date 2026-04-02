import React, { useState, useEffect, useCallback } from 'react'
import { api } from '../../api/client'
import Spinner from '../../components/ui/Spinner'
import Pagination from '../../components/ui/Pagination'

const fmt = (v) => '$' + parseFloat(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtPct = (v) => (v != null ? parseFloat(v).toFixed(2) : '—') + '%'

const ROOMS = ['', '1', '10', '100']

export default function GamesAnalytics() {
  const [filters, setFilters] = useState({ room: '', date_from: '', date_to: '' })
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [expandedRound, setExpandedRound] = useState(null)
  const [detail, setDetail] = useState(null)
  const [detailLoading, setDetailLoading] = useState(false)

  const fetchData = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const params = { page, per_page: 20 }
      Object.entries(filters).forEach(([k, v]) => { if (v) params[k] = v })
      const result = await api.adminGamesAnalytics(params)
      setData(result)
    } catch (e) {
      setError(e.message)
    } finally {
      setLoading(false)
    }
  }, [page, filters])

  useEffect(() => { fetchData() }, [fetchData])

  const updateFilter = (key, value) => {
    setFilters(prev => ({ ...prev, [key]: value }))
    setPage(1)
    setExpandedRound(null)
    setDetail(null)
  }

  const toggleRound = async (roundId) => {
    if (expandedRound === roundId) {
      setExpandedRound(null)
      setDetail(null)
      return
    }
    setExpandedRound(roundId)
    setDetailLoading(true)
    try {
      const d = await api.adminGamesAnalyticsDetail(roundId)
      setDetail(d)
    } catch {
      setDetail(null)
    } finally {
      setDetailLoading(false)
    }
  }

  const rtpByRoom = data?.rtp_by_room ?? {}

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h4 className="mb-0"><i className="bi bi-bar-chart-line me-2"></i>Games Analytics</h4>
        {data && (
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{data.total_rounds} total rounds</span>
        )}
      </div>

      {/* RTP Summary Cards */}
      <div className="row g-3 mb-4">
        <div className="col-md-3">
          <div className="card p-3 text-center">
            <div className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Global RTP</div>
            <div className="fs-4 fw-bold" style={{ color: 'var(--neon-purple)' }}>
              {data ? fmtPct(data.global_rtp) : '—'}
            </div>
          </div>
        </div>
        {['1', '10', '100'].map(room => (
          <div className="col-md-3" key={room}>
            <div className="card p-3 text-center">
              <div className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Room {room} RTP</div>
              <div className="fs-4 fw-bold" style={{ color: 'var(--neon-green)' }}>
                {rtpByRoom[room] != null ? fmtPct(rtpByRoom[room]) : '—'}
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="card p-3 mb-3">
        <div className="row g-2 align-items-end">
          <div className="col-auto">
            <label className="form-label text-xs mb-1">Room</label>
            <select className="form-select form-select-sm" value={filters.room}
              onChange={e => updateFilter('room', e.target.value)}>
              <option value="">All Rooms</option>
              {ROOMS.filter(Boolean).map(r => <option key={r} value={r}>Room {r}</option>)}
            </select>
          </div>
          <div className="col-auto">
            <label className="form-label text-xs mb-1">From</label>
            <input type="date" className="form-control form-control-sm"
              value={filters.date_from} onChange={e => updateFilter('date_from', e.target.value)} />
          </div>
          <div className="col-auto">
            <label className="form-label text-xs mb-1">To</label>
            <input type="date" className="form-control form-control-sm"
              value={filters.date_to} onChange={e => updateFilter('date_to', e.target.value)} />
          </div>
        </div>
      </div>

      {loading && <Spinner fullPage />}
      {error && <div className="alert alert-danger">{error}</div>}

      {!loading && !error && data && (
        <div className="card p-3">
          <div className="table-responsive">
            <table className="table table-hover align-middle">
              <thead>
                <tr>
                  <th>Round ID</th><th>Room</th><th>Total Pot</th><th>Winner</th>
                  <th>Winner Net</th><th>Commission</th><th>Referral Bonus</th>
                  <th>Players</th><th>Finished At</th>
                </tr>
              </thead>
              <tbody>
                {data.rounds.map(r => (
                  <React.Fragment key={r.id}>
                    <tr onClick={() => toggleRound(r.id)} style={{ cursor: 'pointer' }}>
                      <td>{r.id}</td>
                      <td><span className="badge bg-secondary">{r.room}</span></td>
                      <td>{fmt(r.total_pot)}</td>
                      <td>{r.winner_name ?? r.winner_id}</td>
                      <td>{fmt(r.winner_net)}</td>
                      <td>{fmt(r.commission)}</td>
                      <td>{fmt(r.referral_bonus)}</td>
                      <td>{r.player_count}</td>
                      <td>{r.finished_at}</td>
                    </tr>
                    {expandedRound === r.id && (
                      <tr>
                        <td colSpan={9} style={{ background: 'rgba(255,255,255,0.02)' }}>
                          {detailLoading ? <Spinner /> : detail ? (
                            <div className="p-2">
                              <h6 className="mb-2">Bets</h6>
                              <table className="table table-sm mb-3">
                                <thead>
                                  <tr><th>User</th><th>Amount</th><th>Chance</th><th>Client Seed</th></tr>
                                </thead>
                                <tbody>
                                  {detail.bets.map((b, i) => (
                                    <tr key={i}>
                                      <td>{b.display_name ?? b.user_id}</td>
                                      <td>{fmt(b.amount)}</td>
                                      <td>{(parseFloat(b.chance) * 100).toFixed(1)}%</td>
                                      <td style={{ fontFamily: 'monospace', fontSize: '.85rem' }}>{b.client_seed}</td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                              <h6 className="mb-2">Provably Fair</h6>
                              <div className="text-xs" style={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>
                                <div><strong>Server Seed:</strong> {detail.round?.server_seed ?? '—'}</div>
                                <div><strong>Combined Hash:</strong> {detail.round?.final_combined_hash ?? '—'}</div>
                              </div>
                            </div>
                          ) : <span className="text-muted">Failed to load detail</span>}
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                ))}
                {data.rounds.length === 0 && (
                  <tr><td colSpan={9} className="text-center text-muted py-4">No rounds found</td></tr>
                )}
              </tbody>
            </table>
          </div>
          <Pagination page={data.page} totalPages={data.total_pages} onChange={setPage} />
        </div>
      )}
    </>
  )
}
