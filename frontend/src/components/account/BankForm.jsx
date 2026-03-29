import React, { useState } from 'react'
import { useAsync } from '../../hooks/useAsync'
import { accountService } from '../../services/accountService'
import StatusMessage from '../ui/StatusMessage'

export default function BankForm({ bankDetails, onSave }) {
  const [value, setValue] = useState(bankDetails || '')
  const { execute, loading, error, success, reset } = useAsync(
    (details) => accountService.saveBank(details)
  )

  const submit = async e => {
    e.preventDefault()
    reset()
    try {
      await execute(value)
      onSave(value)
    } catch { /* error shown via StatusMessage */ }
  }

  return (
    <div className="card p-4" style={{ maxWidth: 480 }}>
      <h5 className="mb-3">Bank Details</h5>
      <p className="text-muted small">Saved details will be pre-filled in withdrawal requests.</p>
      <StatusMessage error={error} success={success} />
      <form onSubmit={submit}>
        <div className="mb-3">
          <label className="form-label">Bank Requisites</label>
          <textarea className="form-control" rows={5}
            placeholder="Account number, routing number, bank name, SWIFT/IBAN…"
            value={value} onChange={e => setValue(e.target.value)} />
        </div>
        <button type="submit" className="btn btn-secondary w-100" disabled={loading}>
          {loading && <span className="spinner-border spinner-border-sm me-2" />}
          Save
        </button>
      </form>
    </div>
  )
}
