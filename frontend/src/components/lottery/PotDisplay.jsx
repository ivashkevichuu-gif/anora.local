import React from 'react'
import { motion, AnimatePresence } from 'framer-motion'

export default function PotDisplay({ pot }) {
  return (
    <div className="flex flex-col items-center gap-1">
      <span className="text-xs font-semibold tracking-widest uppercase"
        style={{ color: 'var(--text-muted)' }}>
        Total Pot
      </span>
      <AnimatePresence mode="wait">
        <motion.div
          key={pot}
          initial={{ scale: 0.8, opacity: 0, y: -10 }}
          animate={{ scale: 1,   opacity: 1, y: 0 }}
          exit={{    scale: 1.2, opacity: 0, y: 10 }}
          transition={{ type: 'spring', stiffness: 400, damping: 20 }}
          className="text-6xl font-black tracking-tight"
          style={{
            background: 'linear-gradient(135deg, #00ff88, #a855f7)',
            WebkitBackgroundClip: 'text',
            WebkitTextFillColor: 'transparent',
            backgroundClip: 'text',
            filter: 'drop-shadow(0 0 20px rgba(0,255,136,0.4))',
          }}
        >
          ${pot.toFixed(2)}
        </motion.div>
      </AnimatePresence>
    </div>
  )
}
