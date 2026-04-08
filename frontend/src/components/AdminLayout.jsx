import React from 'react'
import { NavLink, Routes, Route, Navigate, useNavigate } from 'react-router-dom'
import { useAdmin }      from '../context/AdminContext'
import AdminUsers        from '../pages/admin/Users'
import AdminTxs          from '../pages/admin/Transactions'
import AdminWithdrawals  from '../pages/admin/Withdrawals'
import AdminLotteryGames from '../pages/admin/LotteryGames'
import SystemBalance     from '../pages/admin/SystemBalance'
import CryptoInvoices   from '../pages/admin/CryptoInvoices'
import CryptoPayouts    from '../pages/admin/CryptoPayouts'
import LedgerExplorer   from '../pages/admin/LedgerExplorer'
import ActivityMonitor   from '../pages/admin/ActivityMonitor'
import FinanceDashboard from '../pages/admin/FinanceDashboard'
import GamesAnalytics  from '../pages/admin/GamesAnalytics'
import MediaSettings   from '../pages/admin/MediaSettings'

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
          <NavLink to="/admin/lottery-games"   className={link}><i className="bi bi-dice-5-fill me-2"></i>Lottery Games</NavLink>
          <NavLink to="/admin/system-balance" className={link}><i className="bi bi-cash-stack me-2"></i>System Balance</NavLink>
          <NavLink to="/admin/crypto-invoices" className={link}><i className="bi bi-currency-bitcoin me-2"></i>Crypto Invoices</NavLink>
          <NavLink to="/admin/crypto-payouts" className={link}><i className="bi bi-wallet2 me-2"></i>Crypto Payouts</NavLink>
          <NavLink to="/admin/ledger" className={link}><i className="bi bi-journal-text me-2"></i>Ledger Explorer</NavLink>
          <NavLink to="/admin/activity-monitor" className={link}><i className="bi bi-shield-exclamation me-2"></i>Activity Monitor</NavLink>
          <NavLink to="/admin/finance" className={link}><i className="bi bi-graph-up me-2"></i>Finance Dashboard</NavLink>
          <NavLink to="/admin/games-analytics" className={link}><i className="bi bi-bar-chart-line me-2"></i>Games Analytics</NavLink>
          <NavLink to="/admin/media" className={link}><i className="bi bi-camera-reels me-2"></i>Media Settings</NavLink>
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
          <Route path="system-balance"  element={<SystemBalance />} />
          <Route path="crypto-invoices" element={<CryptoInvoices />} />
          <Route path="crypto-payouts"  element={<CryptoPayouts />} />
          <Route path="ledger"          element={<LedgerExplorer />} />
          <Route path="activity-monitor" element={<ActivityMonitor />} />
          <Route path="finance" element={<FinanceDashboard />} />
          <Route path="games-analytics" element={<GamesAnalytics />} />
          <Route path="media"           element={<MediaSettings />} />
        </Routes>
      </div>
    </div>
  )
}
