import { useRef, useEffect, useState } from 'react'
import { motion } from 'framer-motion'

export default function PotDisplay({ pot }) {
  const prevPot = useRef(pot)
  const [displayPot, setDisplayPot] = useState(pot)
  const isFirst = useRef(true)

  useEffect(() => {
    // Skip animation on first render — show value immediately
    if (isFirst.current) {
      isFirst.current = false
      setDisplayPot(pot)
      prevPot.current = pot
      return
    }

    // Only animate when pot actually changes to a different value
    if (pot !== prevPot.current) {
      setDisplayPot(pot)
      prevPot.current = pot
    }
  }, [pot])

  return (
    <div className="flex flex-col items-center gap-1">
      <span className="text-xs font-semibold tracking-widest uppercase"
        style={{ color: 'var(--text-muted)' }}>
        Total Pot
      </span>
      <motion.div
        animate={{ scale: [1, 1.05, 1] }}
        transition={{ duration: 0.3 }}
        key={displayPot}
        className="text-6xl font-black tracking-tight"
        style={{
          background: 'linear-gradient(135deg, #00ff88, #a855f7)',
          WebkitBackgroundClip: 'text',
          WebkitTextFillColor: 'transparent',
          backgroundClip: 'text',
          filter: 'drop-shadow(0 0 20px rgba(0,255,136,0.4))',
        }}
      >
        ${displayPot.toFixed(2)}
      </motion.div>
    </div>
  )
}
