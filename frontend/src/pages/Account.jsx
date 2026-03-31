import React, { useState, useEffect } from 'react'
import { useAuth } from '../context/AuthContext'
import DepositForm  from '../components/account/DepositForm'
import WithdrawForm from '../components/account/WithdrawForm'
import BankForm     from '../components/account/BankForm'
import Pagination   from '../components/ui/Pagination'
import Spinner      from '../components/ui/Spinner'
import { api }      from '../api/client'

const TABS = [
  { id: 'deposit',  icon: 'plus-circle',    label: 'Deposit' },
  { id: 'withdraw', icon: 'arrow-up-circle', label: 'Withdraw' },
  { id: 'bank',     icon: 'building',        label: 'Bank Details' },
  { id: 'history',  icon: 'clock-history',   label: 'History' },
  { id: 'referral', icon: 'people',          label: 'Referral' },
]

function ReferralDashboard() {
  const [referralData, setReferralData] = useState(null)
  const [loading, setLoading]           = useState(true)
  const [error, setError]               = useState(null)
  const [copied, setCopied]             = useState(false)

  useEffect(() => {
    api.me()
      .then(d => setReferralData(d.user))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <Spinner />
  if (error)   return <div className="alert alert-danger">{error}</div>

  const refLink = `https://anora.bet/?ref=${referralData.ref_code || ''}`

  const handleCopy = () => {
    navigator.clipboard.writeText(refLink).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    })
  }

  return (
    <div className="card p-4">
      <h5 className="mb-4">Referral Dashboard</h5>

      <div className="row g-3 mb-4">
        <div className="col-sm-6">
          <div className="card p-3 text-center" style={{ background: 'rgba(124,58,237,0.1)', border: '1px solid rgba(124,58,237,0.3)' }}>
            <div className="text-white-50 small mb-1">Referral Earnings</div>
            <div className="fs-4 fw-bold" style={{ color: 'var(--neon-purple)' }}>
              ${parseFloat(referralData.referral_earnings ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}
            </div>
          </div>
        </div>
        <div className="col-sm-6">
          <div className="card p-3 text-center" style={{ background: 'rgba(124,58,237,0.1)', border: '1px solid rgba(124,58,237,0.3)' }}>
            <div className="text-white-50 small mb-1">Users Referred</div>
            <div className="fs-4 fw-bold" style={{ color: 'var(--neon-purple)' }}>
              {referralData.referred_count ?? 0}
            </div>
          </div>
        </div>
      </div>

      <div className="mb-2 text-white-50 small">Your Referral Link</div>
      <div className="d-flex gap-2 align-items-center flex-wrap">
        <input
          readOnly
          value={refLink}
          className="form-control"
          style={{ background: 'rgba(255,255,255,0.05)', color: '#fff', border: '1px solid rgba(255,255,255,0.15)', flex: 1 }}
        />
        <button
          className="btn btn-sm"
          onClick={handleCopy}
          style={{
            background: copied ? 'rgba(34,197,94,0.2)' : 'rgba(124,58,237,0.3)',
            border: copied ? '1px solid rgba(34,197,94,0.5)' : '1px solid rgba(124,58,237,0.5)',
            color: copied ? '#4ade80' : 'var(--neon-purple)',
            whiteSpace: 'nowrap',
            minWidth: 90,
          }}
        >
          <i className={`bi bi-${copied ? 'check2' : 'clipboard'} me-1`}></i>
          {copied ? 'Copied!' : 'Copy'}
        </button>
      </div>
    </div>
  )
}

function UserTransactions() {
  const [page, setPage]           = useState(1)
  const [data, setData]           = useState(null)
  const [loading, setLoading]     = useState(true)
  const [error, setError]         = useState(null)

  useEffect(() => {
    setLoading(true)
    api.userTransactions(page)
      .then(d => setData(d))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false))
  }, [page])

  if (loading) return <Spinner />
  if (error)   return <div className="alert alert-danger">{error}</div>

  const txs        = data?.transactions ?? []
  const totalPages = data?.total_pages ?? 1

  return (
    <div className="card p-3">
      <h5 className="mb-3">Transaction History</h5>
      {txs.length === 0 ? (
        <p className="text-muted">No transactions yet.</p>
      ) : (
        <>
          <div className="table-responsive">
            <table className="table table-hover align-middle">
              <thead>
                <tr>
                  <th>Type</th>
                  <th>Amount</th>
                  <th>Game ID</th>
                  <th>Payout ID</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                {txs.map((tx, i) => (
                  <tr key={i}>
                    <td>
                      <span className="badge" style={{
                        background: tx.type === 'win' ? 'rgba(34,197,94,0.2)' :
                                    tx.type === 'referral_bonus' ? 'rgba(124,58,237,0.2)' :
                                    'rgba(239,68,68,0.2)',
                        color: tx.type === 'win' ? '#4ade80' :
                               tx.type === 'referral_bonus' ? 'var(--neon-purple)' :
                               '#f87171',
                        border: '1px solid currentColor',
                      }}>
                        {tx.type}
                      </span>
                    </td>
                    <td>${parseFloat(tx.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
                    <td>{tx.game_id ?? '—'}</td>
                    <td>
                      {tx.payout_id
                        ? <span className="text-muted small" title={tx.payout_id}>{tx.payout_id.slice(0, 8)}…</span>
                        : '—'}
                    </td>
                    <td className="text-muted small">{tx.created_at}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination page={page} totalPages={totalPages} onChange={setPage} />
        </>
      )}
    </div>
  )
}

export default function Account() {
  const { user, setUser } = useAuth()
  const [tab, setTab]     = useState('deposit')

  const updateBalance = newBalance => setUser(u => ({ ...u, balance: newBalance }))

  return (
    <div className="container">
      {/* Balance card */}
      <div className="balance-card card p-4 mb-4">
        <div className="d-flex align-items-center justify-content-between flex-wrap gap-3">
          <div>
            <div className="text-white-50 small">Current Balance</div>
            <div className="amount">${parseFloat(user.balance ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}</div>
          </div>
          <i className="bi bi-wallet2 fs-1 opacity-50 text-white"></i>
        </div>
        <div className="mt-2 text-white-50 small">{user.email}</div>
      </div>

      {/* Tabs */}
      <ul className="nav nav-tabs mb-4">
        {TABS.map(t => (
          <li className="nav-item" key={t.id}>
            <button
              className={`nav-link ${tab === t.id ? 'active' : ''}`}
              onClick={() => setTab(t.id)}
            >
              <i className={`bi bi-${t.icon} me-1`}></i>{t.label}
            </button>
          </li>
        ))}
      </ul>

      <div>
        {tab === 'deposit'  && <DepositForm  onSuccess={updateBalance} />}
        {tab === 'withdraw' && <WithdrawForm balance={user.balance} bankDetails={user.bank_details} />}
        {tab === 'bank'     && <BankForm     bankDetails={user.bank_details} onSave={bd => setUser(u => ({ ...u, bank_details: bd }))} />}
        {tab === 'history'  && <UserTransactions />}
        {tab === 'referral' && <ReferralDashboard />}
      </div>
    </div>
  )
}
