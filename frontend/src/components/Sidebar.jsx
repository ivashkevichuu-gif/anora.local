import React, { useState, useEffect, useRef } from 'react'
import { NavLink, Link, useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import { useAuth } from '../context/AuthContext'

// ── Balance card with win flash ──────────────────────────────────────────────
function BalanceCard({ user }) {
  const prevBalRef = useRef(parseFloat(user.balance ?? 0))
  const [flash, setFlash] = useState(null)

  useEffect(() => {
    const curr = parseFloat(user.balance ?? 0)
    const prev = prevBalRef.current

    if (curr > prev + 0.01) {
      setFlash('win')
      const t = setTimeout(() => setFlash(null), 2500)
      prevBalRef.current = curr
      return () => clearTimeout(t)
    } else if (curr < prev - 0.01) {
      prevBalRef.current = curr
    } else {
      prevBalRef.current = curr
    }
  }, [user.balance])

  const isWin = flash === 'win'

  return (
    <div
      className="mx-1 mb-2 px-3 py-3 rounded-xl"
      style={{
        background: isWin
          ? 'linear-gradient(135deg, rgba(0,255,136,0.2), rgba(124,58,237,0.15))'
          : 'linear-gradient(135deg, rgba(124,58,237,0.15), rgba(0,255,136,0.08))',
        border: `1px solid ${isWin ? 'rgba(0,255,136,0.6)' : 'rgba(124,58,237,0.25)'}`,
        boxShadow: isWin ? '0 0 20px rgba(0,255,136,0.3)' : 'none',
        transition: 'all 0.4s ease',
      }}
    >
      <div className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>
        <i className="bi bi-person-circle me-1"></i>
        {user.nickname ?? user.email.split('@')[0]}
      </div>
      <div
        className="text-xl font-black"
        style={{
          color: isWin ? '#00ff88' : '#a78bfa',
          textShadow: isWin ? '0 0 12px rgba(0,255,136,0.5)' : 'none',
          transition: 'color 0.3s, text-shadow 0.3s',
        }}
      >
        ${parseFloat(user.balance ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}
      </div>
      {isWin && (
        <div className="text-xs mt-1 font-bold" style={{ color: '#00ff88' }}>
          <i className="bi bi-trophy-fill me-1"></i>You won!
        </div>
      )}
    </div>
  )
}

const PUBLIC_LINKS = [
  { to: '/',        icon: 'bi-house-fill',        label: 'Home' },
  { to: '/about',   icon: 'bi-info-circle-fill',   label: 'About Us' },
]
const AUTH_LINKS = [
  { to: '/account', icon: 'bi-wallet2',            label: 'Account' },
]
const GUEST_LINKS = [
  { to: '/login',    icon: 'bi-box-arrow-in-right', label: 'Login' },
  { to: '/register', icon: 'bi-person-plus-fill',   label: 'Register' },
]

export default function Sidebar({ open, onClose }) {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const handleLogout = async () => {
    onClose()
    await logout()
    navigate('/')
  }

  const linkClass = ({ isActive }) => `sidebar-link${isActive ? ' active' : ''}`

  return (
    <>
      <div className={`sidebar-overlay${open ? ' open' : ''}`} onClick={onClose} />

      <aside className={`sidebar${open ? ' open' : ''}`}>
        {/* Logo */}
        <Link to="/" className="sidebar-logo" onClick={onClose}>
          <i className="bi bi-bank2"></i>
          ANORA
        </Link>

        <nav className="sidebar-nav">
          <div className="nav-section">Menu</div>

          {PUBLIC_LINKS.map(l => (
            <NavLink key={l.to} to={l.to} end className={linkClass} onClick={onClose}>
              <i className={`bi ${l.icon}`}></i>
              {l.label}
            </NavLink>
          ))}

          {user && AUTH_LINKS.map(l => (
            <NavLink key={l.to} to={l.to} className={linkClass} onClick={onClose}>
              <i className={`bi ${l.icon}`}></i>
              {l.label}
            </NavLink>
          ))}

          <div className="nav-section" style={{ marginTop: 8 }}>Account</div>

          {user ? (
            <>
              {/* UPDATED: real-time balance display with win flash */}
              <BalanceCard user={user} />

              <button className="sidebar-link" onClick={handleLogout}>
                <i className="bi bi-box-arrow-left"></i>
                Logout
              </button>
            </>
          ) : (
            GUEST_LINKS.map(l => (
              <NavLink key={l.to} to={l.to} className={linkClass} onClick={onClose}>
                <i className={`bi ${l.icon}`}></i>
                {l.label}
              </NavLink>
            ))
          )}
        </nav>

        <div className="sidebar-footer">
          <div className="text-center" style={{ fontSize: '.7rem', color: 'var(--text-muted)', opacity: 0.5, padding: '8px 0' }}>
           Build v{__BUILD_VERSION__}
          </div>
        </div>
      </aside>
    </>
  )
}
