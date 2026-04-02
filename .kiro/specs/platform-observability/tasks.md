# Tasks — Platform Observability

## Task 1: Device Fingerprints Schema & Endpoint
- [x] 1.1 Add `device_fingerprints` table DDL to `database.sql` with columns: id, user_id, session_id, ip_address, user_agent, canvas_hash, created_at, FK to users, and indexes on user_id, ip_address, canvas_hash, created_at
- [x] 1.2 Create `backend/api/game/fingerprint.php` — require login (401 if no session), read JSON body for `canvas_hash`, insert row into `device_fingerprints` with user_id from session, session_id(), REMOTE_ADDR, HTTP_USER_AGENT, return `{"ok": true}`
- [x] 1.3 Add `submitFingerprint` method to `frontend/src/api/client.js` — POST to `/game/fingerprint.php` with `{ canvas_hash }`

## Task 2: Frontend Fingerprint Collection
- [x] 2.1 Create fingerprint utility function (e.g., `frontend/src/utils/fingerprint.js`) — render predefined string to `<canvas>`, call `toDataURL()`, compute SHA-256 hash using SubtleCrypto API, return hex string
- [x] 2.2 Integrate fingerprint collection into login flow — after successful login, check `sessionStorage` flag, if not set: compute canvas hash, call `api.submitFingerprint()`, set flag; catch errors and `console.error()` without blocking

## Task 3: Activity Monitor Anti-Fraud Rules
- [x] 3.1 Extend `backend/api/admin/activity_monitor.php` with multi_account_ip flag — query `device_fingerprints` for IPs shared by 3+ distinct non-bot users in last 7 days, generate flags with user_id, email, flag_type, details, timestamp
- [x] 3.2 Add canvas_correlation flag — query `device_fingerprints` for non-null canvas_hash shared by 2+ distinct non-bot users, generate flags
- [x] 3.3 Add anomalous_win_rate flag — query `game_rounds` for non-bot users with win_count/rounds_participated > 40% over last 100 rounds, generate flags
- [x] 3.4 Add rapid_bet_speed flag — query `game_bets` for non-bot users with 10+ bets in any 10-second window, generate flags

## Task 4: Finance Dashboard Backend
- [x] 4.1 Create `backend/api/admin/finance_dashboard.php` — requireAdmin (403), compute total_deposits, total_withdrawals, system_profit, net_platform_position, total_bets_volume, total_payouts_volume from ledger_entries and user_balances (excluding bot users), return JSON

## Task 5: Health Check Backend
- [x] 5.1 Create `backend/api/admin/health_check.php` — requireAdmin (403), implement three checks: no_money_created (global balance invariant within 0.01 tolerance), no_money_lost (per-user balance_after match within 0.01), everything_traceable (non-null/non-empty reference_id and reference_type), return JSON with status ok/fail, checks object, checked_at ISO timestamp

## Task 6: Finance Dashboard Frontend
- [x] 6.1 Add `adminFinanceDashboard` and `adminHealthCheck` methods to `frontend/src/api/client.js`
- [x] 6.2 Create `frontend/src/pages/admin/FinanceDashboard.jsx` — fetch finance_dashboard.php and health_check.php on mount, display 6 metric cards (Total Deposits, Total Withdrawals, System Profit, Net Platform Position, Total Bets Volume, Total Payouts Volume) formatted as USD with 2 decimals, display Platform Health section with 3 invariant check pass/fail indicators, green "All Systems OK" or red "Integrity Issue Detected" badge, Refresh and Re-check buttons, loading and error states
- [x] 6.3 Add "Finance Dashboard" NavLink and Route to `frontend/src/components/AdminLayout.jsx` at path `/admin/finance`

## Task 7: Games Analytics Backend
- [x] 7.1 Create `backend/api/admin/games_analytics.php` — requireAdmin (403), accept query params (room, date_from, date_to, page, per_page default 20, round_id), list mode: return paginated finished rounds with id, room, total_pot, winner_id, winner_name, winner_net, commission, referral_bonus, finished_at, player_count; compute global_rtp and rtp_by_room as (SUM winner_net / SUM total_pot * 100); return total_rounds, total_pot_sum, total_payout_sum; detail mode (round_id provided): return single round with all bets (user_id, display_name, amount, chance, client_seed) and provably fair data (server_seed, final_combined_hash)

## Task 8: Games Analytics Frontend
- [x] 8.1 Add `adminGamesAnalytics` and `adminGamesAnalyticsDetail` methods to `frontend/src/api/client.js`
- [x] 8.2 Create `frontend/src/pages/admin/GamesAnalytics.jsx` — fetch games_analytics.php with filter params, display RTP summary cards (Global RTP + per-room for 1, 10, 100), paginated rounds table (Round ID, Room, Total Pot, Winner, Winner Net, Commission, Referral Bonus, Players, Finished At), expandable row detail with bets and provably fair data, room selector filter (All/1/10/100), date range picker, pagination controls, re-fetch on filter change starting at page 1
- [x] 8.3 Add "Games Analytics" NavLink and Route to `frontend/src/components/AdminLayout.jsx` at path `/admin/games-analytics`

## Task 9: Reconciliation Cron Job
- [x] 9.1 Create `backend/logs/` directory (add `.gitkeep`)
- [x] 9.2 Create `backend/cron/reconciliation.php` — compute sum_user_balances, sum_credits, sum_debits; verify global invariant (sum_balances == credits - debits within 0.01); verify per-user consistency (user_balances.balance matches most recent ledger_entries.balance_after within 0.01); write timestamped entries to `backend/logs/reconciliation.log`; write JSON summary to `backend/logs/reconciliation_latest.json` with status, all computed values, per_user_mismatches count, checked_at; exit code 0 on success, 1 on any discrepancy

## Task 10: Property-Based Tests
- [x] 10.1 Create `backend/tests/FingerprintPropertyTest.php` — Property 1: fingerprint insertion round-trip using in-memory SQLite
- [x] 10.2 Create `backend/tests/ActivityMonitorFlagsPropertyTest.php` — Properties 2-5: multi_account_ip, canvas_correlation, anomalous_win_rate, rapid_bet_speed flag detection with random generated data
- [x] 10.3 Create `backend/tests/FinanceDashboardPropertyTest.php` — Property 6: finance aggregation correctness with random ledger entries; Property 7: USD currency formatting
- [x] 10.4 Create `backend/tests/InvariantCheckPropertyTest.php` — Properties 8-11: global balance invariant, per-user consistency, traceability, health check status composition
- [x] 10.5 Create `backend/tests/RtpComputationPropertyTest.php` — Property 12: RTP computation correctness with random game rounds
- [x] 10.6 Create `backend/tests/ReconciliationPropertyTest.php` — Property 13: reconciliation JSON output correctness
