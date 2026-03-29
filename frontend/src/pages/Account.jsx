import React, { useState } from 'react'
import { useAuth } from '../context/AuthContext'
import DepositForm  from '../components/account/DepositForm'
import WithdrawForm from '../components/account/WithdrawForm'
import BankForm     from '../components/account/BankForm'
import TxHistory    from '../components/account/TxHistory'

const TABS = [
  { id: 'deposit',  icon: 'plus-circle',    label: 'Deposit' },
  { id: 'withdraw', icon: 'arrow-up-circle', label: 'Withdraw' },
  { id: 'bank',     icon: 'building',        label: 'Bank Details' },
  { id: 'history',  icon: 'clock-history',   label: 'History' },
]

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
        {tab === 'history'  && <TxHistory />}
      </div>
    </div>
  )
}
