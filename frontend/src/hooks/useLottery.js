import { useState, useEffect, useRef, useCallback } from 'react'
import { api } from '../api/client'

// FIXED: generate a fresh client_seed per bet (not per session)
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
  // FIXED: track the seed used for the last bet (for display/audit)
  const [lastClientSeed, setLastClientSeed] = useState(() => generateClientSeed())

  const intervalRef   = useRef(null)
  const pendingBetRef = useRef(false)
  const onBalanceRef  = useRef(onBalanceUpdate)
  useEffect(() => { onBalanceRef.current = onBalanceUpdate }, [onBalanceUpdate])

  // Reset game state when room changes
  useEffect(() => {
    setState(null)
    setPrevious(null)
    setBetError(null)
  }, [room])

  const fetchStatus = useCallback(async () => {
    try {
      const d = await api.lotteryStatus(room)
      setState(d.current)
      setPrevious(d.previous)
      setUserId(d.user_id != null ? Number(d.user_id) : null)
      if (d.balance != null) {
        setBalance(d.balance)
        onBalanceRef.current?.(d.balance)
      }
    } catch { /* silent */ }
  }, [room])

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

    // FIXED: generate a fresh seed for each individual bet
    const seed = generateClientSeed()
    setLastClientSeed(seed)

    try {
      const d = await api.lotteryBet(room, seed)
      setState(d.state)
      if (d.balance != null) {
        setBalance(d.balance)
        onBalanceRef.current?.(d.balance)
      }
    } catch (e) {
      setBetError(e.message)
    } finally {
      setBetting(false)
      pendingBetRef.current = false
    }
  }, [room])

  return {
    state, previous, userId, balance, betting, betError, placeBet,
    clientSeed: lastClientSeed,  // seed used for the most recent bet
  }
}
