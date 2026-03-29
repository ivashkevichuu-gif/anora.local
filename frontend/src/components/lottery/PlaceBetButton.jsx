import React from 'react'
import { motion } from 'framer-motion'

/**
 * FIXED:
 * - Removed alreadyBet path (multi-bet system — always allow)
 * - disabled prop covers: loading, round finished, countdown=0, guest
 * - Button is non-interactive while loading (spam prevention)
 */
export default function PlaceBetButton({ onBet, disabled, loading, isGuest }) {
  const isBlocked = disabled || loading || isGuest

  const label = isGuest  ? 'Login to Play'
              : loading  ? 'Placing…'
              : disabled ? 'Betting Closed'
              : 'Place Bet — $1'

  return (
    <motion.button
      onClick={isBlocked ? undefined : onBet}
      disabled={isBlocked}
      whileHover={!isBlocked ? { scale: 1.04 } : {}}
      whileTap={!isBlocked  ? { scale: 0.97 } : {}}
      className="relative px-10 py-4 rounded-xl font-bold text-lg tracking-wide overflow-hidden"
      style={{
        background: isBlocked
          ? 'rgba(255,255,255,0.05)'
          : 'linear-gradient(135deg, #00ff88, #059669)',
        color:     isBlocked ? 'var(--text-muted)' : '#000',
        border:    'none',
        boxShadow: !isBlocked
          ? '0 0 30px rgba(0,255,136,0.4), 0 0 60px rgba(0,255,136,0.15)'
          : 'none',
        cursor:    isBlocked ? 'not-allowed' : 'pointer',
        transition: 'box-shadow 0.3s, background 0.3s',
        minWidth: 200,
      }}
    >
      {/* Shimmer — only when active */}
      {!isBlocked && (
        <motion.div
          className="absolute inset-0 opacity-30"
          style={{
            background: 'linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent)',
            transform: 'skewX(-20deg)',
          }}
          animate={{ x: ['-200%', '200%'] }}
          transition={{ duration: 2.5, repeat: Infinity, ease: 'linear' }}
        />
      )}
      {loading && <span className="spinner-border spinner-border-sm me-2" />}
      {label}
    </motion.button>
  )
}
