import { useState, useEffect, useRef } from 'react'

/**
 * PWA Install Banner — detects mobile OS and prompts to install.
 *
 * Android: uses beforeinstallprompt event (native Chrome prompt)
 * iOS: shows manual instructions (Add to Home Screen)
 * Desktop / already installed / dismissed: hidden
 */

function getOS() {
  const ua = navigator.userAgent || ''
  if (/iPad|iPhone|iPod/.test(ua) && !window.MSStream) return 'ios'
  if (/android/i.test(ua)) return 'android'
  return 'other'
}

function isStandalone() {
  return window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true
}

const DISMISS_KEY = 'anora_install_dismissed'
const DISMISS_DAYS = 7

export default function InstallBanner() {
  const [show, setShow] = useState(false)
  const [os, setOs] = useState('other')
  const [showIosGuide, setShowIosGuide] = useState(false)
  const deferredPrompt = useRef(null)

  useEffect(() => {
    // Don't show if already installed or recently dismissed
    if (isStandalone()) return
    const dismissed = localStorage.getItem(DISMISS_KEY)
    if (dismissed && Date.now() - parseInt(dismissed) < DISMISS_DAYS * 86400000) return

    const detectedOS = getOS()
    setOs(detectedOS)

    if (detectedOS === 'android') {
      // Wait for Chrome's beforeinstallprompt
      const handler = (e) => {
        e.preventDefault()
        deferredPrompt.current = e
        setShow(true)
      }
      window.addEventListener('beforeinstallprompt', handler)
      return () => window.removeEventListener('beforeinstallprompt', handler)
    }

    if (detectedOS === 'ios') {
      // iOS Safari doesn't fire beforeinstallprompt — show manual guide
      const isSafari = /Safari/.test(navigator.userAgent) && !/CriOS|FxiOS/.test(navigator.userAgent)
      if (isSafari) setShow(true)
    }
  }, [])

  const handleInstall = async () => {
    if (os === 'android' && deferredPrompt.current) {
      deferredPrompt.current.prompt()
      const result = await deferredPrompt.current.userChoice
      if (result.outcome === 'accepted') {
        setShow(false)
      }
      deferredPrompt.current = null
    } else if (os === 'ios') {
      setShowIosGuide(true)
    }
  }

  const handleDismiss = () => {
    localStorage.setItem(DISMISS_KEY, String(Date.now()))
    setShow(false)
    setShowIosGuide(false)
  }

  if (!show) return null

  return (
    <>
      {/* Banner */}
      <div style={{
        position: 'fixed', bottom: 0, left: 0, right: 0, zIndex: 9999,
        padding: '14px 16px',
        background: 'linear-gradient(135deg, rgba(11,15,26,0.97), rgba(17,24,39,0.97))',
        borderTop: '1px solid rgba(0,229,255,0.2)',
        backdropFilter: 'blur(20px)',
        display: 'flex', alignItems: 'center', gap: 12,
        animation: 'slideUp 0.4s ease-out',
      }}>
        {/* App icon */}
        <div style={{
          width: 48, height: 48, borderRadius: 12, flexShrink: 0,
          background: 'linear-gradient(135deg, #7A5CFF, #00E5FF)',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          fontSize: 20, fontWeight: 900, color: '#fff',
          boxShadow: '0 0 20px rgba(0,229,255,0.3)',
        }}>A</div>

        {/* Text */}
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 14, fontWeight: 700, color: '#E2E8F0' }}>
            Install ANORA
          </div>
          <div style={{ fontSize: 12, color: '#6B7280' }}>
            {os === 'ios' ? 'Add to Home Screen for the best experience' : 'Install the app for quick access'}
          </div>
        </div>

        {/* Install button */}
        <button onClick={handleInstall} style={{
          padding: '8px 18px', borderRadius: 20, border: 'none',
          background: 'linear-gradient(135deg, #00E5FF, #7A5CFF)',
          color: '#fff', fontSize: 13, fontWeight: 700, cursor: 'pointer',
          whiteSpace: 'nowrap', flexShrink: 0,
        }}>
          {os === 'ios' ? 'How to Install' : 'Install'}
        </button>

        {/* Close */}
        <button onClick={handleDismiss} style={{
          background: 'none', border: 'none', color: '#6B7280',
          fontSize: 18, cursor: 'pointer', padding: 4, flexShrink: 0,
        }} aria-label="Dismiss">✕</button>
      </div>

      {/* iOS Guide Modal */}
      {showIosGuide && (
        <div style={{
          position: 'fixed', inset: 0, zIndex: 10000,
          background: 'rgba(0,0,0,0.8)', backdropFilter: 'blur(8px)',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          padding: 20,
        }} onClick={handleDismiss}>
          <div style={{
            background: '#111827', borderRadius: 20, padding: 32,
            maxWidth: 340, width: '100%',
            border: '1px solid rgba(0,229,255,0.2)',
            boxShadow: '0 0 40px rgba(0,229,255,0.1)',
          }} onClick={(e) => e.stopPropagation()}>
            <div style={{ textAlign: 'center', marginBottom: 24 }}>
              <div style={{
                width: 60, height: 60, borderRadius: 14, margin: '0 auto 16px',
                background: 'linear-gradient(135deg, #7A5CFF, #00E5FF)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                fontSize: 24, fontWeight: 900, color: '#fff',
              }}>A</div>
              <div style={{ fontSize: 20, fontWeight: 800, color: '#E2E8F0' }}>
                Install ANORA
              </div>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              <Step num="1" text='Tap the Share button' icon="↑" />
              <Step num="2" text='"Add to Home Screen"' icon="+" />
              <Step num="3" text='Tap "Add"' icon="✓" />
            </div>

            <button onClick={handleDismiss} style={{
              width: '100%', marginTop: 24, padding: '12px 0',
              borderRadius: 12, border: '1px solid rgba(255,255,255,0.1)',
              background: 'rgba(255,255,255,0.05)', color: '#9CA3AF',
              fontSize: 14, cursor: 'pointer',
            }}>Got it</button>
          </div>
        </div>
      )}

      <style>{`
        @keyframes slideUp {
          from { transform: translateY(100%); opacity: 0; }
          to { transform: translateY(0); opacity: 1; }
        }
      `}</style>
    </>
  )
}

function Step({ num, text, icon }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
      <div style={{
        width: 32, height: 32, borderRadius: '50%', flexShrink: 0,
        background: 'rgba(0,229,255,0.1)', border: '1px solid rgba(0,229,255,0.3)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        fontSize: 14, fontWeight: 700, color: '#00E5FF',
      }}>{num}</div>
      <div style={{ fontSize: 14, color: '#CBD5E1' }}>
        {text} <span style={{ fontSize: 16 }}>{icon}</span>
      </div>
    </div>
  )
}
