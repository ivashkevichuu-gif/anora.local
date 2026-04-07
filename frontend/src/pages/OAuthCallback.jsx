import React, { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

/**
 * OAuthCallback — handles redirect after OAuth authorization.
 *
 * Parses tokens from URL params, updates AuthContext, redirects to /account.
 * On error, shows message and redirects to /login after 3 seconds.
 *
 * Feature: oauth-social-login
 * Validates: Requirements 12.1, 12.2, 12.3, 12.4
 */

const ERROR_MESSAGES = {
  state_mismatch:        'Security error. Please try again.',
  session_expired:       'Session expired. Please try again.',
  token_exchange_failed: 'Could not connect to provider. Please try later.',
  invalid_token:         'Verification error. Please try again.',
  invalid_claims:        'Verification error. Please try again.',
  account_banned:        'Your account has been banned.',
  account_forbidden:     'Login is not available for this account.',
  provider_unavailable:  'Provider is temporarily unavailable. Please try later.',
  internal_error:        'Internal error. Please try later.',
}

export default function OAuthCallback() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const { loginWithTokens } = useAuth()
  const [error, setError] = useState(null)

  useEffect(() => {
    const accessToken  = searchParams.get('access_token')
    const refreshToken = searchParams.get('refresh_token')
    const errorCode    = searchParams.get('error')

    if (accessToken && refreshToken) {
      loginWithTokens(accessToken, refreshToken)
        .then(() => navigate('/account', { replace: true }))
        .catch(err => {
          setError(err.message || 'Authorization error')
          setTimeout(() => navigate('/login', { replace: true }), 3000)
        })
    } else if (errorCode) {
      const msg = ERROR_MESSAGES[errorCode] || searchParams.get('message') || 'Unknown error'
      setError(msg)
      setTimeout(() => navigate('/login', { replace: true }), 3000)
    } else {
      navigate('/login', { replace: true })
    }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  if (error) {
    return (
      <div style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        minHeight: '60vh',
        padding: '20px',
        textAlign: 'center',
      }}>
        <div style={{
          backgroundColor: 'rgba(220, 53, 69, 0.1)',
          border: '1px solid rgba(220, 53, 69, 0.3)',
          borderRadius: '8px',
          padding: '20px 32px',
          maxWidth: '400px',
        }}>
          <p style={{ color: '#dc3545', margin: '0 0 8px', fontWeight: 500 }}>{error}</p>
          <p style={{ color: 'var(--text-muted, #888)', fontSize: '13px', margin: 0 }}>
            Redirecting to login page...
          </p>
        </div>
      </div>
    )
  }

  return (
    <div style={{
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      minHeight: '60vh',
    }}>
      <div style={{ textAlign: 'center' }}>
        <div className="spinner-border" role="status" style={{ width: '2rem', height: '2rem' }}>
          <span className="visually-hidden">Loading...</span>
        </div>
        <p style={{ color: 'var(--text-muted, #888)', marginTop: '12px', fontSize: '14px' }}>
          Authorizing...
        </p>
      </div>
    </div>
  )
}
