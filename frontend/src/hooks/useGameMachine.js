import { useReducer, useEffect, useRef } from 'react'

/**
 * UI state machine for the lottery panel.
 *
 * Phases:
 *   IDLE      — no active round data yet
 *   BETTING   — round is open, players can bet
 *   COUNTDOWN — backend countdown in progress, betting closed
 *   DRAWING   — winner animation running
 *   RESULT    — animation done, winner shown, carousel stays visible
 *
 * Valid transitions:
 *   IDLE      → BETTING   : backend status is 'waiting' or 'countdown'
 *   BETTING   → COUNTDOWN : backend status changes to 'countdown'
 *   COUNTDOWN → DRAWING   : backend status changes to 'finished'
 *   DRAWING   → RESULT    : timer fires after SPIN_DURATION + RESULT_HOLD
 *   RESULT    → BETTING   : backend reports a new game_id
 *
 * Invalid transitions (rejected):
 *   IDLE → DRAWING, BETTING → RESULT, DRAWING → BETTING, RESULT → DRAWING
 *
 * The machine is driven by backend polling data from useLottery.
 * It does NOT control the backend — it only maps backend state → UI phase.
 */

const SPIN_DURATION = 5500   // ms — rAF animation duration
const RESULT_HOLD   = 2000   // ms — extra time to view winner before RESULT ends

const initial = {
  phase:   'IDLE',
  bets:    [],
  winner:  null,
  pot:     0,
  gameId:  null,
}

function reducer(state, action) {
  switch (action.type) {

    case 'SYNC': {
      const { game, bets } = action
      if (!game) return state

      // ── RESULT is sticky — only a new gameId breaks it ──────────────────
      if (state.phase === 'RESULT') {
        if (game.id !== state.gameId) {
          // New round started → collapse result, go to BETTING
          return { ...initial, phase: 'BETTING', bets, pot: game.total_pot, gameId: game.id }
        }
        return state   // same game, stay in RESULT
      }

      // ── DRAWING is owned by the timer — polling cannot interrupt it ─────
      if (state.phase === 'DRAWING') {
        return state
      }

      // ── IDLE: only allow transition to BETTING ───────────────────────────
      if (state.phase === 'IDLE') {
        if (game.status === 'waiting' || game.status === 'countdown') {
          return { ...state, phase: 'BETTING', bets, pot: game.total_pot, gameId: game.id }
        }
        // IDLE → DRAWING is invalid; if finished, skip to RESULT directly
        if (game.status === 'finished') {
          return {
            ...state,
            phase:  'RESULT',
            bets:   bets.length ? bets : state.bets,
            winner: game.winner,
            pot:    game.total_pot || state.pot,
            gameId: game.id,
          }
        }
        return state
      }

      // ── BETTING: can go to COUNTDOWN or DRAWING ──────────────────────────
      if (state.phase === 'BETTING') {
        if (game.status === 'countdown') {
          // BETTING → COUNTDOWN
          return { ...state, phase: 'COUNTDOWN', bets, pot: game.total_pot, gameId: game.id }
        }
        if (game.status === 'finished' && game.id === state.gameId) {
          // BETTING → DRAWING (missed countdown, same game)
          return {
            ...state,
            phase:  'DRAWING',
            bets:   bets.length ? bets : state.bets,
            winner: game.winner,
            pot:    game.total_pot || state.pot,
          }
        }
        if (game.status === 'waiting') {
          // Stay in BETTING, update data
          return { ...state, bets, pot: game.total_pot, gameId: game.id }
        }
        // BETTING → RESULT is invalid; ignore
        return state
      }

      // ── COUNTDOWN: can only go to DRAWING ───────────────────────────────
      if (state.phase === 'COUNTDOWN') {
        if (game.status === 'finished') {
          // COUNTDOWN → DRAWING
          return {
            ...state,
            phase:  'DRAWING',
            bets:   bets.length ? bets : state.bets,
            winner: game.winner,
            pot:    game.total_pot || state.pot,
          }
        }
        if (game.status === 'countdown') {
          // Stay in COUNTDOWN, update data
          return { ...state, bets, pot: game.total_pot }
        }
        return state
      }

      return state
    }

    case 'SPIN_DONE':
      if (state.phase !== 'DRAWING') return state
      return { ...state, phase: 'RESULT' }

    default:
      return state
  }
}

export function useGameMachine(lotteryState) {
  const [machine, dispatch] = useReducer(reducer, initial)
  const spinTimerRef        = useRef(null)

  // Sync backend polling data into the machine
  useEffect(() => {
    if (!lotteryState) return
    dispatch({ type: 'SYNC', game: lotteryState.game, bets: lotteryState.bets ?? [] })
  }, [lotteryState])

  // When DRAWING starts, schedule SPIN_DONE after animation completes
  useEffect(() => {
    if (machine.phase === 'DRAWING') {
      spinTimerRef.current = setTimeout(() => {
        dispatch({ type: 'SPIN_DONE' })
      }, SPIN_DURATION + RESULT_HOLD)
    }
    return () => clearTimeout(spinTimerRef.current)
  }, [machine.phase])

  return machine
}
