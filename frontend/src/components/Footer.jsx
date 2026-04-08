import React, { useState, useEffect } from 'react'
import { api } from '../api/client'

export default function Footer() {
  const [links, setLinks] = useState({ telegram_url: '', instagram_url: '' })

  useEffect(() => {
    api.socialLinks().then(setLinks).catch(() => {})
  }, [])

  return (
    <footer className="bg-dark text-white-50 text-center py-3 mt-5">
      <div className="container d-flex flex-column flex-sm-row justify-content-center align-items-center gap-3">
        <small>&copy; {new Date().getFullYear()} ANORA. All rights reserved.</small>
        <div className="d-flex gap-3">
          {links.telegram_url && (
            <a href={links.telegram_url} target="_blank" rel="noopener noreferrer"
              className="text-white-50" style={{ fontSize: '1.25rem' }}
              aria-label="Telegram">
              <i className="bi bi-telegram" />
            </a>
          )}
          {links.instagram_url && (
            <a href={links.instagram_url} target="_blank" rel="noopener noreferrer"
              className="text-white-50" style={{ fontSize: '1.25rem' }}
              aria-label="Instagram">
              <i className="bi bi-instagram" />
            </a>
          )}
        </div>
      </div>
    </footer>
  )
}
