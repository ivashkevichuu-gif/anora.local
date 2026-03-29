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

export default function LotteryPanel() {
  const { setUser } = useAuth()
  const { enabled: soundOn, toggle: toggleSound, play } = useSound()

  const onBalanceUpdate = useRef((b) => setUser(u => u ? { ...u, balance: b } : u)).current
  const { state: lotteryState, previous, userId, betting, betError, placeBet, clientSeed } = useLottery(onBalanceUpdate)

  // ── State machine ──────────────────────────────────────────────────────────
  const machine = useGameMachine(lotteryState, previous)
  const prevPhaseRef = useRef(machine.phase)

  // Sound effects driven by machine phase transitions
  useEffect(() => {
    const prev = prevPhaseRef.current
    if (prev === 'SPINNING' && machine.phase === 'RESULT') play('win')
    prevPhaseRef.current = machine.phase
  }, [machine.phase, play])

  // Tick sound from backend countdown
  const prevCountRef = useRef(null)
  useEffect(() => {
    const cd = lotteryState?.game?.countdown
    if (machine.phase === 'BETTING' && cd !== null && cd <= 5 && cd > 0 && cd !== prevCountRef.current) {
      play('tick')
    }
    prevCountRef.current = cd
  }, [lotteryState?.game?.countdown, machine.phase, play])

  const handleBet = async () => {
    await placeBet()
    play('bet')
  }

  // ── Derived display values ─────────────────────────────────────────────────
  const game    = lotteryState?.game
  const bets    = machine.phase === 'BETTING' ? (lotteryState?.bets ?? []) : machine.bets
  const myStats = lotteryState?.my_stats

  // Betting allowed only in BETTING phase
  const canBet = !!userId && machine.phase === 'BETTING'
    && game?.status !== 'finished'
    && !(game?.status === 'countdown' && (game?.countdown ?? 1) <= 0)

  // UI freeze overlay: show when countdown=0 and we're about to spin
  const uiLocked = machine.phase === 'SPINNING'

  const statusColor  = game?.status === 'countdown' ? 'rgba(0,255,136,0.1)'  : game?.status === 'finished' ? 'rgba(239,68,68,0.1)'  : 'rgba(255,255,255,0.05)'
  const statusBorder = game?.status === 'countdown' ? 'rgba(0,255,136,0.3)'  : game?.status === 'finished' ? 'rgba(239,68,68,0.3)'  : 'rgba(255,255,255,0.1)'
  const statusText   = game?.status === 'countdown' ? 'var(--neon-green)'    : game?.status === 'finished' ? '#ef4444'               : 'var(--text-muted)'

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
        {/* Freeze overlay during spin */}
        {uiLocked && (
          <motion.div
            initial={{ opacity: 0 }} animate={{ opacity: 1 }}
            className="absolute inset-0 rounded-3xl flex items-center justify-center z-20"
            style={{ background: 'rgba(0,0,0,0.6)', backdropFilter: 'blur(4px)' }}
          >
            <div className="flex flex-col items-center gap-3">
              <motion.div
                animate={{ rotate: 360 }}
                transition={{ duration: 1, repeat: Infinity, ease: 'linear' }}
                className="w-10 h-10 rounded-full border-2"
                style={{ borderColor: 'var(--neon-gold)', borderTopColor: 'transparent' }}
              />
              <span className="text-sm font-semibold" style={{ color: 'var(--neon-gold)' }}>
                Drawing winner…
              </span>
            </div>
          </motion.div>
        )}

        {/* Top bar */}
        <div className="w-full flex items-center justify-between">
          <div className="flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-semibold"
            style={{ background: statusColor, border: `1px solid ${statusBorder}`, color: statusText }}>
            <span className="w-1.5 h-1.5 rounded-full inline-block"
              style={{ background: statusText, boxShadow: game?.status === 'countdown' ? '0 0 6px var(--neon-green)' : 'none' }} />
            {machine.phase === 'RESULT'   ? 'Round finished'
           : game?.status === 'waiting'   ? 'Waiting for players'
           : game?.status === 'countdown' ? 'Round in progress'
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
        {userId && myStats && myStats.total_bets > 0 && machine.phase === 'BETTING' && (
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
        <PlaceBetButton onBet={handleBet} disabled={!canBet} loading={betting} isGuest={!userId} />

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
            {game?.id && (
              <a href={`/backend/api/lottery/verify.php?game_id=${game.id}`} target="_blank" rel="noreferrer"
                className="text-xs flex items-center gap-1"
                style={{ color: 'var(--text-muted)', textDecoration: 'none' }}>
                <i className="bi bi-box-arrow-up-right"></i>
                Verify #{game.id}
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

      {/* ── Winner animation — persistent, state-driven ── */}
      {/* Shown in SPINNING and RESULT. Collapses only when machine returns to BETTING (new round). */}
      <AnimatePresence>
        {(machine.phase === 'SPINNING' || machine.phase === 'RESULT') && (
          <motion.div
            key="winner-panel"
            initial={{ opacity: 0, scale: 0.95 }}
            animate={{ opacity: 1, scale: 1 }}
            exit={{ opacity: 0, scale: 0.95 }}
            className="rounded-3xl p-6"
            style={{
              background: 'linear-gradient(145deg, rgba(245,158,11,0.08), rgba(124,58,237,0.08))',
              border: '1px solid rgba(245,158,11,0.2)',
              boxShadow: '0 0 40px rgba(245,158,11,0.1)',
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

      {/* ── Live bets ── */}
      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15 }}>
        <BetsTable bets={lotteryState?.bets ?? []} myUserId={userId} />
      </motion.div>

      {/* ── Previous game ── */}
      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.25 }}>
        <PreviousGame game={previous} />
      </motion.div>
    </div>
  )
}
