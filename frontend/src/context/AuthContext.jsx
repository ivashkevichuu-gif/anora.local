import React, { createContext, useContext, useEffect, useState } from 'react'
import { authService } from '../services/authService'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser]       = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    authService.getMe()
      .then(d => setUser(d.user))
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  const login = async (email, password) => {
    const d = await authService.login(email, password)
    setUser(d.user)
  }

  const logout = async () => {
    await authService.logout()
    setUser(null)
  }

  const loginWithTokens = async (accessToken, refreshToken) => {
    // Store tokens for API client to use
    localStorage.setItem('access_token', accessToken)
    localStorage.setItem('refresh_token', refreshToken)
    // Fetch user data with the new token
    const d = await authService.getMe()
    setUser(d.user)
  }

  return (
    <AuthContext.Provider value={{ user, setUser, loading, login, logout, loginWithTokens }}>
      {children}
    </AuthContext.Provider>
  )
}

export const useAuth = () => useContext(AuthContext)
