import React, { useState } from 'react'
import { useAsync } from '../../hooks/useAsync'
import { accountService } from '../../services/accountService'
import StatusMessage from '../ui/StatusMessage'

export default function DepositForm({ onSuccess }) {
  const [form, setForm] = useState({ amount: '', card: '', expiry: '', cvv: '' })
  const { execute, loading, error, success, reset } = useAsync(
    (amount) => accountService.deposit(amount)
  )

  const handle = e => {
    let { name, value } = e.target
    if (name === 'card')   value = value.replace(/\D/g, '').replace(/(.{4})/g, '$1 ').trim().slice(0, 19)
    if (name === 'expiry') value = value.replace(/\D/g, '').replace(/^(\d{2})(\d)/, '$1/$2').slice(0, 5)
    setForm(f => ({ ...f, [name]: value }))
  }

  const submit = async e => {
    e.preventDefault()
    reset()
    try {
      const d = await execute(form.amount)
      onSuccess(d.balance)
      setForm({ amount: '', card: '', expiry: '', cvv: '' })
    } catch { /* error shown via StatusMessage */ }
  }

  return (
    <div className="card p-4" style={{ maxWidth: 480 }}>
      <h5 className="mb-3">Deposit Funds</h5>
      <StatusMessage error={error} success={success} />
      <form onSubmit={submit}>
        <div className="mb-3">
          <label className="form-label">Amount ($)</label>
          <input type="number" name="amount" className="form-control"
            min="0.01" step="0.01" required value={form.amount} onChange={handle} />
        </div>
        <div className="mb-3">
          <label className="form-label">Card Number</label>
          <input type="text" name="card" className="form-control"
            placeholder="1234 5678 9012 3456" maxLength={19} required
            value={form.card} onChange={handle} />
        </div>
        <div className="row g-2 mb-3">
          <div className="col">
            <label className="form-label">Expiry</label>
            <input type="text" name="expiry" className="form-control"
              placeholder="MM/YY" maxLength={5} required value={form.expiry} onChange={handle} />
          </div>
          <div className="col">
            <label className="form-label">CVV</label>
            <input type="password" name="cvv" className="form-control"
              placeholder="•••" maxLength={4} required value={form.cvv} onChange={handle} />
          </div>
        </div>
        <button type="submit" className="btn btn-primary w-100" disabled={loading}>
          {loading && <span className="spinner-border spinner-border-sm me-2" />}
          Deposit
        </button>
      </form>
    </div>
  )
}
