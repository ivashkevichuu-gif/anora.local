import React from 'react'

const SECTIONS = [
  { icon: 'bi-building', color: '#6c63ff', title: 'Who We Are',
    text: 'ANORA is a modern personal finance platform designed to give you full control over your money. Simple, transparent, and secure.' },
  { icon: 'bi-bullseye', color: '#10b981', title: 'Our Mission',
    text: 'Provide a reliable platform for depositing, managing, and withdrawing funds — with full transaction transparency and fast support.' },
  { icon: 'bi-shield-check', color: '#f59e0b', title: 'Security',
    text: 'All accounts are protected with email verification, hashed passwords, and session-based authentication. Withdrawals are manually reviewed.' },
  { icon: 'bi-envelope', color: '#38bdf8', title: 'Contact',
    text: 'Have questions? Reach us at support@anora.bet' },
]

export default function About() {
  return (
    <div style={{ maxWidth: 700 }}>
      <h2 className="mb-1 fw-700">About Us</h2>
      <p style={{ color: 'var(--text-muted)' }} className="mb-4">
        Learn more about ANORA and what we stand for.
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
              <div className="fw-600 mb-1">{s.title}</div>
              <div style={{ color: 'var(--text-muted)', fontSize: '.9rem' }}>{s.text}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
