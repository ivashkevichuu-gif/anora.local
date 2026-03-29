import React, { useEffect } from 'react'
import { Navigate } from 'react-router-dom'
import { useAdmin } from '../context/AdminContext'
import Spinner from './ui/Spinner'

export default function AdminRoute({ children }) {
  const { isAdmin, loading, checkAdmin } = useAdmin()

  useEffect(() => {
    if (isAdmin === null) checkAdmin()
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  if (isAdmin === null || loading) return <Spinner color="danger" fullPage />
  return isAdmin ? children : <Navigate to="/admin/login" replace />
}
