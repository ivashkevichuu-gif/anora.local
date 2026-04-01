import React, { useState, useEffect } from 'react'
import { useAuth } from '../context/AuthContext'
import DepositForm        from '../components/account/DepositForm'
import WithdrawForm      from '../components/account/WithdrawForm'
import BankForm          from '../components/account/BankForm'
import CryptoDepositForm from '../components/account/CryptoDepositForm'
import CryptoWithdrawForm from '../components/account/CryptoWithdrawForm'
import PlayerStats       from '../components/account/PlayerStats'
import Pagination   from '../components/ui/Pagination'
import Spinner      from '../components/ui/Spinner'
import { api }      from '../api/client'

const TABS = [
  { id: 'profile',        icon: 'person-circle',    label: 'Profile' },
  // { id: 'deposit',        icon: 'plus-circle',      label: 'Deposit' },
  // { id: 'withdraw',       icon: 'arrow-up-circle',  label: 'Withdraw' },
  { id: 'crypto-deposit', icon: 'currency-bitcoin', label: 'Crypto Deposit' },
  { id: 'crypto-withdraw',icon: 'wallet2',          label: 'Crypto Withdraw' },
  // { id: 'bank',           icon: 'building',         label: 'Bank Details' },
  { id: 'stats',          icon: 'bar-chart-fill',   label: 'Statistics' },
  { id: 'history',        icon: 'clock-history',    label: 'History' },
  { id: 'referral',       icon: 'people',           label: 'Referral' },
]

