import { useState, useEffect } from 'react'
import { api } from '../api/client'

function TrustWalletIcon({ size = 18 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path
        d="M16 2C16 2 6 7.5 6 14.5V19c0 5.5 4.2 10.2 10 11 5.8-.8 10-5.5 10-11v-4.5C26 7.5 16 2 16 2z"
        fill="url(#tw-grad)" stroke="url(#tw-grad)" strokeWidth="0.5"
      />
      <path
        d="M16 8c0 0-5.5 3-5.5 7.5v2.5c0 3.2 2.3 6 5.5 6.5 3.2-.5 5.5-3.3 5.5-6.5v-2.5C21.5 11 16 8 16 8z"
        fill="none" stroke="rgba(255,255,255,0.9)" strokeWidth="1.2"
      />
      <defs>
        <linearGradient id="tw-grad" x1="6" y1="2" x2="26" y2="30">
          <stop offset="0%" stopColor="#0088CC" />
          <stop offset="100%" stopColor="#00D2A0" />
        </linearGradient>
      </defs>
    </svg>
  )
}

export default function Footer() {
  const [links, setLinks] = useState({ telegram_url: '', instagram_url: '' })

  useEffect(() => {
    api.socialLinks().then(setLinks).catch(() => {})
  }, [])

  return (
    <footer className="app-footer">
      <span>&copy; {new Date().getFullYear()} ANORA. All rights reserved.</span>

      <div className="d-flex align-items-center gap-3 flex-wrap" style={{ justifyContent: 'center' }}>
        <span>Your chance for life growth.</span>

        {/* Trust Wallet */}
        <a href="https://trustwallet.com"
          target="_blank" rel="noopener noreferrer nofollow"
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 5,
            color: 'rgba(255,255,255,0.5)', fontSize: '0.8rem',
            textDecoration: 'none', transition: 'color .2s',
          }}
          onMouseEnter={e => e.currentTarget.style.color = '#00D2A0'}
          onMouseLeave={e => e.currentTarget.style.color = 'rgba(255,255,255,0.5)'}
          aria-label="Trust Wallet — payments powered by"
        >
          <TrustWalletIcon size={16} />
          <span>Trust Wallet</span>
        </a>

        {/* Social icons */}
        {links.instagram_url && (
          <a href={links.instagram_url} target="_blank" rel="noopener noreferrer"
            style={{ color: 'rgba(255,255,255,0.5)', fontSize: '1.2rem', transition: 'color .2s' }}
            onMouseEnter={e => e.currentTarget.style.color = '#E1306C'}
            onMouseLeave={e => e.currentTarget.style.color = 'rgba(255,255,255,0.5)'}
            aria-label="Instagram">
            <i className="bi bi-instagram" />
          </a>
        )}
        {links.telegram_url && (
          <a href={links.telegram_url} target="_blank" rel="noopener noreferrer"
            style={{ color: 'rgba(255,255,255,0.5)', fontSize: '1.2rem', transition: 'color .2s' }}
            onMouseEnter={e => e.currentTarget.style.color = '#0088cc'}
            onMouseLeave={e => e.currentTarget.style.color = 'rgba(255,255,255,0.5)'}
            aria-label="Telegram">
            <i className="bi bi-telegram" />
          </a>
        )}
      </div>
    </footer>
  )
}
