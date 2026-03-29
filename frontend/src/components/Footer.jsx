import React from 'react'
import { Link } from 'react-router-dom'

export default function Footer() {
  return (
    <footer className="bg-dark text-white-50 text-center py-3 mt-5">
      <div className="container d-flex flex-column flex-sm-row justify-content-center align-items-center gap-3">
        <small>&copy; {new Date().getFullYear()} FinanceApp. All rights reserved.</small>
        <small>
          <Link to="/admin/login" className="text-white-50 text-decoration-none">
            Admin Panel
          </Link>
        </small>
      </div>
    </footer>
  )
}
