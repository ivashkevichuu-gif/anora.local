import React from 'react'
import { NavLink, Link, useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import { useAuth } from '../context/AuthContext'

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
              {/* UPDATED: real-time balance display */}
              <div
                className="mx-1 mb-2 px-3 py-3 rounded-xl"
                style={{
                  background: 'linear-gradient(135deg, rgba(124,58,237,0.15), rgba(0,255,136,0.08))',
                  border: '1px solid rgba(124,58,237,0.25)',
                }}
              >
                <div className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>
                  <i className="bi bi-person-circle me-1"></i>
                  {user.nickname ?? user.email.split('@')[0]}
                </div>
                <div className="flex items-baseline gap-1">
                  <AnimatePresence mode="wait">
                    <motion.span
                      key={user.balance}
                      initial={{ opacity: 0, y: -6 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{    opacity: 0, y:  6 }}
                      transition={{ duration: 0.2 }}
                      className="text-xl font-black"
                      style={{
                        background: 'linear-gradient(135deg, #00ff88, #a855f7)',
                        WebkitBackgroundClip: 'text',
                        WebkitTextFillColor: 'transparent',
                        backgroundClip: 'text',
                      }}
                    >
                      ${parseFloat(user.balance ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}
                    </motion.span>
                  </AnimatePresence>
                </div>
              </div>

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
           Build v1.0.0
          </div>
        </div>
      </aside>
    </>
  )
}
