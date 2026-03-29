import React, { createContext, useContext, useState } from 'react'
import { adminService } from '../services/adminService'

const AdminContext = createContext(null)

/**
 * AdminProvider does NOT auto-check session on mount.
 * Admin auth is verified lazily — only when /admin/* routes are accessed.
 * This avoids a wasted API call on every public page load.
 */
export function AdminProvider({ children }) {
  const [isAdmin, setIsAdmin]   = useState(null)  // null = unknown, false = not admin, true = admin
  const [loading, setLoading]   = useState(false)

  const checkAdmin = async () => {
    if (isAdmin !== null) return isAdmin
    setLoading(true)
    try {
      await adminService.getMe()
      setIsAdmin(true)
      return true
    } catch {
      setIsAdmin(false)
      return false
    } finally {
      setLoading(false)
    }
  }

  const adminLogin = async (username, password) => {
    await adminService.login(username, password)
    setIsAdmin(true)
  }

  const adminLogout = async () => {
    await adminService.logout()
    setIsAdmin(false)
  }

  return (
    <AdminContext.Provider value={{ isAdmin, loading, checkAdmin, adminLogin, adminLogout }}>
      {children}
    </AdminContext.Provider>
  )
}

export const useAdmin = () => useContext(AdminContext)
