import React from 'react'

export default function Footer() {
  return (
    <footer className="bg-dark text-white-50 text-center py-3 mt-5">
      <div className="container d-flex flex-column flex-sm-row justify-content-center align-items-center gap-3">
        <small>&copy; {new Date().getFullYear()} ANORA. All rights reserved.</small>
      </div>
    </footer>
  )
}
