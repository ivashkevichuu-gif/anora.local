import { useState, useEffect } from 'react'
import { api } from '../../api/client'
import Spinner from '../ui/Spinner'

const PERIODS = [
  { id: 'day',   label: 'Day' },
  { id: 'month', label: 'Month' },
  { id: 'year',  label: 'Year' },
  { id: 'all',   label: 'All Time' },
]

const ROOM_COLORS = { 1: '#7c3aed', 10: '#3b82f6', 100: '#f59e0b' }

// ── Simple SVG bar chart ─────────────────────────────────────────────────────
function BarChart({ data, color, label, valuePrefix = '' }) {
  if (!data?.length) return <div className="text-center py-4" style={{ color: 'var(--text-muted)', fontSize: '.85rem' }}>No data</div>

  const maxVal = Math.max(...data.map(d => d.value), 1)
  const barW = Math.max(12, Math.min(40, Math.floor(280 / data.length) - 4))

  return (
    <div>
      <div className="text-xs mb-2 font-semibold" style={{ color: 'var(--text-muted)' }}>{label}</div>
      <div className="d-flex align-items-end gap-1 overflow-x-auto pb-2" style={{ height: 140, scrollbarWidth: 'thin' }}>
        {data.map((d, i) => {
          const h = Math.max(4, (d.value / maxVal) * 110)
          return (
            <div key={i} className="d-flex flex-column align-items-center flex-shrink-0" style={{ width: barW }}>
              <div className="text-center mb-1" style={{ fontSize: 9, color: 'var(--text-muted)' }}>
                {valuePrefix}{d.value.toFixed(d.value % 1 ? 2 : 0)}
              </div>
              <div style={{
                width: barW - 2, height: h, borderRadius: 4,
                background: color, opacity: 0.85,
                transition: 'height 0.3s ease',
              }} />
              <div className="text-center mt-1" style={{ fontSize: 8, color: 'var(--text-muted)', maxWidth: barW, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                {d.label}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}

// ── Stat card ────────────────────────────────────────────────────────────────
function StatCard({ icon, label, value, color, sub }) {
  return (
    <div className="card p-3 text-center" style={{ background: `${color}10`, border: `1px solid ${color}30`, flex: 1, minWidth: 130 }}>
      <div className="mb-1"><i className={`bi ${icon}`} style={{ color, fontSize: '1.1rem' }} /></div>
      <div className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{label}</div>
      <div className="fs-5 fw-bold" style={{ color }}>{value}</div>
      {sub && <div className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{sub}</div>}
    </div>
  )
}

// ── Main component ───────────────────────────────────────────────────────────
export default function PlayerStats() {
  const [period, setPeriod]   = useState('all')
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)

  useEffect(() => {
    setLoading(true)
    setError(null)
    api.playerStats(period)
      .then(d => setData(d))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false))
  }, [period])

  if (loading && !data) return <Spinner />
  if (error) return <div className="alert alert-danger">{error}</div>
  if (!data) return null

  const s = data.summary

  // Prepare chart data
  const betsChart = (data.chart_bets ?? []).map(r => ({
    label: r.period_key?.split(' ')[0]?.slice(5) || r.period_key,
    value: parseFloat(r.total_bets) || 0,
  }))

  const winsChart = (data.chart_wins ?? []).map(r => ({
    label: r.period_key?.split(' ')[0]?.slice(5) || r.period_key,
    value: parseFloat(r.total_wins) || 0,
  }))

  // Games per room — merge into per-period with room breakdown
  const gamesMap = {}
  for (const r of (data.chart_games ?? [])) {
    if (!gamesMap[r.period_key]) gamesMap[r.period_key] = { label: r.period_key?.split(' ')[0]?.slice(5) || r.period_key, value: 0 }
    gamesMap[r.period_key].value += parseInt(r.games) || 0
  }
  const gamesChart = Object.values(gamesMap)

  const profitColor = s.net_profit >= 0 ? '#00ff88' : '#ef4444'

  return (
    <div>
      {/* Period selector */}
      <div className="d-flex gap-2 mb-4">
        {PERIODS.map(p => (
          <button
            key={p.id}
            className="btn btn-sm"
            onClick={() => setPeriod(p.id)}
            style={{
              background: period === p.id ? 'rgba(124,58,237,0.3)' : 'rgba(255,255,255,0.05)',
              border: `1px solid ${period === p.id ? 'rgba(124,58,237,0.5)' : 'rgba(255,255,255,0.1)'}`,
              color: period === p.id ? 'var(--neon-purple)' : 'var(--text-muted)',
            }}
          >
            {p.label}
          </button>
        ))}
        {loading && <span className="spinner-border spinner-border-sm ms-2" style={{ color: 'var(--text-muted)' }} />}
      </div>

      {/* Summary cards */}
      <div className="d-flex flex-wrap gap-3 mb-4">
        <StatCard icon="bi-controller" label="Games Played" value={s.total_games} color="#7c3aed" />
        <StatCard icon="bi-coin" label="Total Bets" value={`$${s.total_bets.toFixed(2)}`} color="#3b82f6" />
        <StatCard icon="bi-trophy" label="Total Wins" value={`$${s.total_wins.toFixed(2)}`} color="#f59e0b" sub={`${s.win_count} wins · ${s.win_rate}% rate`} />
        <StatCard icon="bi-graph-up-arrow" label="Net Profit" value={`${s.net_profit >= 0 ? '+' : ''}$${s.net_profit.toFixed(2)}`} color={profitColor} />
      </div>

      {/* Room breakdown */}
      {data.rooms?.length > 0 && (
        <div className="card p-3 mb-4">
          <div className="text-xs font-semibold mb-3" style={{ color: 'var(--text-muted)' }}>Games by Room</div>
          <div className="d-flex gap-3 flex-wrap">
            {data.rooms.map(r => (
              <div key={r.room} className="d-flex align-items-center gap-2 px-3 py-2 rounded-lg"
                style={{ background: `${ROOM_COLORS[r.room]}15`, border: `1px solid ${ROOM_COLORS[r.room]}30` }}>
                <span className="fw-bold" style={{ color: ROOM_COLORS[r.room] }}>${r.room}</span>
                <span style={{ color: 'var(--text-muted)', fontSize: '.85rem' }}>
                  {r.games} games · ${parseFloat(r.total_staked).toFixed(2)} staked
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Charts */}
      <div className="row g-3">
        <div className="col-md-4">
          <div className="card p-3">
            <BarChart data={gamesChart} color="#7c3aed" label="Games Played" />
          </div>
        </div>
        <div className="col-md-4">
          <div className="card p-3">
            <BarChart data={betsChart} color="#3b82f6" label="Bets ($)" valuePrefix="$" />
          </div>
        </div>
        <div className="col-md-4">
          <div className="card p-3">
            <BarChart data={winsChart} color="#f59e0b" label="Wins ($)" valuePrefix="$" />
          </div>
        </div>
      </div>
    </div>
  )
}
