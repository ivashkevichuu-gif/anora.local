import React from 'react'
import { motion, AnimatePresence } from 'framer-motion'

function avatarColor(name) {
  let hash = 0
  for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash)
  const colors = ['#7c3aed','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2']
  return colors[Math.abs(hash) % colors.length]
}

function ChanceBar({ chance }) {
  const pct   = Math.min(100, chance * 100)
  const color = pct > 60 ? '#00ff88' : pct > 30 ? '#f59e0b' : '#a855f7'
  const risk  = pct > 50 ? { label: 'High chance · Lower reward', color: '#00ff88' }
              : pct > 20 ? { label: 'Medium chance',               color: '#f59e0b' }
              :             { label: 'Low chance · High reward',    color: '#a855f7' }
  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-center gap-2">
        <div className="flex-1 h-1.5 rounded-full overflow-hidden"
          style={{ background: 'rgba(255,255,255,0.08)', minWidth: 60 }}>
          <motion.div
            className="h-full rounded-full"
            style={{ background: color, boxShadow: `0 0 6px ${color}88` }}
            initial={{ width: 0 }}
            animate={{ width: `${pct}%` }}
            transition={{ duration: 0.5, ease: 'easeOut' }}
          />
        </div>
        <span className="text-xs font-bold tabular-nums" style={{ color, minWidth: 38, textAlign: 'right' }}>
          {pct.toFixed(1)}%
        </span>
      </div>
      <span className="text-xs" style={{ color: risk.color, opacity: 0.7 }}>{risk.label}</span>
    </div>
  )
}

export default function BetsTable({ bets, myUserId }) {
  const totalBets = bets?.reduce((s, b) => s + (b.bet_count ?? 1), 0) ?? 0

  return (
    <div
      className="rounded-2xl overflow-hidden"
      style={{
        background: 'rgba(255,255,255,0.02)',
        border: '1px solid rgba(255,255,255,0.06)',
        backdropFilter: 'blur(12px)',
      }}
    >
      {/* Header */}
      <div className="px-5 py-3 border-b flex items-center gap-2"
        style={{ borderColor: 'rgba(255,255,255,0.06)' }}>
        <i className="bi bi-people-fill" style={{ color: 'var(--neon-purple)' }}></i>
        <span className="text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>
          Live Bets
        </span>
        <span className="ml-auto text-xs px-2 py-0.5 rounded-full font-semibold"
          style={{ background: 'rgba(168,85,247,0.15)', color: 'var(--neon-purple)' }}>
          {totalBets} bet{totalBets !== 1 ? 's' : ''}
        </span>
      </div>

      {!bets?.length ? (
        <div className="px-5 py-8 text-center text-sm" style={{ color: 'var(--text-muted)' }}>
          No bets yet this round
        </div>
      ) : (
        <div>
          <AnimatePresence>
            {bets.map((bet, i) => {
              const isMe  = bet.user_id === myUserId
              const isBot = bet.is_bot
              const name  = bet.display_name || bet.email?.split('@')[0] || 'Player'
              const color = avatarColor(name)

              return (
                <motion.div
                  key={bet.user_id}
                  initial={{ opacity: 0, x: -16 }}
                  animate={{ opacity: 1, x: 0 }}
                  transition={{ delay: i * 0.04 }}
                  className="px-5 py-3 transition-colors"
                  style={{
                    background: isMe ? 'rgba(124,58,237,0.06)' : 'transparent',
                    borderLeft: isMe ? '2px solid rgba(124,58,237,0.5)' : '2px solid transparent',
                    opacity: isBot ? 0.75 : 1,
                  }}
                >
                  {/* Top row: avatar + name + bets + staked */}
                  <div className="flex items-center gap-3 mb-2">
                    <div
                      className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                      style={{ background: color, boxShadow: isMe ? `0 0 10px ${color}66` : 'none' }}
                    >
                      {name.slice(0, 2).toUpperCase()}
                    </div>
                    <span className="flex-1 text-sm truncate"
                      style={{ color: isMe ? 'var(--text)' : 'var(--text-muted)' }}>
                      {name}
                      {isMe && (
                        <span className="ml-1 text-xs" style={{ color: 'var(--neon-purple)' }}>(you)</span>
                      )}
                    </span>
                    <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                      ×{bet.bet_count ?? 1}
                    </span>
                    <span className="text-sm font-bold" style={{ color: 'var(--neon-green)' }}>
                      ${(bet.total_bet ?? 0).toFixed(2)}
                    </span>
                  </div>

                  {/* Win chance progress bar */}
                  <ChanceBar chance={bet.chance ?? 0} />
                </motion.div>
              )
            })}
          </AnimatePresence>
        </div>
      )}
    </div>
  )
}
