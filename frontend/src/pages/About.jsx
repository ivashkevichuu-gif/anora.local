import { useState } from 'react'
import { useSEO } from '../hooks/useSEO'

const TABS = [
  { id: 'info', label: 'Info', icon: 'bi-info-circle-fill' },
  { id: 'payments', label: 'Payments', icon: 'bi-wallet2' },
]

export default function About() {
  useSEO('Info', 'Learn about ANORA — provably fair crypto lottery with Trust Wallet payments, real-time gameplay, and instant payouts.')
  const [tab, setTab] = useState('info')

  return (
    <div className="flex flex-col gap-4 max-w-2xl mx-auto" style={{ maxWidth: 700 }}>
      <h2 className="mb-1 fw-700">Info</h2>
      <p style={{ color: 'var(--text-muted)' }} className="mb-2">
        Everything you need to know about ANORA.
      </p>

      {/* Tabs */}
      <div className="d-flex gap-2 mb-3">
        {TABS.map(t => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className="d-flex align-items-center gap-2"
            style={{
              padding: '10px 20px', borderRadius: 12, border: 'none', cursor: 'pointer',
              fontSize: '.9rem', fontWeight: 600, transition: 'all .2s',
              background: tab === t.id ? 'rgba(0,229,255,0.12)' : 'rgba(255,255,255,0.04)',
              color: tab === t.id ? '#00E5FF' : 'var(--text-muted)',
              borderBottom: tab === t.id ? '2px solid #00E5FF' : '2px solid transparent',
            }}>
            <i className={`bi ${t.icon}`} />
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'info' && <InfoTab />}
      {tab === 'payments' && <PaymentsTab />}
    </div>
  )
}

// ── Info Tab ──────────────────────────────────────────────────────────────────

const INFO_SECTIONS = [
  { icon: 'bi-dice-5-fill', color: '#7c3aed', title: 'What is ANORA?',
    text: 'ANORA is a provably fair lottery platform where players compete in real-time raffle rounds. Place your bet, watch the countdown, and see if luck is on your side. Every game result is cryptographically verifiable.' },
  { icon: 'bi-grid-3x3-gap-fill', color: '#10b981', title: 'Game Rooms',
    text: 'We offer three rooms with different bet sizes: $1, $10, and $100. Each room runs independently — pick the stakes that suit you. When 2+ players join, a 30-second countdown begins. After the timer expires, a winner is selected based on weighted probability: the more you bet, the higher your chance.' },
  { icon: 'bi-shield-check', color: '#f59e0b', title: 'Provably Fair',
    text: 'Every round uses a server seed (hidden until the round ends) combined with all player client seeds via SHA-256 hashing. After the round finishes, the server seed is revealed so anyone can independently verify the result. No manipulation is possible.' },
  { icon: 'bi-cash-stack', color: '#3b82f6', title: 'How Payouts Work',
    text: 'The winner receives 97% of the pot. A 2% platform fee keeps the lights on, and 1% goes as a referral bonus to the person who invited the winner (or back to the platform if no referrer). All payouts are instant and recorded in an immutable ledger.' },
  { icon: 'bi-people-fill', color: '#a855f7', title: 'Referral Program',
    text: 'Share your unique referral link and earn 1% of the pot every time someone you referred wins a game. Your referral earnings are tracked in your account and can be withdrawn anytime.' },
  { icon: 'bi-lock-fill', color: '#ef4444', title: 'Security',
    text: 'All accounts are protected with email verification and hashed passwords. Every financial operation is recorded in an append-only ledger for full audit transparency. Crypto webhooks are validated with HMAC-SHA512 signatures.' },
  { icon: 'bi-envelope-fill', color: '#38bdf8', title: 'Contact',
    text: 'Questions or issues? Reach us at support@anora.bet — we\'re here to help.' },
]

function InfoTab() {
  return (
    <div className="d-flex flex-column gap-3">
      {INFO_SECTIONS.map(s => (
        <div className="card p-4 d-flex flex-row align-items-start gap-3" key={s.title}>
          <div style={{
            background: `${s.color}18`, borderRadius: 10,
            width: 44, height: 44, display: 'flex', alignItems: 'center',
            justifyContent: 'center', flexShrink: 0,
          }}>
            <i className={`bi ${s.icon}`} style={{ color: s.color, fontSize: '1.2rem' }} />
          </div>
          <div>
            <div className="fw-600 mb-1" style={{ color: 'var(--text)' }}>{s.title}</div>
            <div style={{ color: 'var(--text-muted)', fontSize: '.9rem' }}>{s.text}</div>
          </div>
        </div>
      ))}
    </div>
  )
}

// ── Payments Tab ─────────────────────────────────────────────────────────────

const DEPOSIT_STEPS = [
  { num: '1', title: 'Download Trust Wallet',
    text: 'Install Trust Wallet from the App Store (iOS) or Google Play (Android). It\'s free and supports 10M+ crypto assets.',
    link: 'https://trustwallet.com/download' },
  { num: '2', title: 'Create or Import a Wallet',
    text: 'Open Trust Wallet and create a new wallet. Write down your 12-word recovery phrase and store it safely. Never share it with anyone.' },
  { num: '3', title: 'Buy or Transfer Crypto',
    text: 'Buy crypto directly in Trust Wallet using a card, or transfer from another wallet/exchange. We recommend USDT (TRC-20) or BTC for deposits.' },
  { num: '4', title: 'Deposit on ANORA',
    text: 'Go to your ANORA Account → Crypto Deposit. Choose your currency and amount. You\'ll get a payment address — send crypto from Trust Wallet to that address. Balance is credited automatically after blockchain confirmation.' },
]

const WITHDRAW_STEPS = [
  { num: '1', title: 'Open Your Trust Wallet',
    text: 'Make sure you have a wallet address ready to receive funds. In Trust Wallet, tap the coin you want to receive → "Receive" → copy the address.' },
  { num: '2', title: 'Request Withdrawal on ANORA',
    text: 'Go to Account → Crypto Withdraw. Enter the amount, select currency, and paste your Trust Wallet address.' },
  { num: '3', title: 'Wait for Processing',
    text: 'Withdrawals are processed automatically. Amounts over $500 require admin approval for security. Funds arrive in your Trust Wallet after blockchain confirmation.' },
]

function PaymentsTab() {
  return (
    <div className="d-flex flex-column gap-4">
      {/* Trust Wallet intro */}
      <div className="card p-4" style={{
        background: 'linear-gradient(135deg, rgba(0,136,204,0.08), rgba(0,210,160,0.08))',
        border: '1px solid rgba(0,210,160,0.2)',
      }}>
        <div className="d-flex align-items-center gap-3 mb-3">
          <div style={{
            width: 48, height: 48, borderRadius: 12,
            background: 'linear-gradient(135deg, #0088CC, #00D2A0)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            flexShrink: 0,
          }}>
            <i className="bi bi-shield-check" style={{ color: '#fff', fontSize: '1.4rem' }} />
          </div>
          <div>
            <div className="fw-700" style={{ color: '#00D2A0', fontSize: '1.1rem' }}>
              Powered by Trust Wallet
            </div>
            <div style={{ color: 'var(--text-muted)', fontSize: '.85rem' }}>
              We recommend{' '}
              <a href="https://trustwallet.com" target="_blank" rel="noopener noreferrer nofollow"
                style={{ color: '#00D2A0', textDecoration: 'none' }}>
                Trust Wallet
              </a>
              {' '}for secure crypto deposits and withdrawals.
            </div>
          </div>
        </div>
      </div>

      {/* Deposit section */}
      <div>
        <h3 className="d-flex align-items-center gap-2 mb-3" style={{ color: 'var(--text)', fontSize: '1.1rem' }}>
          <i className="bi bi-arrow-down-circle-fill" style={{ color: '#10b981' }} />
          How to Deposit
        </h3>
        <div className="d-flex flex-column gap-2">
          {DEPOSIT_STEPS.map(s => (
            <div className="card p-3 d-flex flex-row align-items-start gap-3" key={s.num}>
              <div style={{
                width: 32, height: 32, borderRadius: '50%', flexShrink: 0,
                background: 'rgba(16,185,129,0.12)', border: '1px solid rgba(16,185,129,0.3)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                fontSize: '.85rem', fontWeight: 700, color: '#10b981',
              }}>{s.num}</div>
              <div>
                <div className="fw-600 mb-1" style={{ color: 'var(--text)', fontSize: '.95rem' }}>{s.title}</div>
                <div style={{ color: 'var(--text-muted)', fontSize: '.85rem' }}>{s.text}</div>
                {s.link && (
                  <a href={s.link} target="_blank" rel="noopener noreferrer nofollow"
                    className="d-inline-flex align-items-center gap-1 mt-1"
                    style={{ color: '#00D2A0', fontSize: '.8rem', textDecoration: 'none' }}>
                    <i className="bi bi-box-arrow-up-right" style={{ fontSize: '.7rem' }} />
                    trustwallet.com/download
                  </a>
                )}
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Withdraw section */}
      <div>
        <h3 className="d-flex align-items-center gap-2 mb-3" style={{ color: 'var(--text)', fontSize: '1.1rem' }}>
          <i className="bi bi-arrow-up-circle-fill" style={{ color: '#f59e0b' }} />
          How to Withdraw
        </h3>
        <div className="d-flex flex-column gap-2">
          {WITHDRAW_STEPS.map(s => (
            <div className="card p-3 d-flex flex-row align-items-start gap-3" key={s.num}>
              <div style={{
                width: 32, height: 32, borderRadius: '50%', flexShrink: 0,
                background: 'rgba(245,158,11,0.12)', border: '1px solid rgba(245,158,11,0.3)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                fontSize: '.85rem', fontWeight: 700, color: '#f59e0b',
              }}>{s.num}</div>
              <div>
                <div className="fw-600 mb-1" style={{ color: 'var(--text)', fontSize: '.95rem' }}>{s.title}</div>
                <div style={{ color: 'var(--text-muted)', fontSize: '.85rem' }}>{s.text}</div>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Important notes */}
      <div className="card p-4" style={{
        background: 'rgba(239,68,68,0.05)',
        border: '1px solid rgba(239,68,68,0.15)',
      }}>
        <div className="fw-600 mb-2 d-flex align-items-center gap-2" style={{ color: '#f87171' }}>
          <i className="bi bi-exclamation-triangle-fill" />
          Important
        </div>
        <ul style={{ color: 'var(--text-muted)', fontSize: '.85rem', paddingLeft: 18, margin: 0 }}>
          <li style={{ marginBottom: 6 }}>Minimum withdrawal amount: <strong style={{ color: '#f59e0b' }}>$100</strong></li>
          <li style={{ marginBottom: 6 }}>Maximum daily withdrawal: <strong style={{ color: 'var(--text)' }}>$10,000</strong></li>
          <li style={{ marginBottom: 6 }}>Withdrawals over $500 require admin approval for security</li>
          <li style={{ marginBottom: 6 }}>Always double-check the wallet address before sending</li>
          <li>Deposits are credited after blockchain confirmation (usually 1-30 minutes depending on network)</li>
        </ul>
      </div>

      {/* Supported currencies */}
      <div className="card p-4">
        <div className="fw-600 mb-2 d-flex align-items-center gap-2" style={{ color: 'var(--text)' }}>
          <i className="bi bi-currency-bitcoin" style={{ color: '#f97316' }} />
          Supported Cryptocurrencies
        </div>
        <div className="d-flex flex-wrap gap-2">
          {['BTC', 'ETH', 'USDT', 'TRX', 'SOL', 'BNB', 'MATIC', 'LTC', 'DOGE', 'XRP', 'ADA', 'AVAX'].map(c => (
            <span key={c} style={{
              padding: '4px 12px', borderRadius: 8, fontSize: '.8rem', fontWeight: 600,
              background: 'rgba(255,255,255,0.04)', border: '1px solid rgba(255,255,255,0.08)',
              color: 'var(--text-muted)',
            }}>{c}</span>
          ))}
          <span style={{
            padding: '4px 12px', borderRadius: 8, fontSize: '.8rem',
            color: 'var(--text-muted)',
          }}>and 50+ more</span>
        </div>
      </div>
    </div>
  )
}
