import React, { useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../services/authService'
import StatusMessage from '../components/ui/StatusMessage'

export default function Register() {
  const [form, setForm]       = useState({ email: '', password: '', confirm: '' })
  const [error, setError]     = useState(null)
  const [success, setSuccess] = useState(null)
  const [busy, setBusy]       = useState(false)

  const handle = e => setForm(f => ({ ...f, [e.target.name]: e.target.value }))

  const submit = async e => {
    e.preventDefault()
    setError(null)
    if (form.password !== form.confirm) { setError('Passwords do not match.'); return }
    setBusy(true)
    try {
      const d = await authService.register(form.email, form.password)
      setSuccess(d)
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={{ maxWidth: 440 }}>
      <h2 className="mb-1 fw-700">Create account</h2>
      <p style={{ color: 'var(--text-muted)' }} className="mb-4">
        Fill in the details below to get started.
      </p>

      <div className="card p-4">
        <StatusMessage error={error} success={success} />
        {!success && (
          <form onSubmit={submit}>
            <div className="mb-3">
              <label className="form-label">Email address</label>
              <input type="email" name="email" className="form-control" required
                value={form.email} onChange={handle} />
            </div>
            <div className="mb-3">
              <label className="form-label">Password</label>
              <input type="password" name="password" className="form-control"
                required minLength={6} value={form.password} onChange={handle} />
            </div>
            <div className="mb-4">
              <label className="form-label">Confirm Password</label>
              <input type="password" name="confirm" className="form-control" required
                value={form.confirm} onChange={handle} />
            </div>
            <button type="submit" className="btn btn-accent w-100" disabled={busy}>
              {busy && <span className="spinner-border spinner-border-sm me-2" />}
              Create account
            </button>
          </form>
        )}
      </div>

      <p className="text-center mt-3" style={{ color: 'var(--text-muted)', fontSize: '.9rem' }}>
        Already have an account?{' '}
        <Link to="/login" style={{ color: 'var(--accent)' }}>Sign in</Link>
      </p>
    </div>
  )
}
