import React from 'react'
import { motion, AnimatePresence } from 'framer-motion'

function avatarColor(name) {
  let hash = 0
  for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash)
  const colors = ['#7c3aed','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2']
  return colors[Math.abs(hash) % colors.length]
}

function displayName(bet) {
  return bet.display_name || bet.email?.split('@')[0] || 'Player'
}

export default function Participants({ bets, myUserId }) {
  if (!bets?.length) {
    return (
      <div className="flex items-center justify-center py-6"
        style={{ color: 'var(--text-muted)', fontSize: '.85rem' }}>
        <i className="bi bi-people me-2"></i>
        Waiting for players to join…
      </div>
    )
  }

  return (
    <div className="flex gap-3 overflow-x-auto pb-2 px-1" style={{ scrollbarWidth: 'thin' }}>
      <AnimatePresence>
        {bets.map((bet, i) => {
          const isMe   = bet.user_id === myUserId
          const isBot  = bet.is_bot
          const name   = displayName(bet)
          const color  = avatarColor(name)
          const staked = bet.total_bet ?? bet.amount ?? 1

          return (
            <motion.div
              key={bet.user_id}
              initial={{ opacity: 0, scale: 0.5, y: 20 }}
              animate={{ opacity: 1, scale: 1,   y: 0 }}
              exit={{    opacity: 0, scale: 0.5 }}
              transition={{ delay: i * 0.05, type: 'spring', stiffness: 300, damping: 20 }}
              className="flex flex-col items-center gap-1.5 flex-shrink-0"
              style={{
                minWidth: 60,
                marginTop: 7,
                opacity: isBot ? 0.75 : 1,
              }}
            >
              <div
                className="w-12 h-12 rounded-full flex items-center justify-center text-sm font-bold text-white relative"
                style={{
                  background: color,
                  boxShadow: isMe
                    ? `0 0 0 2px var(--bg), 0 0 0 4px ${color}, 0 0 16px ${color}88`
                    : `0 0 12px ${color}44`,
                }}
              >
                {name.slice(0, 2).toUpperCase()}

                {/* Bet count badge */}
                {(bet.bet_count ?? 1) > 1 && (
                  <span
                    className="absolute -top-1 -right-1 w-5 h-5 rounded-full flex items-center justify-center text-white font-bold"
                    style={{ background: 'var(--neon-purple)', fontSize: 9, boxShadow: '0 0 6px var(--neon-purple)' }}
                  >
                    {bet.bet_count}
                  </span>
                )}
              </div>

              <span className="text-xs max-w-[56px] truncate text-center"
                style={{ color: isMe ? 'var(--text)' : 'var(--text-muted)' }}>
                {name}
              </span>

              <span className="text-xs font-bold" style={{ color: 'var(--neon-green)' }}>
                ${staked.toFixed(2)}
              </span>

              {bet.chance !== undefined && (
                <span className="text-xs" style={{ color: 'var(--neon-gold)', fontSize: 10 }}>
                  {(bet.chance * 100).toFixed(0)}%
                </span>
              )}
            </motion.div>
          )
        })}
      </AnimatePresence>
    </div>
  )
}
