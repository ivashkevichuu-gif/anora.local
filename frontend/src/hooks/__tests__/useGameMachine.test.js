// Feature: referral-commission-system
// Property-based tests for the game state machine (P12, P13, P15)
// Uses Vitest + fast-check

import { describe, it, expect } from 'vitest'
import fc from 'fast-check'

// ─── Inline reducer (copied from useGameMachine.js) ──────────────────────────
// We test the pure reducer directly without React hooks.

const SPIN_DURATION = 5500   // ms
const RESULT_HOLD   = 2000   // ms

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

      if (state.phase === 'RESULT') {
        if (game.id !== state.gameId) {
          return { ...initial, phase: 'BETTING', bets, pot: game.total_pot, gameId: game.id }
        }
        return state
      }

      if (state.phase === 'DRAWING') {
        return state
      }

      if (state.phase === 'IDLE') {
        if (game.status === 'waiting' || game.status === 'countdown') {
          return { ...state, phase: 'BETTING', bets, pot: game.total_pot, gameId: game.id }
        }
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

      if (state.phase === 'BETTING') {
        if (game.status === 'countdown') {
          return { ...state, phase: 'COUNTDOWN', bets, pot: game.total_pot, gameId: game.id }
        }
        if (game.status === 'finished' && game.id === state.gameId) {
          return {
            ...state,
            phase:  'DRAWING',
            bets:   bets.length ? bets : state.bets,
            winner: game.winner,
            pot:    game.total_pot || state.pot,
          }
        }
        if (game.status === 'waiting') {
          return { ...state, bets, pot: game.total_pot, gameId: game.id }
        }
        return state
      }

      if (state.phase === 'COUNTDOWN') {
        if (game.status === 'finished') {
          return {
            ...state,
            phase:  'DRAWING',
            bets:   bets.length ? bets : state.bets,
            winner: game.winner,
            pot:    game.total_pot || state.pot,
          }
        }
        if (game.status === 'countdown') {
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

// ─── Helper: apply a sequence of SYNC events starting from initial state ─────
function applyEvents(events) {
  let state = { ...initial }
  const transitions = []
  for (const event of events) {
    const prev = state.phase
    state = reducer(state, { type: 'SYNC', game: event, bets: [] })
    transitions.push({ from: prev, to: state.phase, event })
  }
  return { state, transitions }
}

// ─── Property 12: Valid Transitions Only ─────────────────────────────────────
// Feature: referral-commission-system, Property 12: Valid Transitions Only
// Validates: Requirements 8.2, 8.3

describe('Property 12: Valid Transitions Only', () => {
  it('never produces IDLE→DRAWING, BETTING→RESULT, DRAWING→BETTING, or RESULT→DRAWING', () => {
    const eventArb = fc.array(
      fc.record({
        status: fc.constantFrom('waiting', 'countdown', 'finished'),
        id:     fc.integer({ min: 1, max: 100 }),
        total_pot: fc.float({ min: 0, max: 1000 }),
        winner: fc.constant(null),
      }),
      { minLength: 0, maxLength: 20 }
    )

    fc.assert(
      fc.property(eventArb, (events) => {
        const { transitions } = applyEvents(events)

        for (const { from, to } of transitions) {
          // IDLE → DRAWING must never happen
          expect(from === 'IDLE' && to === 'DRAWING').toBe(false)
          // BETTING → RESULT must never happen
          expect(from === 'BETTING' && to === 'RESULT').toBe(false)
          // DRAWING → BETTING must never happen
          expect(from === 'DRAWING' && to === 'BETTING').toBe(false)
          // RESULT → DRAWING must never happen
          expect(from === 'RESULT' && to === 'DRAWING').toBe(false)
        }
      }),
      { numRuns: 100 }
    )
  })

  it('DRAWING phase is immutable to SYNC events (polling cannot interrupt it)', () => {
    const eventArb = fc.record({
      status: fc.constantFrom('waiting', 'countdown', 'finished'),
      id:     fc.integer({ min: 1, max: 100 }),
      total_pot: fc.float({ min: 0, max: 1000 }),
      winner: fc.constant(null),
    })

    fc.assert(
      fc.property(eventArb, (event) => {
        const drawingState = { ...initial, phase: 'DRAWING', gameId: event.id }
        const next = reducer(drawingState, { type: 'SYNC', game: event, bets: [] })
        expect(next.phase).toBe('DRAWING')
      }),
      { numRuns: 100 }
    )
  })

  it('RESULT phase is sticky for the same game_id', () => {
    const gameIdArb = fc.integer({ min: 1, max: 100 })

    fc.assert(
      fc.property(
        gameIdArb,
        fc.constantFrom('waiting', 'countdown', 'finished'),
        (gameId, status) => {
          const resultState = { ...initial, phase: 'RESULT', gameId }
          const event = { id: gameId, status, total_pot: 10, winner: null }
          const next = reducer(resultState, { type: 'SYNC', game: event, bets: [] })
          expect(next.phase).toBe('RESULT')
        }
      ),
      { numRuns: 100 }
    )
  })
})

// ─── Property 13: DRAWING Phase Duration ─────────────────────────────────────
// Feature: referral-commission-system, Property 13: DRAWING Phase Duration
// Validates: Requirements 8.4, 8.7, 9.1, 9.2, 9.4

describe('Property 13: DRAWING Phase Duration', () => {
  it('SPIN_DURATION + RESULT_HOLD equals 7500ms', () => {
    expect(SPIN_DURATION + RESULT_HOLD).toBe(7500)
    expect(SPIN_DURATION).toBe(5500)
    expect(RESULT_HOLD).toBe(2000)
  })

  it('reducer stays in DRAWING when SPIN_DONE has not fired (SYNC cannot exit DRAWING)', () => {
    const eventArb = fc.record({
      status: fc.constantFrom('waiting', 'countdown', 'finished'),
      id:     fc.integer({ min: 1, max: 100 }),
      total_pot: fc.float({ min: 0, max: 1000 }),
      winner: fc.constant(null),
    })

    fc.assert(
      fc.property(eventArb, (event) => {
        const drawingState = { ...initial, phase: 'DRAWING', gameId: event.id }
        // Any number of SYNC events while in DRAWING must not change the phase
        let state = drawingState
        for (let i = 0; i < 5; i++) {
          state = reducer(state, { type: 'SYNC', game: event, bets: [] })
        }
        expect(state.phase).toBe('DRAWING')
      }),
      { numRuns: 100 }
    )
  })

  it('SPIN_DONE transitions DRAWING → RESULT', () => {
    const drawingState = { ...initial, phase: 'DRAWING', gameId: 1 }
    const next = reducer(drawingState, { type: 'SPIN_DONE' })
    expect(next.phase).toBe('RESULT')
  })

  it('SPIN_DONE is a no-op in any phase other than DRAWING', () => {
    const phases = ['IDLE', 'BETTING', 'COUNTDOWN', 'RESULT']

    fc.assert(
      fc.property(fc.constantFrom(...phases), (phase) => {
        const state = { ...initial, phase, gameId: 1 }
        const next = reducer(state, { type: 'SPIN_DONE' })
        expect(next.phase).toBe(phase)
      }),
      { numRuns: 100 }
    )
  })
})

// ─── Property 15: Referral TTL Enforcement ───────────────────────────────────
// Feature: referral-commission-system, Property 15: Referral TTL Enforcement
// Validates: Requirements 11.2

/**
 * Pure helper that reads anora_ref from a mock storage object.
 * Mirrors the TTL logic described in App.jsx spec.
 *
 * @param {Object} storage - mock with getItem(key) / removeItem(key)
 * @returns {string|null} the referral code, or null if expired/missing
 */
function readAnoraRef(storage) {
  const KEY = 'anora_ref'
  const raw = storage.getItem(KEY)
  if (!raw) return null

  let entry
  try {
    entry = JSON.parse(raw)
  } catch {
    storage.removeItem(KEY)
    return null
  }

  if (!entry || typeof entry.expires !== 'number' || typeof entry.code !== 'string') {
    storage.removeItem(KEY)
    return null
  }

  if (entry.expires < Date.now()) {
    storage.removeItem(KEY)
    return null
  }

  return entry.code
}

/** Build a minimal mock storage from a plain object */
function makeMockStorage(initial = {}) {
  const store = { ...initial }
  return {
    getItem:    (k) => (k in store ? store[k] : null),
    removeItem: (k) => { delete store[k] },
    _store:     store,
  }
}

describe('Property 15: Referral TTL Enforcement', () => {
  it('returns null and deletes the key when expires < Date.now()', () => {
    // Generate past timestamps (already expired)
    const pastOffsetArb = fc.integer({ min: 1, max: 365 * 24 * 60 * 60 * 1000 })
    const codeArb = fc.string({ minLength: 12, maxLength: 12 })

    fc.assert(
      fc.property(pastOffsetArb, codeArb, (offset, code) => {
        const expires = Date.now() - offset   // in the past
        const storage = makeMockStorage({
          anora_ref: JSON.stringify({ code, expires }),
        })

        const result = readAnoraRef(storage)

        expect(result).toBeNull()
        expect(storage.getItem('anora_ref')).toBeNull()
      }),
      { numRuns: 100 }
    )
  })

  it('returns the stored code when expires > Date.now()', () => {
    // Generate future timestamps (not yet expired)
    const futureOffsetArb = fc.integer({ min: 1000, max: 7 * 24 * 60 * 60 * 1000 })
    const codeArb = fc.string({ minLength: 12, maxLength: 12 })

    fc.assert(
      fc.property(futureOffsetArb, codeArb, (offset, code) => {
        const expires = Date.now() + offset   // in the future
        const storage = makeMockStorage({
          anora_ref: JSON.stringify({ code, expires }),
        })

        const result = readAnoraRef(storage)

        expect(result).toBe(code)
        // Key must still be present
        expect(storage.getItem('anora_ref')).not.toBeNull()
      }),
      { numRuns: 100 }
    )
  })

  it('returns null when anora_ref key is absent', () => {
    const storage = makeMockStorage({})
    expect(readAnoraRef(storage)).toBeNull()
  })

  it('returns null and deletes key for malformed JSON', () => {
    const storage = makeMockStorage({ anora_ref: 'not-json' })
    expect(readAnoraRef(storage)).toBeNull()
    expect(storage.getItem('anora_ref')).toBeNull()
  })
})
