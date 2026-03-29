import React from 'react'
import { NavLink, Routes, Route, Navigate, useNavigate } from 'react-router-dom'
import { useAdmin }      from '../context/AdminContext'
import AdminUsers        from '../pages/admin/Users'
import AdminTxs          from '../pages/admin/Transactions'
import AdminWithdrawals  from '../pages/admin/Withdrawals'
import AdminLotteryGames from '../pages/admin/LotteryGames'

export default function AdminLayout() {
  const { adminLogout } = useAdmin()
  const navigate = useNavigate()

  const handleLogout = async () => {
    await adminLogout()
    navigate('/admin/login')
  }

  const link = ({ isActive }) => `nav-link ${isActive ? 'active' : ''}`

  return (
    <div className="d-flex">
      <div className="admin-sidebar d-flex flex-column p-3">
        <div className="text-white fw-bold fs-5 mb-4 mt-1">
          <i className="bi bi-bank2 me-2"></i>Admin
        </div>
        <nav className="nav flex-column gap-1">
          <NavLink to="/admin/users"         className={link}><i className="bi bi-people-fill me-2"></i>Users</NavLink>
          <NavLink to="/admin/transactions"  className={link}><i className="bi bi-list-ul me-2"></i>Transactions</NavLink>
          <NavLink to="/admin/withdrawals"   className={link}><i className="bi bi-arrow-up-circle me-2"></i>Withdrawals</NavLink>
          <NavLink to="/admin/lottery-games" className={link}><i className="bi bi-dice-5-fill me-2"></i>Lottery Games</NavLink>
        </nav>
        <div className="mt-auto">
          <button className="nav-link btn btn-link text-danger p-0" onClick={handleLogout}>
            <i className="bi bi-box-arrow-left me-2"></i>Logout
          </button>
        </div>
      </div>
      <div className="flex-grow-1 p-4">
        <Routes>
          <Route index                  element={<Navigate to="users" replace />} />
          <Route path="users"           element={<AdminUsers />} />
          <Route path="transactions"    element={<AdminTxs />} />
          <Route path="withdrawals"     element={<AdminWithdrawals />} />
          <Route path="lottery-games"   element={<AdminLotteryGames />} />
        </Routes>
      </div>
    </div>
  )
}
