import React, { lazy, Suspense, useEffect } from 'react'
import { Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider }  from './context/AuthContext'
import { AdminProvider } from './context/AdminContext'
import PublicLayout    from './components/layouts/PublicLayout'
import ProtectedRoute  from './components/ProtectedRoute'
import AdminRoute      from './components/AdminRoute'
import Spinner         from './components/ui/Spinner'

// Lazy-loaded pages — each page is a separate chunk
const Home        = lazy(() => import('./pages/Home'))
const Login       = lazy(() => import('./pages/Login'))
const Register    = lazy(() => import('./pages/Register'))
const Verify      = lazy(() => import('./pages/Verify'))
const Account     = lazy(() => import('./pages/Account'))
const About       = lazy(() => import('./pages/About'))
const AdminLogin      = lazy(() => import('./pages/admin/AdminLogin'))
const AdminLayout     = lazy(() => import('./components/AdminLayout'))
const SystemBalance   = lazy(() => import('./pages/admin/SystemBalance'))

export default function App() {
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const ref = params.get('ref')
    if (ref) {
      localStorage.setItem('anora_ref', JSON.stringify({
        code: ref,
        expires: Date.now() + 7 * 24 * 60 * 60 * 1000
      }))
    }
  }, [])

  return (
    <AuthProvider>
      <AdminProvider>
        <Suspense fallback={<Spinner fullPage />}>
          <Routes>
            {/* Public pages — share Navbar + Footer via PublicLayout */}
            <Route element={<PublicLayout />}>
              <Route path="/"         element={<Home />} />
              <Route path="/login"    element={<Login />} />
              <Route path="/register" element={<Register />} />
              <Route path="/verify"   element={<Verify />} />
              <Route path="/about"    element={<About />} />
              <Route path="/account"  element={
                <ProtectedRoute><Account /></ProtectedRoute>
              } />
            </Route>

            {/* Admin routes — no public layout */}
            <Route path="/admin"       element={<Navigate to="/admin/login" replace />} />
            <Route path="/admin/login" element={<AdminLogin />} />
            <Route path="/admin/*"     element={
              <AdminRoute><AdminLayout /></AdminRoute>
            } />
            <Route path="/admin/system-balance" element={
              <AdminRoute><SystemBalance /></AdminRoute>
            } />

            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </AdminProvider>
    </AuthProvider>
  )
}
