import React from 'react'
import { useSEO } from '../hooks/useSEO'

const SECTIONS = [
  { icon: 'bi-dice-5-fill', color: '#7c3aed', title: 'What is ANORA?',
    text: 'ANORA is a provably fair lottery platform where players compete in real-time raffle rounds. Place your bet, watch the countdown, and see if luck is on your side. Every game result is cryptographically verifiable.' },
  { icon: 'bi-grid-3x3-gap-fill', color: '#10b981', title: 'Game Rooms',
    text: 'We offer three rooms with different bet sizes: $1, $10, and $100. Each room runs independently — pick the stakes that suit you. When 2+ players join, a 30-second countdown begins. After the timer expires, a winner is selected based on weighted probability: the more you bet, the higher your chance.' },
  { icon: 'bi-shield-check', color: '#f59e0b', title: 'Provably Fair',
    text: 'Every round uses a server seed (hidden until the round ends) combined with all player client seeds via SHA-256 hashing. After the round finishes, the server seed is revealed so anyone can independently verify the result. No manipulation is possible.' },
  { icon: 'bi-currency-bitcoin', color: '#f97316', title: 'Crypto Wallet',
    text: 'Deposit and withdraw using cryptocurrency via NOWPayments. We support BTC, ETH, LTC, USDT, TRX, SOL, BNB, MATIC, DOGE and more. Deposits are credited automatically after blockchain confirmation. Withdrawals are processed to your wallet address.' },
  { icon: 'bi-cash-stack', color: '#3b82f6', title: 'How Payouts Work',
    text: 'The winner receives 97% of the pot. A 2% platform fee keeps the lights on, and 1% goes as a referral bonus to the person who invited the winner (or back to the platform if no referrer). All payouts are instant and recorded in an immutable ledger.' },
  { icon: 'bi-people-fill', color: '#a855f7', title: 'Referral Program',
    text: 'Share your unique referral link and earn 1% of the pot every time someone you referred wins a game. Your referral earnings are tracked in your account and can be withdrawn anytime.' },
  { icon: 'bi-lock-fill', color: '#ef4444', title: 'Security',
    text: 'All accounts are protected with email verification and hashed passwords. Every financial operation is recorded in an append-only ledger for full audit transparency. Crypto webhooks are validated with HMAC-SHA512 signatures.' },
  { icon: 'bi-envelope-fill', color: '#38bdf8', title: 'Contact',
    text: 'Questions or issues? Reach us at support@anora.bet — we\'re here to help.' },
]

export default function About() {
  useSEO('About', 'Learn about ANORA — a provably fair crypto lottery platform with real-time gameplay, SHA-256 verification, and instant payouts.')
  return (
    <div className="flex flex-col gap-6 max-w-2xl mx-auto" style={{ maxWidth: 700 }}>
      <h2 className="mb-1 fw-700">About ANORA</h2>
      <p style={{ color: 'var(--text-muted)' }} className="mb-4">
        A provably fair lottery platform with crypto payments.
      </p>
      <div className="d-flex flex-column gap-3">
        {SECTIONS.map(s => (
          <div className="card p-4 d-flex flex-row align-items-start gap-3" key={s.title}>
            <div style={{
              background: `${s.color}18`,
              borderRadius: 10,
              width: 44, height: 44,
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              flexShrink: 0,
            }}>
              <i className={`bi ${s.icon}`} style={{ color: s.color, fontSize: '1.2rem' }}></i>
            </div>
            <div>
              <div className="fw-600 mb-1" style={{ color: 'var(--text)' }}>{s.title}</div>
              <div style={{ color: 'var(--text-muted)', fontSize: '.9rem' }}>{s.text}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