// ── Profile tab ───────────────────────────────────────────────────────────────
function ProfileTab({ user, onNicknameUpdate }) {
  const [nickname, setNickname]   = useState(user.nickname ?? '')
  const [saving, setSaving]       = useState(false)
  const [error, setError]         = useState(null)
  const [success, setSuccess]     = useState(null)

  // Sync if user object updates
  useEffect(() => { setNickname(user.nickname ?? '') }, [user.nickname])

  const canChange = user.can_change_nickname !== false

  // Time until next change
  let nextChangeLabel = null
  if (!canChange && user.nickname_changed_at) {
    const since = (Date.now() / 1000) - new Date(user.nickname_changed_at).getTime() / 1000
    const hoursLeft = Math.ceil((86400 - since) / 3600)
    nextChangeLabel = `Available in ~${hoursLeft}h`
  }

  const handleSave = async () => {
    setError(null)
    setSuccess(null)
    setSaving(true)
    try {
      const d = await api.updateNickname({ nickname })
      setSuccess(d.message)
      onNicknameUpdate(d.nickname)
    } catch (e) {
      setError(e.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="card p-4" style={{ maxWidth: 520 }}>
      <h5 className="mb-4" style={{ color: 'var(--text)' }}>
        <i className="bi bi-person-circle me-2" style={{ color: 'var(--accent)' }}></i>
        Personal Information
      </h5>

      {/* Email — read-only */}
      <div className="mb-4">
        <label className="form-label">Email address</label>
        <div className="d-flex align-items-center gap-2">
          <input
            readOnly
            value={user.email}
            className="form-control"
            style={{ background: 'rgba(255,255,255,0.03)', color: 'var(--text-muted)', cursor: 'default' }}
          />
          <span className="badge" style={{ background: 'rgba(255,255,255,0.08)', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
            <i className="bi bi-lock-fill me-1"></i>Cannot change
          </span>
        </div>
        <div className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
          Your email address is permanent and cannot be changed.
        </div>
      </div>

      {/* Nickname */}
      <div className="mb-3">
        <label className="form-label">
          Nickname
          <span className="ms-2 text-xs" style={{ color: 'var(--text-muted)', fontWeight: 400 }}>
            Displayed in the game instead of your email
          </span>
        </label>
        <input
          type="text"
          className="form-control"
          value={nickname}
          onChange={e => setNickname(e.target.value)}
          disabled={!canChange || saving}
          maxLength={32}
          placeholder="Your nickname"
        />
        <div className="mt-1 d-flex justify-content-between">
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
            3–32 characters · letters, numbers, spaces, hyphens, underscores
          </span>
          <span className="text-xs" style={{ color: canChange ? 'var(--neon-green)' : '#f59e0b' }}>
            {canChange ? 'Can change now' : nextChangeLabel}
          </span>
        </div>
      </div>

      {error   && <div className="alert alert-danger py-2 mb-3">{error}</div>}
      {success && <div className="alert alert-success py-2 mb-3">{success}</div>}

      <button
        className="btn btn-accent w-100"
        onClick={handleSave}
        disabled={!canChange || saving || nickname.trim() === (user.nickname ?? '')}
      >
        {saving
          ? <><span className="spinner-border spinner-border-sm me-2" />Saving…</>
          : 'Save Nickname'}
      </button>

      {!canChange && (
        <div className="mt-2 text-center text-xs" style={{ color: 'var(--text-muted)' }}>
          <i className="bi bi-clock me-1"></i>
          You can change your nickname once per day. {nextChangeLabel}
        </div>
      )}
    </div>
  )
}

// ── Referral tab ──────────────────────────────────────────────────────────────
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
      <h5 className="mb-4" style={{ color: 'var(--text)' }}>
        <i className="bi bi-people me-2" style={{ color: 'var(--accent)' }}></i>
        Referral Dashboard
        </h5>

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

// ── History tab ───────────────────────────────────────────────────────────────
function UserTransactions() {
  const [page, setPage]       = useState(1)
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)

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
      <h5 className="mb-3" style={{ color: 'var(--text)' }}>
        <i className="bi bi-clock-history me-2" style={{ color: 'var(--accent)' }}></i>
        Transaction History
        </h5>
      {txs.length === 0 ? (
        <p className="text-muted">No transactions yet.</p>
      ) : (
        <>
          <div className="table-responsive">
            <table className="table table-hover align-middle">
              <thead>
                <tr><th>Type</th><th>Amount</th><th>Game ID</th><th>Payout ID</th><th>Note</th><th>Date</th></tr>
              </thead>
              <tbody>
                {txs.map((tx, i) => (
                  <tr key={i}>
                    <td>
                      <span className="badge" style={{
                        background: tx.type === 'win' ? 'rgba(34,197,94,0.2)' :
                                    tx.type === 'referral_bonus' ? 'rgba(124,58,237,0.2)' :
                                    tx.type === 'crypto_deposit' ? 'rgba(249,115,22,0.2)' :
                                    tx.type === 'crypto_withdrawal' ? 'rgba(59,130,246,0.2)' :
                                    tx.type === 'crypto_withdrawal_refund' ? 'rgba(234,179,8,0.2)' :
                                    'rgba(239,68,68,0.2)',
                        color: tx.type === 'win' ? '#4ade80' :
                               tx.type === 'referral_bonus' ? 'var(--neon-purple)' :
                               tx.type === 'crypto_deposit' ? '#fb923c' :
                               tx.type === 'crypto_withdrawal' ? '#60a5fa' :
                               tx.type === 'crypto_withdrawal_refund' ? '#facc15' :
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
                    <td>
                      {tx.note
                        ? <span className="text-muted small" title={tx.note}>{tx.note.length > 20 ? tx.note.slice(0, 20) + '…' : tx.note}</span>
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

// ── Main Account page ─────────────────────────────────────────────────────────
export default function Account() {
  const { user, setUser } = useAuth()
  const [tab, setTab]     = useState(() => localStorage.getItem('anora_account_tab') || 'profile')

  const changeTab = (id) => {
    setTab(id)
    localStorage.setItem('anora_account_tab', id)
  }

  const updateBalance  = newBalance => setUser(u => ({ ...u, balance: newBalance }))
  const updateNickname = newNick    => setUser(u => ({ ...u, nickname: newNick }))

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
        <div className="mt-2 text-white-50 small">
          {user.nickname
            ? <><span className="fw-semibold text-white">{user.nickname}</span> · {user.email}</>
            : user.email}
        </div>
      </div>

      {/* Tabs */}
      <ul className="nav nav-tabs mb-4">
        {TABS.map(t => (
          <li className="nav-item" key={t.id}>
            <button
              className={`nav-link ${tab === t.id ? 'active' : ''}`}
              onClick={() => changeTab(t.id)}
            >
              <i className={`bi bi-${t.icon} me-1`}></i>{t.label}
            </button>
          </li>
        ))}
      </ul>

      <div>
        {tab === 'profile'  && <ProfileTab user={user} onNicknameUpdate={updateNickname} />}
        {tab === 'deposit'  && <DepositForm  onSuccess={updateBalance} />}
        {tab === 'withdraw' && <WithdrawForm balance={user.balance} bankDetails={user.bank_details} />}
        {tab === 'crypto-deposit'  && <CryptoDepositForm onSuccess={updateBalance} />}
        {tab === 'crypto-withdraw' && <CryptoWithdrawForm defaultWallet={user.default_wallet_address} defaultCurrency={user.default_crypto_currency} />}
        {tab === 'bank'     && <BankForm     bankDetails={user.bank_details} onSave={bd => setUser(u => ({ ...u, bank_details: bd }))} />}
        {tab === 'stats'    && <PlayerStats />}
        {tab === 'history'  && <UserTransactions />}
        {tab === 'referral' && <ReferralDashboard />}
      </div>
    </div>
  )
}
