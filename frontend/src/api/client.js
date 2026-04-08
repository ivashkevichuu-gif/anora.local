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
  updateNickname:   body        => request('/account/nickname.php', { method: 'POST', body: JSON.stringify(body) }),

  adminLogin:      body => request('/admin/login.php',        { method: 'POST', body: JSON.stringify(body) }),
  adminLogout:     ()   => request('/admin/logout.php',       { method: 'POST' }),
  adminMe:         ()   => request('/admin/me.php'),
  adminUsers:      ()   => request('/admin/users.php'),
  adminTxs:        ()   => request('/admin/transactions.php?source=ledger'),
  adminWithdrawals:()   => request('/admin/withdrawals.php'),
  adminAction:     body => request('/admin/action.php',       { method: 'POST', body: JSON.stringify(body) }),

  // Lottery (legacy — kept for backward compatibility)
  lotteryStatus: (room = 1)           => request(`/lottery/status.php?room=${room}`),
  lotteryBet:    (room, clientSeed)   => request('/lottery/bet.php', {
    method: 'POST',
    body: JSON.stringify({ room, client_seed: clientSeed }),
  }),
  lotteryVerify: (gameId)             => request(`/lottery/verify.php?game_id=${gameId}`),

  // Game (new ledger-based system)
  gameStatus: (room = 1) => request(`/game/status.php?room=${room}`),
  gameBet: (room, clientSeed) => request('/game/bet.php', {
    method: 'POST',
    body: JSON.stringify({ room, client_seed: clientSeed }),
  }),
  gameVerify: (gameId) => request(`/game/verify.php?game_id=${gameId}`),

  // Device fingerprint
  submitFingerprint: (canvasHash) => request('/game/fingerprint.php', {
    method: 'POST',
    body: JSON.stringify({ canvas_hash: canvasHash }),
  }),

  // OAuth
  oauthStart: (provider) => {
    window.location.href = `${BASE}/auth/oauth_start.php?provider=${encodeURIComponent(provider)}`
  },

  // Crypto
  cryptoDeposit:    body         => request('/account/crypto_deposit.php',  { method: 'POST', body: JSON.stringify(body) }),
  cryptoWithdraw:   body         => request('/account/crypto_withdraw.php', { method: 'POST', body: JSON.stringify(body) }),
  cryptoInvoices:   (page = 1)   => request(`/account/crypto_invoices.php?page=${page}`),
  cryptoPayouts:    (page = 1)   => request(`/account/crypto_payouts.php?page=${page}`),

  // Admin crypto
  adminCryptoInvoices:     (page = 1, status = '') => request(`/admin/crypto_invoices.php?page=${page}&status=${status}`),
  adminCryptoPayouts:      (page = 1, status = '') => request(`/admin/crypto_payouts.php?page=${page}&status=${status}`),
  adminCryptoPayoutAction: body                    => request('/admin/crypto_payouts.php', { method: 'POST', body: JSON.stringify(body) }),

  // Player stats
  playerStats: (period = 'all') => request(`/account/stats.php?period=${period}`),

  // Admin hardening
  adminLedger: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    return request(`/admin/ledger.php?${qs}`);
  },
  adminActivityMonitor: () => request('/admin/activity_monitor.php'),
  adminLotteryGameDetail: (roundId) => request(`/admin/lottery_games.php?round_id=${roundId}`),

  // Admin lottery
  adminLotteryGames:   ()             => request('/admin/lottery_games.php'),
  adminSystemBalance:  (page = 1)     => request(`/admin/system_balance.php?page=${page}`),

  // Admin finance & health
  adminFinanceDashboard: () => request('/admin/finance_dashboard.php'),
  adminHealthCheck:      () => request('/admin/health_check.php'),

  // Admin games analytics
  adminGamesAnalytics: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    return request(`/admin/games_analytics.php?${qs}`);
  },
  adminGamesAnalyticsDetail: (roundId) => request(`/admin/games_analytics.php?round_id=${roundId}`),

  // Media settings
  adminMediaSettings:       ()     => request('/admin/media_settings.php'),
  adminMediaSettingsUpdate:  body  => request('/admin/media_settings.php', { method: 'POST', body: JSON.stringify(body) }),
  adminMediaPostAction:      body  => request('/admin/media_posts.php', { method: 'POST', body: JSON.stringify(body) }),

  // Public social links (no auth)
  socialLinks: () => request('/public/social_links.php'),
}
