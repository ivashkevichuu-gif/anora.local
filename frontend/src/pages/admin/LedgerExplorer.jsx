import { useState, useEffect, useCallback } from 'react'
import { api } from '../../api/client'
import Spinner from '../../components/ui/Spinner'
import Pagination from '../../components/ui/Pagination'

const LEDGER_TYPES = [
  '', 'deposit', 'bet', 'win', 'system_fee', 'referral_bonus',
  'withdrawal', 'crypto_deposit', 'crypto_withdrawal', 'crypto_withdrawal_refund',
]

export default function LedgerExplorer() {
  const [filters, setFilters] = useState({
    user_id: '', email: '', type: '', date_from: '', date_to: '',
    reference_type: '', reference_id: '',
  })
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const fetchData = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const params = { page, per_page: 50 }
      Object.entries(filters).forEach(([k, v]) => { if (v) params[k] = v })
      const result = await api.adminLedger(params)
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
  }

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h4 className="mb-0"><i className="bi bi-journal-text me-2"></i>Ledger Explorer</h4>
        {data && (
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{data.total_count} total</span>
        )}
      </div>

      <div className="card p-3 mb-3">
        <div className="row g-2 align-items-end">
          <div className="col-auto">
            <label className="form-label text-xs mb-1">User ID / Email</label>
            <input
              type="text" className="form-control form-control-sm"
              placeholder="ID or email"
              value={filters.user_id || filters.email}
              onChange={e => {
                const v = e.target.value
                if (/^\d*$/.test(v)) {
                  updateFilter('user_id', v)
                  updateFilter('email', '')
                } else {
                  updateFilter('email', v)
                  updateFilter('user_id', '')
                }
              }}
            />
          </div>
          <div className="col-auto">
            <label className="form-label text-xs mb-1">Type</label>
            <select className="form-select form-select-sm" value={filters.type}
              onChange={e => updateFilter('type', e.target.value)}>
              <option value="">All types</option>
              {LEDGER_TYPES.filter(Boolean).map(t => <option key={t} value={t}>{t}</option>)}
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
          <div className="col-auto">
            <label className="form-label text-xs mb-1">Ref Type</label>
            <input type="text" className="form-control form-control-sm" placeholder="reference_type"
              value={filters.reference_type} onChange={e => updateFilter('reference_type', e.target.value)} />
          </div>
          <div className="col-auto">
            <label className="form-label text-xs mb-1">Ref ID</label>
            <input type="text" className="form-control form-control-sm" placeholder="reference_id"
              value={filters.reference_id} onChange={e => updateFilter('reference_id', e.target.value)} />
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
                  <th>#</th><th>User</th><th>Email</th><th>Type</th><th>Amount</th>
                  <th>Direction</th><th>Balance After</th><th>Ref ID</th><th>Ref Type</th><th>Date</th>
                </tr>
              </thead>
              <tbody>
                {data.entries.map(e => (
                  <tr key={e.id}>
                    <td>{e.id}</td>
                    <td>{e.user_id}</td>
                    <td>{e.email}</td>
                    <td><span className="badge bg-secondary">{e.type}</span></td>
                    <td>${parseFloat(e.amount).toFixed(2)}</td>
                    <td>
                      <span className={`badge ${e.direction === 'credit' ? 'bg-success' : 'bg-danger'}`}>
                        {e.direction}
                      </span>
                    </td>
                    <td>${parseFloat(e.balance_after).toFixed(2)}</td>
                    <td style={{ fontFamily: 'monospace', fontSize: '.85rem' }}>{e.reference_id ?? '—'}</td>
                    <td>{e.reference_type ?? '—'}</td>
                    <td>{e.created_at}</td>
                  </tr>
                ))}
                {data.entries.length === 0 && (
                  <tr><td colSpan={10} className="text-center text-muted py-4">No entries found</td></tr>
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
