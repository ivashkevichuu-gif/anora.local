import { useState, useEffect } from 'react'
import { api } from '../api/client'

export default function Footer() {
  const [links, setLinks] = useState({ telegram_url: '', instagram_url: '' })

  useEffect(() => {
    api.socialLinks().then(setLinks).catch(() => {})
  }, [])

  return (
    <footer className="app-footer">
      <span>&copy; {new Date().getFullYear()} ANORA. All rights reserved.</span>
      <div className="d-flex align-items-center gap-3">
        <span>Your chance for life growth.</span>
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
