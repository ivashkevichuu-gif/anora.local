import React from 'react'
import { motion } from 'framer-motion'

function avatarColor(email) {
  let hash = 0
  for (let i = 0; i < email.length; i++) hash = email.charCodeAt(i) + ((hash << 5) - hash)
  const colors = ['#7c3aed','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2']
  return colors[Math.abs(hash) % colors.length]
}

export default function PreviousGame({ game }) {
  if (!game) return null

  return (
    <div
      className="rounded-2xl p-5"
      style={{
        background: 'rgba(255,255,255,0.02)',
        border: '1px solid rgba(255,255,255,0.06)',
      }}
    >
      <div className="flex items-center gap-2 mb-4">
        <i className="bi bi-trophy-fill" style={{ color: 'var(--neon-gold)' }}></i>
        <span className="text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>
          Previous Round #{game.id}
        </span>
        <span className="ml-auto text-xs" style={{ color: 'var(--text-muted)' }}>
          {game.finished_at?.slice(0, 16).replace('T', ' ')}
        </span>
      </div>

      {/* Winner highlight */}
      {game.winner_email && (
        <motion.div
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          className="flex items-center gap-3 p-3 rounded-xl mb-4"
          style={{
            background: 'rgba(245,158,11,0.08)',
            border: '1px solid rgba(245,158,11,0.25)',
            boxShadow: '0 0 20px rgba(245,158,11,0.1)',
          }}
        >
          <div
            className="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white glow-winner"
            style={{ background: avatarColor(game.winner_email) }}
          >
            {game.winner_email.slice(0, 2).toUpperCase()}
          </div>
          <div>
            <div className="font-bold" style={{ color: 'var(--neon-gold)' }}>
              {game.winner_email.split('@')[0]}
            </div>
            <div className="text-xs" style={{ color: 'var(--text-muted)' }}>Winner</div>
          </div>
          <div className="ml-auto text-xl font-black" style={{ color: 'var(--neon-gold)' }}>
            +${game.total_pot.toFixed(2)}
          </div>
        </motion.div>
      )}

      {/* Players */}
      <div className="flex flex-wrap gap-2">
        {game.bets?.map(bet => (
          <div
            key={bet.user_id}
            className="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs"
            style={{
              background: bet.user_id === game.winner_id
                ? 'rgba(245,158,11,0.1)'
                : 'rgba(255,255,255,0.04)',
              border: bet.user_id === game.winner_id
                ? '1px solid rgba(245,158,11,0.3)'
                : '1px solid rgba(255,255,255,0.06)',
              color: bet.user_id === game.winner_id ? 'var(--neon-gold)' : 'var(--text-muted)',
            }}
          >
            <div
              className="w-5 h-5 rounded-full flex items-center justify-center text-white font-bold"
              style={{ background: avatarColor(bet.email), fontSize: 9 }}
            >
              {bet.email.slice(0, 2).toUpperCase()}
            </div>
            {bet.email.split('@')[0]}
            <span style={{ opacity: 0.6 }}>×{bet.bet_count ?? 1}</span>
            <span style={{ color: 'var(--neon-green)' }}>{(bet.chance * 100).toFixed(1)}%</span>
          </div>
        ))}
      </div>

      {/* FIXED: provably fair seed reveal */}
      {game.server_seed && (
        <div className="mt-4 pt-4" style={{ borderTop: '1px solid rgba(255,255,255,0.06)' }}>
          <div className="text-xs mb-1 flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
            <i className="bi bi-shield-check" style={{ color: 'var(--neon-green)' }}></i>
            Provably Fair — Server Seed
          </div>
          <div
            className="text-xs font-mono px-3 py-2 rounded-lg break-all"
            style={{ background: 'rgba(0,255,136,0.05)', color: 'var(--neon-green)', border: '1px solid rgba(0,255,136,0.15)' }}
          >
            {game.server_seed}
          </div>
          <div className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            SHA-256: {game.server_seed ? btoa(game.server_seed).slice(0, 20) + '…' : ''}
          </div>
        </div>
      )}
    </div>
  )
}
