import React from 'react'
import { motion } from 'framer-motion'

function getColor(seconds) {
  if (seconds === null) return '#6b7280'
  if (seconds > 15) return '#00ff88'
  if (seconds > 7)  return '#f59e0b'
  return '#ef4444'
}

function getGlow(seconds) {
  if (seconds === null) return 'none'
  if (seconds > 15) return '0 0 30px rgba(0,255,136,0.5)'
  if (seconds > 7)  return '0 0 30px rgba(245,158,11,0.5)'
  return '0 0 30px rgba(239,68,68,0.6)'
}

export default function CountdownTimer({ countdown, status }) {
  const color = getColor(countdown)
  const isActive = status === 'active' && countdown !== null

  return (
    <div className="flex flex-col items-center gap-2">
      <span className="text-xs font-semibold tracking-widest uppercase"
        style={{ color: 'var(--text-muted)' }}>
        {status === 'waiting' ? 'Waiting for players' : status === 'finished' || status === 'spinning' ? 'Round ended' : 'Draw in'}
      </span>

      <motion.div
        animate={isActive && countdown <= 5
          ? { scale: [1, 1.12, 1] }
          : { scale: 1 }
        }
        transition={{ duration: 0.5, repeat: isActive && countdown <= 5 ? Infinity : 0 }}
        className="text-7xl font-black tabular-nums"
        style={{
          color,
          textShadow: getGlow(countdown),
          minWidth: 120,
          textAlign: 'center',
          transition: 'color 0.5s, text-shadow 0.5s',
        }}
      >
        {status === 'waiting'  ? '—'
         : status === 'finished' || status === 'spinning' ? '✓'
         : countdown !== null ? countdown
         : '—'}
      </motion.div>

      {/* Progress arc */}
      {isActive && (
        <div className="w-full max-w-xs h-1 rounded-full overflow-hidden"
          style={{ background: 'rgba(255,255,255,0.08)' }}>
          <motion.div
            className="h-full rounded-full"
            style={{ background: color, boxShadow: `0 0 8px ${color}` }}
            initial={{ width: '100%' }}
            animate={{ width: `${(countdown / 30) * 100}%` }}
            transition={{ duration: 0.9, ease: 'linear' }}
          />
        </div>
      )}
    </div>
  )
}
