import { useState, useEffect, useRef } from 'react'

/**
 * Simplified UI state machine for the lottery panel.
 *
 * Maps backend game status directly to UI phases:
 *   'waiting'  → BETTING
 *   'active'   → COUNTDOWN
 *   'spinning' → DRAWING
 *   'finished' → RESULT
 *
 * The frontend is a pure display layer — no local state transitions.
 * Only two timers remain for animation purposes:
 *   SPIN_DURATION — keep the carousel spinning even though backend already picked winner
 *   RESULT_HOLD   — show the winner card before the next round's BETTING phase
 */

const SPIN_DURATION = 5500   // ms — carousel animation duration
const RESULT_HOLD   = 3000   // ms — show result before transitioning to next round

export function useGameMachine(lotteryState) {
  const [override, setOverride] = useState(null) // 'DRAWING' or 'RESULT' override
  const timerRef   = useRef(null)
  const prevGameId = useRef(null)

  const game = lotteryState?.game
  const bets = lotteryState?.bets ?? []

  const backendStatus = game?.status ?? null
  const gameId        = game?.round_id ?? null

  // Detect new round — clear overrides when game ID changes
  useEffect(() => {
    if (gameId !== null && prevGameId.current !== null && gameId !== prevGameId.current) {
      // New round started — clear any lingering override
      clearTimeout(timerRef.current)
      setOverride(null)
    }
    prevGameId.current = gameId
  }, [gameId])

  // When backend reports 'spinning', start DRAWING animation timer
  useEffect(() => {
    if (backendStatus === 'spinning' && override !== 'DRAWING' && override !== 'RESULT') {
      setOverride('DRAWING')
      clearTimeout(timerRef.current)
      timerRef.current = setTimeout(() => {
        setOverride('RESULT')
      }, SPIN_DURATION)
    }
  }, [backendStatus, override])

  // When backend reports 'finished' and we haven't started DRAWING yet,
  // go straight to DRAWING animation then RESULT
  useEffect(() => {
    if (backendStatus === 'finished' && override === null) {
      setOverride('DRAWING')
      clearTimeout(timerRef.current)
      timerRef.current = setTimeout(() => {
        setOverride('RESULT')
      }, SPIN_DURATION)
    }
  }, [backendStatus, override])

  // When in RESULT and backend moves to a new round (waiting/active), hold briefly then clear
  useEffect(() => {
    if (override === 'RESULT' && (backendStatus === 'waiting' || backendStatus === 'active')) {
      clearTimeout(timerRef.current)
      timerRef.current = setTimeout(() => {
        setOverride(null)
      }, RESULT_HOLD)
    }
  }, [override, backendStatus])

  // Cleanup timer on unmount
  useEffect(() => {
    return () => clearTimeout(timerRef.current)
  }, [])

  // Determine current UI phase
  let phase
  if (override) {
    phase = override
  } else {
    switch (backendStatus) {
      case 'waiting':  phase = 'BETTING';   break
      case 'active':   phase = 'COUNTDOWN'; break
      case 'spinning': phase = 'DRAWING';   break
      case 'finished': phase = 'RESULT';    break
      default:         phase = 'IDLE';      break
    }
  }

  return {
    phase,
    bets,
    winner: game?.winner ?? null,
    pot:    game?.total_pot ?? 0,
    gameId,
  }
}
