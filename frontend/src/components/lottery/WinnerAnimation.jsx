import { useEffect, useRef, useState, useMemo } from 'react'
import { motion, AnimatePresence } from 'framer-motion'

// ─── Helpers ─────────────────────────────────────────────────────────────────

function avatarColor(email) {
  let hash = 0
  for (let i = 0; i < email.length; i++) hash = email.charCodeAt(i) + ((hash << 5) - hash)
  const colors = ['#7c3aed','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2']
  return colors[Math.abs(hash) % colors.length]
}

function getDisplayName(bet) {
  return bet.display_name ?? bet.email.split('@')[0]
}

// ─── Layout ───────────────────────────────────────────────────────────────────

const TILE_W      = 80
const TILE_GAP    = 10
const TILE_STEP   = TILE_W + TILE_GAP   // 90px per slot
const CONTAINER_W = 400
const CENTER      = CONTAINER_W / 2     // 200px — gold line

// Winner sits at this index in the strip (deep enough to hide the start)
const WINNER_IDX  = 150
const STRIP_LEN   = 200   // total tiles

// Exact translateX so winner tile center aligns with CENTER:
//   tile[WINNER_IDX] center = WINNER_IDX * TILE_STEP + TILE_W/2
//   translateX = CENTER - tile_center
const TARGET_X = CENTER - (WINNER_IDX * TILE_STEP + TILE_W / 2)

const SPIN_MS = 5500   // must match SPIN_DURATION in useGameMachine

// ─── Casino easing ────────────────────────────────────────────────────────────
// Phase 1 (0–20%):  quadratic acceleration
// Phase 2 (20–80%): linear cruise at full speed
// Phase 3 (80–100%): cubic deceleration to exact stop
function easeCasino(t) {
  if (t < 0.2) return 2.5 * t * t
  if (t < 0.8) return 0.1 + (t - 0.2) * 1.333
  const p = (t - 0.8) / 0.2
  return 0.9 + (1 - Math.pow(1 - p, 3)) * 0.1
}

// ─── Weighted strip builder ───────────────────────────────────────────────────
// Bets with more total_bet get higher visual frequency in the strip.
// This makes the reel feel weighted — high-stakers appear more often.
function buildWeightedStrip(bets, winnerBet, winnerIdx, totalLen) {
  if (!bets.length) return []

  // Weight each bet by their total_bet (more bets = more tiles)
  const totalWeight = bets.reduce((s, b) => s + (b.total_bet ?? 1), 0)

  // Weighted random picker
  function pick() {
    let r = Math.random() * totalWeight
    for (const b of bets) {
      r -= (b.total_bet ?? 1)
      if (r <= 0) return b
    }
    return bets[bets.length - 1]
  }

  const strip = Array.from({ length: totalLen }, () => ({ ...pick(), _isWinner: false }))

  // Place winner at exact index
  strip[winnerIdx] = { ...winnerBet, _isWinner: true }

  // Near-miss: place a different player 1 before and 2 after winner
  // This creates the "so close!" feeling
  const others = bets.filter(b => b.user_id !== winnerBet.user_id)
  if (others.length > 0) {
    const nearMiss = others[Math.floor(Math.random() * others.length)]
    if (winnerIdx > 0)           strip[winnerIdx - 1] = { ...nearMiss, _isWinner: false }
    if (winnerIdx + 2 < totalLen) strip[winnerIdx + 2] = { ...nearMiss, _isWinner: false }
  }

  return strip
}

// ─── Component ────────────────────────────────────────────────────────────────

