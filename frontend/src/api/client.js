const BASE = '/backend/api'

async function request(path, options = {}) {
  let res
  try {
    res = await fetch(`${BASE}${path}`, {
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', ...options.headers },
      ...options,
    })
  } catch (networkErr) {
    throw new Error('Network error: ' + networkErr.message)
  }

  const text = await res.text()

  if (!text || !text.trim()) {
    throw new Error(`Empty response (HTTP ${res.status})`)
  }

  let data
  try {
    data = JSON.parse(text)
  } catch {
    throw new Error(`Server error: ${text.slice(0, 200)}`)
  }

  if (!res.ok) throw new Error(data.error || 'Request failed')
  return data
}

export const api = {
  register:   body => request('/auth/register.php',  { method: 'POST', body: JSON.stringify(body) }),
  login:      body => request('/auth/login.php',     { method: 'POST', body: JSON.stringify(body) }),
  logout:     ()   => request('/auth/logout.php',    { method: 'POST' }),
  me:         ()   => request('/auth/me.php'),
  verify:     body => request('/auth/verify.php',    { method: 'POST', body: JSON.stringify(body) }),

  deposit:      body => request('/account/deposit.php',      { method: 'POST', body: JSON.stringify(body) }),
  withdraw:     body => request('/account/withdraw.php',     { method: 'POST', body: JSON.stringify(body) }),
  saveBank:     body => request('/account/bank.php',         { method: 'POST', body: JSON.stringify(body) }),
  transactions:     ()           => request('/account/transactions.php'),
  userTransactions: (page = 1)  => request(`/account/transactions.php?page=${page}`),

  adminLogin:      body => request('/admin/login.php',        { method: 'POST', body: JSON.stringify(body) }),
  adminLogout:     ()   => request('/admin/logout.php',       { method: 'POST' }),
  adminMe:         ()   => request('/admin/me.php'),
  adminUsers:      ()   => request('/admin/users.php'),
  adminTxs:        ()   => request('/admin/transactions.php'),
  adminWithdrawals:()   => request('/admin/withdrawals.php'),
  adminAction:     body => request('/admin/action.php',       { method: 'POST', body: JSON.stringify(body) }),

  // Lottery
  lotteryStatus: (room = 1)           => request(`/lottery/status.php?room=${room}`),
  lotteryBet:    (room, clientSeed)   => request('/lottery/bet.php', {
    method: 'POST',
    body: JSON.stringify({ room, client_seed: clientSeed }),
  }),
  lotteryVerify: (gameId)             => request(`/lottery/verify.php?game_id=${gameId}`),

  // Admin lottery
  adminLotteryGames:   ()             => request('/admin/lottery_games.php'),
  adminSystemBalance:  (page = 1)     => request(`/admin/system_balance.php?page=${page}`),
}
