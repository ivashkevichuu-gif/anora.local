import React, { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import StatusMessage from '../components/ui/StatusMessage'
import { getCanvasFingerprint } from '../utils/fingerprint'
import { api } from '../api/client'

export default function Login() {
  const { login }   = useAuth()
  const navigate    = useNavigate()
  const [form, setForm]   = useState({ email: '', password: '' })
  const [error, setError] = useState(null)
  const [busy, setBusy]   = useState(false)

  const handle = e => setForm(f => ({ ...f, [e.target.name]: e.target.value }))

  const submit = async e => {
    e.preventDefault()
    setError(null)
    setBusy(true)
    try {
      await login(form.email, form.password)

      // Collect and submit device fingerprint once per session
      if (!sessionStorage.getItem('fp_submitted')) {
        try {
          const canvasHash = await getCanvasFingerprint()
          await api.submitFingerprint(canvasHash)
          sessionStorage.setItem('fp_submitted', '1')
        } catch (fpErr) {
          console.error('Fingerprint submission failed:', fpErr)
        }
      }

      navigate('/account')
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={{ maxWidth: 420 }}>
      <h2 className="mb-1 fw-700">Sign in</h2>
      <p style={{ color: 'var(--text-muted)' }} className="mb-4">
        Welcome back — enter your credentials below.
      </p>

      <div className="card p-4">
        <StatusMessage error={error} />
        <form onSubmit={submit}>
          <div className="mb-3">
            <label className="form-label">Email address</label>
            <input type="email" name="email" className="form-control" required
              value={form.email} onChange={handle} />
          </div>
          <div className="mb-4">
            <label className="form-label">Password</label>
            <input type="password" name="password" className="form-control" required
              value={form.password} onChange={handle} />
          </div>
          <button type="submit" className="btn btn-accent w-100" disabled={busy}>
            {busy && <span className="spinner-border spinner-border-sm me-2" />}
            Sign in
          </button>
        </form>
      </div>

      <p className="text-center mt-3" style={{ color: 'var(--text-muted)', fontSize: '.9rem' }}>
        No account?{' '}
        <Link to="/register" style={{ color: 'var(--accent)' }}>Create one</Link>
      </p>
    </div>
  )
}
