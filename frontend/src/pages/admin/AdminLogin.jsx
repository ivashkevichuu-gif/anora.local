import React, { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAdmin } from '../../context/AdminContext'

export default function AdminLogin() {
  const { adminLogin } = useAdmin()
  const navigate = useNavigate()
  const [form, setForm]   = useState({ username: '', password: '' })
  const [error, setError] = useState('')
  const [busy, setBusy]   = useState(false)

  const handle = e => setForm(f => ({ ...f, [e.target.name]: e.target.value }))

  const submit = async e => {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      await adminLogin(form.username, form.password)
      navigate('/admin/users')
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="bg-dark d-flex align-items-center justify-content-center" style={{ minHeight: '100vh' }}>
      <div className="card p-4" style={{ width: 360 }}>
        <h4 className="text-center mb-3">
          <i className="bi bi-shield-lock-fill text-danger me-2"></i>Admin Login
        </h4>
        {error && <div className="alert alert-danger">{error}</div>}
        <form onSubmit={submit}>
          <div className="mb-3">
            <label className="form-label">Username</label>
            <input type="text" name="username" className="form-control" required autoFocus
              value={form.username} onChange={handle} />
          </div>
          <div className="mb-3">
            <label className="form-label">Password</label>
            <input type="password" name="password" className="form-control" required
              value={form.password} onChange={handle} />
          </div>
          <button type="submit" className="btn btn-danger w-100" disabled={busy}>
            {busy && <span className="spinner-border spinner-border-sm me-2" />}
            Login
          </button>
        </form>
      </div>
    </div>
  )
}
