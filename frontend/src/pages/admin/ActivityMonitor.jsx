import { useState } from 'react'
import { useFetch } from '../../hooks/useFetch'
import { api } from '../../api/client'
import Spinner from '../../components/ui/Spinner'

const FLAG_COLORS = {
  win_streak:       'bg-danger',
  high_velocity:    'bg-warning text-dark',
  ip_correlation:   'bg-warning text-dark',
  large_withdrawal: 'bg-info text-dark',
}

// More specific orange for high_velocity vs yellow for ip_correlation
const FLAG_STYLES = {
  win_streak:       { background: '#dc3545', color: '#fff' },
  high_velocity:    { background: '#fd7e14', color: '#fff' },
  ip_correlation:   { background: '#ffc107', color: '#000' },
  large_withdrawal: { background: '#0d6efd', color: '#fff' },
}

export default function ActivityMonitor() {
  const { data, loading, error } = useFetch(api.adminActivityMonitor)
  const [dismissed, setDismissed] = useState(new Set())
  const [banning, setBanning] = useState(new Set())

  const flags = (data?.flags ?? []).filter((_, i) => !dismissed.has(i))

  const dismiss = (index) => {
    setDismissed(prev => new Set(prev).add(index))
  }

  const banUser = async (userId, index) => {
    setBanning(prev => new Set(prev).add(index))
    try {
      await api.adminAction({ action: 'ban', id: userId })
      setDismissed(prev => new Set(prev).add(index))
    } catch (e) {
      alert('Ban failed: ' + e.message)
    } finally {
      setBanning(prev => { const s = new Set(prev); s.delete(index); return s })
    }
  }

  if (loading) return <Spinner fullPage />
  if (error)   return <div className="alert alert-danger">{error}</div>

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h4 className="mb-0"><i className="bi bi-shield-exclamation me-2"></i>Activity Monitor</h4>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{flags.length} flags</span>
      </div>
      <div className="card p-3">
        <div className="table-responsive">
          <table className="table table-hover align-middle">
            <thead>
              <tr>
                <th>User</th><th>Email</th><th>Flag</th><th>Details</th><th>Time</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {(data?.flags ?? []).map((f, i) => {
                if (dismissed.has(i)) return null
                return (
                  <tr key={i}>
                    <td>{f.user_id}</td>
                    <td>{f.email}</td>
                    <td>
                      <span className="badge" style={FLAG_STYLES[f.flag_type] || { background: '#6c757d', color: '#fff' }}>
                        {f.flag_type}
                      </span>
                    </td>
                    <td className="small">{f.details}</td>
                    <td>{f.timestamp}</td>
                    <td>
                      <div className="d-flex gap-1">
                        <button className="btn btn-sm btn-outline-secondary" onClick={() => dismiss(i)}>
                          Dismiss
                        </button>
                        <button
                          className="btn btn-sm btn-danger"
                          disabled={banning.has(i)}
                          onClick={() => banUser(f.user_id, i)}
                        >
                          {banning.has(i) ? 'Banning…' : 'Ban User'}
                        </button>
                      </div>
                    </td>
                  </tr>
                )
              })}
              {flags.length === 0 && (
                <tr><td colSpan={6} className="text-center text-muted py-4">No suspicious activity detected</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </>
  )
}
