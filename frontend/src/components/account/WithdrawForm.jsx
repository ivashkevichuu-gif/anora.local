import React, { useState } from 'react'
import { useAsync } from '../../hooks/useAsync'
import { accountService } from '../../services/accountService'
import StatusMessage from '../ui/StatusMessage'

export default function WithdrawForm({ balance, bankDetails }) {
  const [form, setForm] = useState({ amount: '', bank: bankDetails || '' })
  const { execute, loading, error, success, reset } = useAsync(
    (amount, bank) => accountService.withdraw(amount, bank)
  )

  const handle = e => setForm(f => ({ ...f, [e.target.name]: e.target.value }))

  const submit = async e => {
    e.preventDefault()
    reset()
    if (parseFloat(form.amount) > parseFloat(balance ?? 0)) {
      // surface as a local error without calling the API
      return
    }
    try {
      await execute(form.amount, form.bank)
      setForm(f => ({ ...f, amount: '' }))
    } catch { /* error shown via StatusMessage */ }
  }

  const available = parseFloat(balance ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2 })

  return (
    <div className="card p-4" style={{ maxWidth: 480 }}>
      <h5 className="mb-3">Request Withdrawal</h5>
      <p className="text-muted small">Available: <strong>${available}</strong></p>
      <StatusMessage error={error} success={success} />
      <form onSubmit={submit}>
        <div className="mb-3">
          <label className="form-label">Amount ($)</label>
          <input type="number" name="amount" className="form-control"
            min="0.01" step="0.01" max={balance ?? 0} required
            value={form.amount} onChange={handle} />
        </div>
        <div className="mb-3">
          <label className="form-label">Bank Details</label>
          <textarea name="bank" className="form-control" rows={3}
            placeholder="Account number, routing number, bank name…"
            value={form.bank} onChange={handle} />
        </div>
        <button type="submit" className="btn btn-warning w-100" disabled={loading}>
          {loading && <span className="spinner-border spinner-border-sm me-2" />}
          Request Withdrawal
        </button>
      </form>
    </div>
  )
}
