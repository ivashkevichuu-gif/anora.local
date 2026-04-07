import React from 'react'

/**
 * OAuthButtons — Google Sign-In button (Apple commented for future use).
 *
 * Feature: oauth-social-login
 * Validates: Requirements 11.1, 11.2, 11.3, 11.6
 */

const googleBtnStyle = {
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  gap: '10px',
  width: '100%',
  padding: '10px 16px',
  border: '1px solid #dadce0',
  borderRadius: '8px',
  backgroundColor: '#fff',
  color: '#3c4043',
  fontSize: '14px',
  fontWeight: 500,
  fontFamily: "'Roboto', 'Arial', sans-serif",
  cursor: 'pointer',
  transition: 'background-color 0.2s, box-shadow 0.2s',
  lineHeight: '20px',
}

// Apple Sign-In — commented for future use
// const appleBtnStyle = {
//   display: 'flex',
//   alignItems: 'center',
//   justifyContent: 'center',
//   gap: '10px',
//   width: '100%',
//   padding: '10px 16px',
//   border: 'none',
//   borderRadius: '8px',
//   backgroundColor: '#000',
//   color: '#fff',
//   fontSize: '14px',
//   fontWeight: 500,
//   fontFamily: "'SF Pro Display', 'Arial', sans-serif",
//   cursor: 'pointer',
//   transition: 'opacity 0.2s',
//   lineHeight: '20px',
// }

function GoogleIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
      <path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844a4.14 4.14 0 01-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/>
      <path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 009 18z" fill="#34A853"/>
      <path d="M3.964 10.71A5.41 5.41 0 013.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 000 9s0 0 0 0a9 9 0 00.957 4.042l3.007-2.332z" fill="#FBBC05"/>
      <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 00.957 4.958L3.964 6.29C4.672 4.163 6.656 3.58 9 3.58z" fill="#EA4335"/>
    </svg>
  )
}

// Apple Sign-In — commented for future use
// function AppleIcon() {
//   return (
//     <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
//       <path d="M15.545 14.1c-.355.82-.525 1.186-.981 1.907-.636.999-1.533 2.243-2.644 2.255-1.24.013-1.559-.808-3.241-.797-1.682.01-2.033.813-3.274.8-1.11-.013-1.96-1.136-2.597-2.135C1.18 13.588.553 10.147 2.24 7.87c.592-.8 1.648-1.306 2.764-1.32 1.098-.013 2.134.76 2.804.76.67 0 1.926-.94 3.248-.802.553.023 2.107.224 3.104 1.685-.08.05-1.853 1.082-1.834 3.228.022 2.565 2.25 3.418 2.273 3.428-.02.06-.355 1.22-.054-.749zM11.388.85c.49-.63.863-1.52.768-2.42-.83.054-1.8.553-2.368 1.204-.513.587-.935 1.49-.771 2.36.907.028 1.843-.484 2.371-1.144z" fill="#fff"/>
//     </svg>
//   )
// }

export default function OAuthButtons() {
  const handleGoogle = () => {
    window.location.href = '/backend/api/auth/oauth_start.php?provider=google'
  }

  // Apple Sign-In — commented for future use
  // const handleApple = () => {
  //   window.location.href = '/backend/api/auth/oauth_start.php?provider=apple'
  // }

  return (
    <div style={{ marginTop: '16px' }}>
      <div style={{
        display: 'flex',
        alignItems: 'center',
        gap: '12px',
        margin: '16px 0',
        color: 'var(--text-muted, #888)',
        fontSize: '13px',
      }}>
        <div style={{ flex: 1, height: '1px', backgroundColor: 'var(--border, #333)' }} />
        <span>или</span>
        <div style={{ flex: 1, height: '1px', backgroundColor: 'var(--border, #333)' }} />
      </div>

      <button
        type="button"
        onClick={handleGoogle}
        style={googleBtnStyle}
        aria-label="Sign in with Google"
        onMouseEnter={e => {
          e.currentTarget.style.backgroundColor = '#f8f9fa'
          e.currentTarget.style.boxShadow = '0 1px 3px rgba(0,0,0,0.12)'
        }}
        onMouseLeave={e => {
          e.currentTarget.style.backgroundColor = '#fff'
          e.currentTarget.style.boxShadow = 'none'
        }}
      >
        <GoogleIcon />
        Sign in with Google
      </button>

      {/* Apple Sign-In — commented for future use */}
      {/* <button
        type="button"
        onClick={handleApple}
        style={{ ...appleBtnStyle, marginTop: '10px' }}
        aria-label="Sign in with Apple"
        onMouseEnter={e => { e.currentTarget.style.opacity = '0.85' }}
        onMouseLeave={e => { e.currentTarget.style.opacity = '1' }}
      >
        <AppleIcon />
        Sign in with Apple
      </button> */}
    </div>
  )
}
