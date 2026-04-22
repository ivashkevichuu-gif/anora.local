import { useRef, useEffect } from 'react'
import { motion, AnimatePresence } from 'framer-motion'
import { useAuth }        from '../../context/AuthContext'
import { useLottery }     from '../../hooks/useLottery'
import { useGameMachine } from '../../hooks/useGameMachine'
import { useSound }       from '../../hooks/useSound'
import PotDisplay         from './PotDisplay'
import CountdownTimer     from './CountdownTimer'
import Participants       from './Participants'
import PlaceBetButton     from './PlaceBetButton'
import WinnerAnimation    from './WinnerAnimation'
import BetsTable          from './BetsTable'
import PreviousGame       from './PreviousGame'

export default function LotteryPanel({ room = 1 }) {
  const { setUser } = useAuth()
  const { enabled: soundOn, toggle: toggleSound, play } = useSound()

  // Buffer balance updates during DRAWING + RESULT phases — release when animation ends
  const phaseRef = useRef('IDLE')
  const pendingBalanceRef = useRef(null)
  const preAnimBalanceRef = useRef(null) // balance before animation started

  const onBalanceUpdate = useRef((b, backendStatus) => {
    const p = phaseRef.current
    // Buffer balance during animation phases OR when backend reports spinning/finished
    // (the phaseRef may lag behind by one render cycle, so check backend status too)
    const isAnimating = p === 'DRAWING' || p === 'RESULT'
    const backendAnimating = backendStatus === 'spinning' || backendStatus === 'finished'
    if (isAnimating || backendAnimating) {
      // Hold the balance update — don't show it during animation
      pendingBalanceRef.current = b
      return
    }
    setUser(u => u ? { ...u, balance: b } : u)
  }).current

  const { state: lotteryState, previous, userId, betting, betError, placeBet, clientSeed } = useLottery(onBalanceUpdate, room)

  // ── State machine ──────────────────────────────────────────────────────────
  const machine = useGameMachine(lotteryState)
  const prevPhaseRef = useRef(machine.phase)

  // Track phase for balance buffering
  useEffect(() => {
    const prev = phaseRef.current
    phaseRef.current = machine.phase

    // Entering DRAWING — snapshot current balance
    if (machine.phase === 'DRAWING' && prev !== 'DRAWING') {
      preAnimBalanceRef.current = pendingBalanceRef.current
    }

    // Animation ended (back to BETTING/COUNTDOWN) — flush pending balance with win flash
    if (prev === 'RESULT' && machine.phase !== 'RESULT' && machine.phase !== 'DRAWING') {
      if (pendingBalanceRef.current !== null) {
        const b = pendingBalanceRef.current
        pendingBalanceRef.current = null
        preAnimBalanceRef.current = null
        setUser(u => u ? { ...u, balance: b } : u)
      }
    }

    // Safety: if we somehow skip RESULT and go straight to BETTING
    if (machine.phase !== 'DRAWING' && machine.phase !== 'RESULT' && pendingBalanceRef.current !== null) {
      const b = pendingBalanceRef.current
      pendingBalanceRef.current = null
      preAnimBalanceRef.current = null
      setUser(u => u ? { ...u, balance: b } : u)
    }
  }, [machine.phase, setUser])

  // Freeze "previous round" data during win animation — only update after animation ends
  const frozenPreviousRef = useRef(previous)
  useEffect(() => {
    const isAnimating = machine.phase === 'DRAWING' || machine.phase === 'RESULT'
    // Always accept first non-null value (initial load)
    if (frozenPreviousRef.current === null && previous !== null) {
      frozenPreviousRef.current = previous
    } else if (!isAnimating) {
      frozenPreviousRef.current = previous
    }
  }, [machine.phase, previous])
  // Use frozen value during animation, live value otherwise
  const displayPrevious = (machine.phase === 'DRAWING' || machine.phase === 'RESULT')
    ? frozenPreviousRef.current
    : previous

  // Sound effects driven by machine phase transitions
  useEffect(() => {
    const prev = prevPhaseRef.current
    if (prev === 'DRAWING' && machine.phase === 'RESULT') play('win')
    prevPhaseRef.current = machine.phase
  }, [machine.phase, play])

  // Tick sound from backend countdown
  const prevCountRef = useRef(null)
  useEffect(() => {
    const cd = lotteryState?.game?.countdown
    if ((machine.phase === 'BETTING' || machine.phase === 'COUNTDOWN') && cd !== null && cd <= 5 && cd > 0 && cd !== prevCountRef.current) {
      play('tick')
    }
    prevCountRef.current = cd
  }, [lotteryState?.game?.countdown, machine.phase, play])

  const handleBet = async () => {
    await placeBet()
    play('bet')
  }

  // Freeze bets table during animation too
  const frozenBetsRef = useRef(lotteryState?.bets ?? [])
  useEffect(() => {
    const isAnimating = machine.phase === 'DRAWING' || machine.phase === 'RESULT'
    if (!isAnimating && lotteryState?.bets?.length) {
      frozenBetsRef.current = lotteryState.bets
    }
  }, [machine.phase, lotteryState?.bets])
  const displayBetsTable = (machine.phase === 'DRAWING' || machine.phase === 'RESULT')
    ? frozenBetsRef.current
    : (lotteryState?.bets ?? [])

  // ── Derived display values ─────────────────────────────────────────────────
  const game    = lotteryState?.game
  const bets    = machine.phase === 'BETTING' ? (lotteryState?.bets ?? []) : machine.bets
  const myStats = lotteryState?.my_stats

  // Betting allowed in BETTING and COUNTDOWN phases (backend accepts bets in waiting/active)
  const canBet = !!userId
    && (machine.phase === 'BETTING' || machine.phase === 'COUNTDOWN')
    && game?.status !== 'spinning'
    && game?.status !== 'finished'

  // UI freeze overlay: show when in DRAWING or RESULT phase
  const uiLocked = machine.phase === 'DRAWING' || machine.phase === 'RESULT'

  const statusColor  = game?.status === 'active' ? 'rgba(0,255,136,0.1)'  : game?.status === 'finished' ? 'rgba(239,68,68,0.1)'  : 'rgba(255,255,255,0.05)'
  const statusBorder = game?.status === 'active' ? 'rgba(0,255,136,0.3)'  : game?.status === 'finished' ? 'rgba(239,68,68,0.3)'  : 'rgba(255,255,255,0.1)'
  const statusText   = game?.status === 'active' ? 'var(--neon-green)'    : game?.status === 'finished' ? '#ef4444'               : 'var(--text-muted)'

  return (
    <div className="flex flex-col gap-6 max-w-2xl mx-auto">

      {/* ── Main panel ── */}
      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        className="rounded-3xl p-8 flex flex-col items-center gap-8 relative"
        style={{
          background: 'linear-gradient(145deg, rgba(124,58,237,0.08), rgba(0,255,136,0.04))',
          border: '1px solid rgba(255,255,255,0.08)',
          boxShadow: '0 0 60px rgba(124,58,237,0.08), inset 0 1px 0 rgba(255,255,255,0.05)',
        }}
      >
        {/* Freeze overlay during spin — blurred background + winner animation on top */}
        <AnimatePresence>
          {uiLocked && (
            <motion.div
              key="winner-overlay"
              initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
              className="absolute inset-0 rounded-3xl z-20 flex flex-col items-center justify-center"
              style={{
                background: 'rgba(0,0,0,0.55)',
                backdropFilter: 'blur(8px)',
                WebkitBackdropFilter: 'blur(8px)',
              }}
            >
              <WinnerAnimation
                bets={machine.bets}
                winner={machine.winner}
                pot={machine.pot}
                phase={machine.phase}
              />
            </motion.div>
          )}
        </AnimatePresence>

        {/* Top bar */}
        <div className="w-full flex items-center justify-between">
          <div className="flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-semibold"
            style={{ background: statusColor, border: `1px solid ${statusBorder}`, color: statusText }}>
            <span className="w-1.5 h-1.5 rounded-full inline-block"
              style={{ background: statusText, boxShadow: game?.status === 'active' ? '0 0 6px var(--neon-green)' : 'none' }} />
            {machine.phase === 'RESULT'  ? 'Round finished'
           : game?.status === 'waiting'  ? 'Waiting for players'
           : game?.status === 'active'   ? 'Round in progress'
           : game?.status === 'spinning' ? 'Drawing winner…'
           : 'Round finished'}
          </div>

          <button onClick={toggleSound}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold"
            style={{
              background: soundOn ? 'rgba(0,255,136,0.1)' : 'rgba(255,255,255,0.05)',
              border: `1px solid ${soundOn ? 'rgba(0,255,136,0.3)' : 'rgba(255,255,255,0.1)'}`,
              color: soundOn ? 'var(--neon-green)' : 'var(--text-muted)', cursor: 'pointer',
            }}>
            <i className={`bi ${soundOn ? 'bi-volume-up-fill' : 'bi-volume-mute-fill'}`}></i>
            {soundOn ? 'Sound ON' : 'Sound OFF'}
          </button>
        </div>

        {/* Pot + Timer */}
        <div className="flex items-center justify-around w-full gap-8 flex-wrap">
          <PotDisplay pot={machine.pot || game?.total_pot || 0} />
          <CountdownTimer countdown={game?.countdown} status={game?.status ?? 'waiting'} />
        </div>

        {/* Participants — show betting bets during BETTING, machine bets otherwise */}
        <div className="w-full">
          <Participants bets={bets} myUserId={userId} />
        </div>

        {/* My stats */}
        {userId && myStats && myStats.total_bets > 0 && (machine.phase === 'BETTING' || machine.phase === 'COUNTDOWN') && (
          <motion.div
            initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }}
            className="w-full px-5 py-3 rounded-xl"
            style={{ background: 'rgba(124,58,237,0.1)', border: '1px solid rgba(124,58,237,0.25)' }}
          >
            <div className="flex items-center justify-between mb-2">
              <div className="flex items-center gap-2">
                <i className="bi bi-ticket-perforated-fill" style={{ color: 'var(--neon-purple)' }}></i>
                <span className="text-sm font-semibold" style={{ color: 'var(--text)' }}>
                  My bets: <span style={{ color: 'var(--neon-purple)' }}>{myStats.total_bets}</span>
                </span>
              </div>
              <div className="flex items-center gap-4">
                <span className="text-sm" style={{ color: 'var(--text-muted)' }}>
                  Staked: <span className="font-bold" style={{ color: 'var(--neon-green)' }}>${myStats.total_bet.toFixed(2)}</span>
                </span>
                <span className="text-sm font-bold" style={{ color: 'var(--neon-gold)' }}>
                  {(myStats.chance * 100).toFixed(1)}% chance
                </span>
              </div>
            </div>
            <div className="h-1.5 rounded-full overflow-hidden" style={{ background: 'rgba(255,255,255,0.08)' }}>
              <motion.div className="h-full rounded-full"
                style={{ background: 'linear-gradient(90deg, #7c3aed, #a855f7)', boxShadow: '0 0 8px rgba(168,85,247,0.5)' }}
                animate={{ width: `${(myStats.chance * 100).toFixed(1)}%` }}
                transition={{ duration: 0.5 }} />
            </div>
            <div className="text-xs mt-1" style={{
              color: myStats.chance > 0.5 ? '#00ff88' : myStats.chance > 0.2 ? '#f59e0b' : '#a855f7', opacity: 0.8,
            }}>
              {myStats.chance > 0.5 ? '🟢 High chance · Lower reward' : myStats.chance > 0.2 ? '🟡 Medium chance' : '🟣 Low chance · High reward'}
            </div>
          </motion.div>
        )}

        {/* Bet button */}
        <PlaceBetButton onBet={handleBet} disabled={!canBet} loading={betting} isGuest={!userId} betAmount={room} />

        {/* Error */}
        <AnimatePresence>
          {betError && (
            <motion.div initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }}
              className="text-sm px-4 py-2 rounded-lg"
              style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171', border: '1px solid rgba(239,68,68,0.2)' }}>
              {betError}
            </motion.div>
          )}
        </AnimatePresence>

        {/* Provably fair */}
        <div className="w-full space-y-1.5">
          <div className="flex items-center gap-2">
            <span className="text-xs flex items-center gap-1 px-2 py-1 rounded-full"
              style={{ background: 'rgba(0,255,136,0.08)', border: '1px solid rgba(0,255,136,0.2)', color: 'var(--neon-green)' }}>
              <i className="bi bi-shield-check"></i>
              Provably Fair · Verifiable
            </span>
            {game?.round_id && (
              <a href={`/backend/api/game/verify.php?game_id=${game.round_id}`} target="_blank" rel="noreferrer"
                className="text-xs flex items-center gap-1"
                style={{ color: 'var(--text-muted)', textDecoration: 'none' }}>
                <i className="bi bi-box-arrow-up-right"></i>
                Verify #{game.round_id}
              </a>
            )}
          </div>
          {userId && (
            <div className="text-xs flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
              Last seed:
              <span className="font-mono ml-1" style={{ color: 'var(--text)', fontSize: 10 }}>{clientSeed}</span>
            </div>
          )}
        </div>
      </motion.div>

      {/* ── Live bets ── */}
      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15 }}>
        <BetsTable bets={displayBetsTable} myUserId={userId} />
      </motion.div>

      {/* ── Previous game ── */}
      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.25 }}>
        <PreviousGame game={displayPrevious} />
      </motion.div>
    </div>
  )
}
