import { useState, useEffect, useRef, useCallback } from 'react'
import { api } from '../api/client'

// Generate a fresh client_seed per bet
// Uses 4 × uint32 for 128 bits of entropy, formatted as dash-separated decimals
function generateClientSeed() {
  const arr = new Uint32Array(4)
  crypto.getRandomValues(arr)
  return Array.from(arr).join('-')
}

export function useLottery(onBalanceUpdate, room = 1) {
  const [state, setState]         = useState(null)
  const [previous, setPrevious]   = useState(null)
  const [userId, setUserId]       = useState(null)
  const [balance, setBalance]     = useState(null)
  const [betting, setBetting]     = useState(false)
  const [betError, setBetError]   = useState(null)
  const [loading, setLoading]     = useState(true)
  const [lastClientSeed, setLastClientSeed] = useState(() => generateClientSeed())

  const intervalRef   = useRef(null)
  const pendingBetRef = useRef(false)
  const onBalanceRef  = useRef(onBalanceUpdate)
  useEffect(() => { onBalanceRef.current = onBalanceUpdate }, [onBalanceUpdate])

  // Reset only error when room changes — keep state visible until new data arrives
  useEffect(() => {
    setBetError(null)
    setLoading(true)
  }, [room])

  // Map the status endpoint response shape to the state used by components
  // status.php returns: { game: {round_id, status, ...}, bets, stats, my_stats, previous, balance }
  const mapStatusResponse = useCallback((d) => {
    const game = d.game ? {
      round_id:         d.game.round_id,
      status:           d.game.status,
      total_pot:        d.game.total_pot,
      countdown:        d.game.countdown,
      winner:           d.game.winner,
      server_seed_hash: d.game.server_seed_hash,
      server_seed:      d.game.server_seed,
      room:             d.game.room,
    } : null

    return {
      game,
      bets:     d.bets ?? [],
      stats:    d.stats ?? { unique_players: 0, total_bets: 0 },
      my_stats: d.my_stats ?? null,
    }
  }, [])

  // Map the bet endpoint response shape (raw getGameState result)
  // bet.php returns: { ok, state: { round, bets, unique_players, total_bets, my_stats, previous }, balance }
  const mapBetResponse = useCallback((raw) => {
    const game = raw.round ? {
      round_id:         raw.round.round_id,
      status:           raw.round.status,
      total_pot:        raw.round.total_pot,
      countdown:        raw.round.countdown,
      winner:           raw.round.winner,
      server_seed_hash: raw.round.server_seed_hash,
      server_seed:      raw.round.server_seed,
      room:             raw.round.room,
    } : null

    return {
      game,
      bets:     raw.bets ?? [],
      stats:    { unique_players: raw.unique_players ?? 0, total_bets: raw.total_bets ?? 0 },
      my_stats: raw.my_stats ?? null,
    }
  }, [])

  const fetchStatus = useCallback(async () => {
    try {
      const d = await api.gameStatus(room)
      const mapped = mapStatusResponse(d)
      setState(mapped)
      setPrevious(d.previous ?? null)
      setLoading(false)

      // Extract userId from my_stats presence or balance
      if (d.balance != null) {
        setUserId(prev => prev) // keep existing userId
        setBalance(d.balance)
        onBalanceRef.current?.(d.balance, d.game?.status)
      }

      // Try to get userId from auth context (me endpoint sets it)
      // The status endpoint doesn't return user_id directly,
      // but if my_stats is non-null, the user is logged in
      if (d.my_stats !== null && d.my_stats !== undefined) {
        // User is authenticated — userId is set via auth context
      }
    } catch { /* silent */ }
  }, [room, mapStatusResponse])

  useEffect(() => {
    // Also fetch the user's identity on mount
    const initUser = async () => {
      try {
        const me = await api.me()
        if (me?.user?.id) setUserId(Number(me.user.id))
      } catch { /* not logged in */ }
    }
    initUser()
  }, [])

  useEffect(() => {
    fetchStatus()
    intervalRef.current = setInterval(fetchStatus, 1000)
    return () => clearInterval(intervalRef.current)
  }, [fetchStatus])

  const placeBet = useCallback(async () => {
    if (pendingBetRef.current) return
    pendingBetRef.current = true
    setBetError(null)
    setBetting(true)

    // Generate a fresh seed for each individual bet
    const seed = generateClientSeed()
    setLastClientSeed(seed)

    try {
      const d = await api.gameBet(room, seed)
      // The bet response returns { ok, state: <raw getGameState>, balance }
      if (d.state) {
        const mapped = mapBetResponse(d.state)
        setState(mapped)
      }
      if (d.balance != null) {
        setBalance(d.balance)
        onBalanceRef.current?.(d.balance, d.state?.round?.status)
      }
    } catch (e) {
      setBetError(e.message)
    } finally {
      setBetting(false)
      pendingBetRef.current = false
    }
  }, [room, mapBetResponse])

  return {
    state, previous, userId, balance, betting, betError, placeBet, loading,
    clientSeed: lastClientSeed,
  }
}
