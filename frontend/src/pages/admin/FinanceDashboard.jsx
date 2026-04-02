import { useState, useEffect, useCallback } from 'react'
import { api } from '../../api/client'
import Spinner from '../../components/ui/Spinner'

const fmt = (v) => '$' + parseFloat(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const metrics = [
  { key: 'total_deposits',       label: 'Total Deposits',       color: 'var(--neon-green)' },
  { key: 'total_withdrawals',    label: 'Total Withdrawals',    color: '#f87171' },
  { key: 'system_profit',        label: 'System Profit',        color: 'var(--neon-purple)' },
  { key: 'net_platform_position',label: 'Net Platform Position', color: '#60a5fa' },
  { key: 'total_bets_volume',    label: 'Total Bets Volume',    color: '#fbbf24' },
  { key: 'total_payouts_volume', label: 'Total Payouts Volume', color: '#34d399' },
]

const checkLabels = {
  no_money_created:    'No Money Created',
  no_money_lost:       'No Money Lost',
  everything_traceable:'Everything Traceable',
}

export default function FinanceDashboard() {
  const [finance, setFinance]       = useState(null)
  const [health, setHealth]         = useState(null)
  const [loading, setLoading]       = useState(true)
  const [healthLoading, setHealthLoading] = useState(true)
  const [error, setError]           = useState(null)
  const [healthError, setHealthError] = useState(null)

  const fetchFinance = useCallback(() => {
    setLoading(true)
    setError(null)
    api.adminFinanceDashboard()
      .then(d => { setFinance(d); setLoading(false) })
      .catch(e => { setError(e.message); setLoading(false) })
  }, [])

  const fetchHealth = useCallback(() => {
    setHealthLoading(true)
    setHealthError(null)
    api.adminHealthCheck()
      .then(d => { setHealth(d); setHealthLoading(false) })
      .catch(e => { setHealthError(e.message); setHealthLoading(false) })
  }, [])

  useEffect(() => { fetchFinance(); fetchHealth() }, [fetchFinance, fetchHealth])

  if (loading && !finance) return <Spinner fullPage />

  const checks = health?.checks ?? {}
  const allPassed = health && Object.values(checks).every(c => c.passed)

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h4 className="mb-0"><i className="bi bi-graph-up me-2"></i>Finance Dashboard</h4>
        <button className="btn btn-sm btn-outline-light" onClick={fetchFinance} disabled={loading}>
          <i className="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}

      {loading && <div className="mb-3"><Spinner /></div>}

      <div className="row g-3 mb-4">
        {metrics.map(m => (
          <div className="col-md-4" key={m.key}>
            <div className="card p-3 text-center">
              <div className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{m.label}</div>
              <div className="fs-4 fw-bold" style={{ color: m.color }}>
                {fmt(finance?.[m.key])}
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Platform Health */}
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h5 className="mb-0"><i className="bi bi-shield-check me-2"></i>Platform Health</h5>
        <button className="btn btn-sm btn-outline-light" onClick={fetchHealth} disabled={healthLoading}>
          <i className="bi bi-arrow-clockwise me-1"></i>Re-check
        </button>
      </div>

      {healthError && <div className="alert alert-danger">{healthError}</div>}
      {healthLoading && !health && <Spinner />}

      {health && (
        <div className="card p-3">
          <div className="mb-3">
            {allPassed
              ? <span className="badge bg-success px-3 py-2">All Systems OK</span>
              : <span className="badge bg-danger px-3 py-2">Integrity Issue Detected</span>}
          </div>
          <div className="row g-3">
            {Object.entries(checkLabels).map(([key, label]) => {
              const check = checks[key]
              const passed = check?.passed
              return (
                <div className="col-md-4" key={key}>
                  <div className="card p-3 text-center">
                    <div className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{label}</div>
                    <div className="fs-5 fw-bold" style={{ color: passed ? 'var(--neon-green)' : '#f87171' }}>
                      <i className={`bi ${passed ? 'bi-check-circle-fill' : 'bi-x-circle-fill'} me-1`}></i>
                      {passed ? 'Pass' : 'Fail'}
                    </div>
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      )}
    </>
  )
}