export default function WinnerAnimation({ bets, winner, pot, phase }) {
  const [stripX, setStripX]     = useState(0)
  const [blur, setBlur]         = useState(0)
  const [flashing, setFlashing] = useState(false)

  const rafRef      = useRef(null)
  const hasSpunRef  = useRef(false)   // spin-once guard
  const lastXRef    = useRef(0)       // for velocity tracking
  const velocityRef = useRef(0)

  const winnerId = winner?.id ?? winner?.user_id ?? null

  // Build the strip once per round (bets + winner change together)
  const tiles = useMemo(() => {
    if (!bets?.length || winnerId == null) return []
    const winnerBet = bets.find(b => b.user_id === winnerId) ?? bets[0]
    return buildWeightedStrip(bets, winnerBet, WINNER_IDX, STRIP_LEN)
  }, [bets, winnerId])

  // Reset guard when new tiles arrive (new round)
  useEffect(() => {
    hasSpunRef.current = false
    lastXRef.current   = 0
    velocityRef.current = 0
    setStripX(0)
    setBlur(0)
  }, [tiles])

  // ── Spin animation — runs ONCE when phase becomes SPINNING ────────────────
  useEffect(() => {
    if (phase !== 'SPINNING' || !tiles.length || hasSpunRef.current) return
    hasSpunRef.current = true

    cancelAnimationFrame(rafRef.current)
    setStripX(0)
    setBlur(0)
    setFlashing(false)
    lastXRef.current    = 0
    velocityRef.current = 0

    let startTime = null

    function step(ts) {
      if (!startTime) startTime = ts
      const progress = Math.min((ts - startTime) / SPIN_MS, 1)
      const eased    = easeCasino(progress)
      const currentX = TARGET_X * eased

      // Velocity = pixels moved since last frame → drives motion blur
      const dx = Math.abs(currentX - lastXRef.current)
      velocityRef.current = dx
      lastXRef.current    = currentX

      // Blur proportional to speed, max 8px
      const blurPx = Math.min(dx / 8, 8)

      setStripX(currentX)
      setBlur(blurPx)

      if (progress < 1) {
        rafRef.current = requestAnimationFrame(step)
      } else {
        // Snap to exact target, clear blur
        setStripX(TARGET_X)
        setBlur(0)
      }
    }

    rafRef.current = requestAnimationFrame(step)
    return () => cancelAnimationFrame(rafRef.current)
  }, [phase, tiles])

  // ── Flash on RESULT ───────────────────────────────────────────────────────
  useEffect(() => {
    if (phase !== 'RESULT') return
    setBlur(0)
    setStripX(TARGET_X)
    setFlashing(true)
    const t = setTimeout(() => setFlashing(false), 700)
    return () => clearTimeout(t)
  }, [phase])

  if (!tiles.length || !winner) return null
  if (phase !== 'SPINNING' && phase !== 'RESULT') return null

  const revealed = phase === 'RESULT'

  return (
    <div className="flex flex-col items-center gap-5 py-2 relative">

      {/* Screen flash */}
      <AnimatePresence>
        {flashing && (
          <motion.div
            initial={{ opacity: 0.6 }} animate={{ opacity: 0 }} transition={{ duration: 0.8 }}
            className="fixed inset-0 pointer-events-none z-50"
            style={{ background: 'radial-gradient(ellipse at center, rgba(245,158,11,0.5) 0%, transparent 65%)' }}
          />
        )}
      </AnimatePresence>

      <div className="text-xs font-semibold tracking-widest uppercase" style={{ color: 'var(--text-muted)' }}>
        {phase === 'SPINNING' ? '🎰 Selecting winner…' : '🏆 Winner!'}
      </div>

      {/* Carousel container */}
      <div className="relative overflow-hidden rounded-2xl"
        style={{
          width: CONTAINER_W, height: 104,
          background: 'rgba(0,0,0,0.35)',
          border: '1px solid rgba(255,255,255,0.08)',
          boxShadow: revealed ? '0 0 30px rgba(245,158,11,0.15)' : 'none',
          transition: 'box-shadow 0.5s',
        }}>

        {/* Edge fades — stronger than before for depth */}
        <div className="absolute inset-y-0 left-0 z-10 pointer-events-none"
          style={{ width: 80, background: 'linear-gradient(to right, rgba(0,0,0,0.9), transparent)' }} />
        <div className="absolute inset-y-0 right-0 z-10 pointer-events-none"
          style={{ width: 80, background: 'linear-gradient(to left, rgba(0,0,0,0.9), transparent)' }} />

        {/* Gold center line */}
        <div className="absolute inset-y-0 z-20 pointer-events-none"
          style={{ left: CENTER - 1, width: 2, background: 'var(--neon-gold)', boxShadow: '0 0 12px var(--neon-gold)' }} />
        <div className="absolute top-0 z-20 pointer-events-none"
          style={{ left: CENTER - 7, width: 0, height: 0, borderLeft: '7px solid transparent', borderRight: '7px solid transparent', borderTop: '9px solid var(--neon-gold)' }} />
        <div className="absolute bottom-0 z-20 pointer-events-none"
          style={{ left: CENTER - 7, width: 0, height: 0, borderLeft: '7px solid transparent', borderRight: '7px solid transparent', borderBottom: '9px solid var(--neon-gold)' }} />

        {/* Strip — velocity blur applied to whole strip */}
        <div style={{
          position: 'absolute', top: 12, left: 0,
          display: 'flex', gap: TILE_GAP,
          transform: `translateX(${stripX}px)`,
          willChange: 'transform',
          filter: blur > 0.5 ? `blur(${blur.toFixed(1)}px)` : 'none',
        }}>
          {tiles.map((bet, i) => {
            const highlight = bet._isWinner && revealed
            const name      = getDisplayName(bet)
            return (
              <div key={i} style={{
                width: TILE_W, height: 80, flexShrink: 0,
                borderRadius: 12, boxSizing: 'border-box',
                display: 'flex', flexDirection: 'column',
                alignItems: 'center', justifyContent: 'center', gap: 4,
                background: highlight ? 'rgba(245,158,11,0.2)'  : 'rgba(255,255,255,0.04)',
                border:     highlight ? '1px solid rgba(245,158,11,0.8)' : '1px solid rgba(255,255,255,0.06)',
                boxShadow:  highlight ? '0 0 28px rgba(245,158,11,0.6)' : 'none',
                transition: 'background 0.4s, border 0.4s, box-shadow 0.4s',
              }}>
                <div style={{
                  width: 36, height: 36, borderRadius: '50%',
                  background: avatarColor(bet.email),
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: 11, fontWeight: 700, color: '#fff',
                  boxShadow: highlight ? `0 0 12px ${avatarColor(bet.email)}` : 'none',
                  transition: 'box-shadow 0.4s',
                }}>
                  {name.slice(0, 2).toUpperCase()}
                </div>
                <span style={{
                  fontSize: 11, color: highlight ? 'var(--neon-gold)' : 'var(--text-muted)',
                  maxWidth: TILE_W - 8, overflow: 'hidden',
                  textOverflow: 'ellipsis', whiteSpace: 'nowrap', textAlign: 'center',
                  fontWeight: highlight ? 700 : 400,
                  transition: 'color 0.4s',
                }}>
                  {name}
                </span>
              </div>
            )
          })}
        </div>
      </div>

      {/* Winner reveal card — stays until new round */}
      <AnimatePresence>
        {revealed && (
          <motion.div
            initial={{ opacity: 0, scale: 0.7, y: 20 }}
            animate={{ opacity: 1, scale: 1,   y: 0 }}
            exit={{    opacity: 0, scale: 0.9,  y: -10 }}
            transition={{ type: 'spring', stiffness: 280, damping: 22 }}
            className="flex flex-col items-center gap-2"
          >
            <motion.div
              animate={{ boxShadow: [
                '0 0 20px rgba(245,158,11,0.4)',
                '0 0 50px rgba(245,158,11,0.9)',
                '0 0 20px rgba(245,158,11,0.4)',
              ]}}
              transition={{ duration: 1.8, repeat: Infinity, ease: 'easeInOut' }}
              style={{
                width: 68, height: 68, borderRadius: '50%',
                background: avatarColor(winner.email),
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                fontSize: 24, fontWeight: 900, color: '#fff',
              }}
            >
              {(winner.display_name ?? winner.email).slice(0, 2).toUpperCase()}
            </motion.div>

            <div className="text-2xl font-black" style={{ color: 'var(--neon-gold)' }}>
              {winner.display_name ?? winner.email.split('@')[0]}
            </div>

            {pot != null && (
              <motion.div
                initial={{ opacity: 0, scale: 0.8 }}
                animate={{ opacity: 1, scale: 1 }}
                transition={{ delay: 0.25, type: 'spring', stiffness: 300 }}
                className="text-3xl font-black"
                style={{
                  background: 'linear-gradient(135deg, #f59e0b, #fbbf24)',
                  WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent', backgroundClip: 'text',
                  filter: 'drop-shadow(0 0 14px rgba(245,158,11,0.7))',
                }}
              >
                +${parseFloat(pot).toFixed(2)}
              </motion.div>
            )}

            <div className="text-sm" style={{ color: 'var(--text-muted)' }}>wins the pot!</div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}
