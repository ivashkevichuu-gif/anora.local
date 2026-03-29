import { useRef, useState, useCallback } from 'react'

/**
 * Minimal Web Audio API sound system.
 * No external files — generates tones programmatically.
 * Default: sound OFF.
 */
export function useSound() {
  const [enabled, setEnabled] = useState(false)
  const ctxRef = useRef(null)

  const getCtx = useCallback(() => {
    if (!ctxRef.current) {
      ctxRef.current = new (window.AudioContext || window.webkitAudioContext)()
    }
    return ctxRef.current
  }, [])

  const play = useCallback((type) => {
    if (!enabled) return
    try {
      const ctx = getCtx()
      const osc = ctx.createOscillator()
      const gain = ctx.createGain()
      osc.connect(gain)
      gain.connect(ctx.destination)

      if (type === 'bet') {
        osc.frequency.setValueAtTime(440, ctx.currentTime)
        osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.1)
        gain.gain.setValueAtTime(0.15, ctx.currentTime)
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15)
        osc.start(ctx.currentTime)
        osc.stop(ctx.currentTime + 0.15)
      } else if (type === 'win') {
        // Ascending fanfare
        const notes = [523, 659, 784, 1047]
        notes.forEach((freq, i) => {
          const o = ctx.createOscillator()
          const g = ctx.createGain()
          o.connect(g); g.connect(ctx.destination)
          o.frequency.value = freq
          o.type = 'sine'
          const t = ctx.currentTime + i * 0.12
          g.gain.setValueAtTime(0, t)
          g.gain.linearRampToValueAtTime(0.2, t + 0.05)
          g.gain.exponentialRampToValueAtTime(0.001, t + 0.3)
          o.start(t); o.stop(t + 0.3)
        })
      } else if (type === 'tick') {
        osc.frequency.value = 220
        osc.type = 'square'
        gain.gain.setValueAtTime(0.05, ctx.currentTime)
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.05)
        osc.start(ctx.currentTime)
        osc.stop(ctx.currentTime + 0.05)
      }
    } catch { /* AudioContext blocked — ignore */ }
  }, [enabled, getCtx])

  const toggle = useCallback(() => setEnabled(e => !e), [])

  return { enabled, toggle, play }
}
