import React from 'react'
import { useFetch } from '../../hooks/useFetch'
import { accountService } from '../../services/accountService'
import { TypeBadge, StatusBadge } from '../ui/StatusBadge'
import Spinner from '../ui/Spinner'

export default function TxHistory() {
  const { data, loading, error } = useFetch(accountService.getTransactions)

  if (loading) return <Spinner fullPage />
  if (error)   return <div className="alert alert-danger">{error}</div>

  const txs = data?.transactions ?? []

  return (
    <div className="card p-3">
      <h5 className="mb-3">Transaction History</h5>
      {txs.length === 0 ? (
        <p className="text-muted">No transactions yet.</p>
      ) : (
        <div className="table-responsive">
          <table className="table table-hover align-middle">
            <thead>
              <tr><th>#</th><th>Type</th><th>Amount</th><th>Status</th><th>Note</th><th>Date</th></tr>
            </thead>
            <tbody>
              {txs.map(tx => (
                <tr key={tx.id}>
                  <td>{tx.id}</td>
                  <td><TypeBadge type={tx.type} /></td>
                  <td>${parseFloat(tx.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                  <td><StatusBadge status={tx.status} /></td>
                  <td>{tx.note || '—'}</td>
                  <td>{tx.created_at}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
