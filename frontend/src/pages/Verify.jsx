import React, { useEffect, useState } from 'react'
import { useSearchParams, Link } from 'react-router-dom'
import { api } from '../api/client'

export default function Verify() {
  const [params]          = useSearchParams()
  const [msg, setMsg]     = useState('')
  const [isOk, setIsOk]   = useState(false)
  const [done, setDone]   = useState(false)

  useEffect(() => {
    const token = params.get('token')
    if (!token) { setMsg('No token provided.'); setDone(true); return }
    api.verify({ token })
      .then(d => { setMsg(d.message); setIsOk(true) })
      .catch(e => setMsg(e.message))
      .finally(() => setDone(true))
  }, [])

  if (!done) return <div className="text-center py-5"><div className="spinner-border text-primary" /></div>

  return (
    <div className="container" style={{ maxWidth: 480 }}>
      <div className={`alert alert-${isOk ? 'success' : 'danger'} mt-4`}>
        {msg}
        {isOk && <> &nbsp;<Link to="/login">Login now</Link></>}
      </div>
    </div>
  )
}
