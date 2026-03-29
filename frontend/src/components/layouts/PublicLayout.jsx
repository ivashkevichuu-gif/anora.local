import React, { useState } from 'react'
import { Outlet } from 'react-router-dom'
import Sidebar from '../Sidebar'

export default function PublicLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  return (
    <div className="app-shell">
      {/* Mobile hamburger */}
      <button
        className="sidebar-toggle"
        onClick={() => setSidebarOpen(o => !o)}
        aria-label="Toggle menu"
      >
        <i className={`bi ${sidebarOpen ? 'bi-x-lg' : 'bi-list'}`}></i>
      </button>

      <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      <div className="main-content">
        <div className="page-area">
          <Outlet />
        </div>
        <footer className="app-footer">
          <span>&copy; {new Date().getFullYear()} ANORA. All rights reserved.</span>
          <span>Built with React &amp; PHP</span>
        </footer>
      </div>
    </div>
  )
}
