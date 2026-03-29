import { useReducer, useEffect, useRef } from 'react'

/**
 * UI state machine for the lottery panel.
 *
 * Phases:
 *   IDLE     — no active round data yet
 *   BETTING  — round is open, players can bet
 *   SPINNING — countdown hit 0, animation running
 *   RESULT   — animation done, winner shown, carousel stays visible
 *
 * The machine is driven by backend polling data from useLottery.
 * It does NOT control the backend — it only maps backend state → UI phase.
 *
 * RESULT persists until the backend reports a NEW round (new game id).
 * That is the only trigger that collapses the carousel.
 */

const SPIN_DURATION = 5500   // ms — rAF animation duration
const RESULT_HOLD   = 2000   // ms — extra time to view winner before RESULT is set

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

      // ── SPINNING is owned by the timer — polling cannot interrupt it ────
      if (state.phase === 'SPINNING') {
        return state
      }

      // ── IDLE or BETTING: map backend status → UI phase ──────────────────
      if (game.status === 'waiting' || game.status === 'countdown') {
        return { ...state, phase: 'BETTING', bets, pot: game.total_pot, gameId: game.id }
      }

      if (game.status === 'finished') {
        // Coming from BETTING → start spin animation
        if (state.phase === 'BETTING' && game.id === state.gameId) {
          return {
            ...state,
            phase:  'SPINNING',
            bets:   bets.length ? bets : state.bets,
            winner: game.winner,
            pot:    game.total_pot || state.pot,
          }
        }

        // Coming from IDLE (page load while game already finished)
        // or BETTING with a different gameId (missed the transition) → go straight to RESULT
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

    case 'SPIN_DONE':
      if (state.phase !== 'SPINNING') return state
      return { ...state, phase: 'RESULT' }

    default:
      return state
  }
}

export function useGameMachine(lotteryState, previous) {
  const [machine, dispatch] = useReducer(reducer, initial)
  const spinTimerRef        = useRef(null)

  // Sync backend polling data into the machine
  useEffect(() => {
    if (!lotteryState) return
    dispatch({ type: 'SYNC', game: lotteryState.game, bets: lotteryState.bets ?? [] })
  }, [lotteryState])

  // When SPINNING starts, schedule SPIN_DONE after animation completes
  useEffect(() => {
    if (machine.phase === 'SPINNING') {
      spinTimerRef.current = setTimeout(() => {
        dispatch({ type: 'SPIN_DONE' })
      }, SPIN_DURATION + RESULT_HOLD)
    }
    return () => clearTimeout(spinTimerRef.current)
  }, [machine.phase])

  return machine
}
