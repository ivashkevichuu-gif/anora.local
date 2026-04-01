# Implementation Plan: Production Hardening

## Overview

Close six production-hardening gaps: race condition fix, mandatory client seeds, fraud_flagged DDL, new admin API endpoints (ledger, activity monitor, round detail), admin action extensions (ban, clear_fraud_flag), two new frontend pages (LedgerExplorer, ActivityMonitor), and enhancements to existing pages (Users.jsx, LotteryGames.jsx, AdminLayout).

## Tasks

- [x] 1. Backend quick fixes (GameEngine + DDL)
  - [x] 1.1 Fix race condition in GameEngine::placeBet()
    - Add `$this->ledger->getBalanceForUpdate($userId)` call after locking game_rounds row and before `addEntry()`
    - Compare locked balance against `$amount`, throw `RuntimeException('Insufficient balance')` and rollback if insufficient
    - _Requirements: 6.1, 6.2, 6.3, 6.4_

  - [x] 1.2 Add mandatory client seed auto-generation in GameEngine::placeBet()
    - When `$clientSeed === ''`, generate 4 unsigned 32-bit ints from `random_bytes(16)` via `unpack('N4', ...)`
    - Format as dash-separated string (e.g. `3284719283-1928374650-482917364-1029384756`)
    - Change INSERT to store `$clientSeed` directly (never NULL)
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

  - [x] 1.3 Add `fraud_flagged` column to users table
    - Add `ALTER TABLE users ADD COLUMN IF NOT EXISTS fraud_flagged TINYINT(1) NOT NULL DEFAULT 0` to `database.sql`
    - _Requirements: 5.1_

  - [x] 1.4 Add win streak auto-flagging in GameEngine::finishRoundAttempt()
    - After existing win streak log line, add `UPDATE users SET fraud_flagged = 1 WHERE id = ?` when `$winCount >= 10`
    - _Requirements: 5.2, 5.3_

  - [ ]* 1.5 Write property tests for race condition and client seed
    - **Property 9: No overdraw under concurrent bets**
    - **Property 10: All bets have valid client seeds**
    - **Validates: Requirements 6.1, 6.2, 6.3, 6.4, 7.1, 7.2, 7.4**

- [x] 2. Checkpoint — Verify backend quick fixes
  - Ensure all tests pass, ask the user if questions arise.

- [x] 3. Backend API endpoints
  - [x] 3.1 Create `backend/api/admin/ledger.php`
    - GET endpoint guarded by `requireAdmin()`
    - Accept query params: `page`, `per_page`, `user_id`, `email`, `type`, `date_from`, `date_to`, `reference_type`, `reference_id`
    - JOIN `ledger_entries` with `users` to include email
    - Return JSON: `{ entries, page, per_page, total_count, total_pages }`
    - Clamp `per_page` to max 200, `page` to min 1
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9_

  - [x] 3.2 Create `backend/api/admin/activity_monitor.php`
    - GET endpoint guarded by `requireAdmin()`
    - Detect win streaks (≥10 wins in 24h for non-bot users)
    - Detect high velocity (≥50 bets in any 5-min window in 24h for non-bot users)
    - Detect IP correlation (≥2 non-bot users sharing `registration_ip`)
    - Detect large withdrawals (>1000.00 in last 7 days)
    - Return JSON: `{ flags: [{ user_id, email, flag_type, details, timestamp }] }`
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7_

  - [x] 3.3 Enhance `backend/api/admin/lottery_games.php` with round detail
    - Add `server_seed`, `final_combined_hash`, `winner_name` (COALESCE nickname/email) to main query
    - When `round_id` query param is present, return single round with all bets: `user_id`, `display_name`, `amount`, `chance`, `client_seed`
    - _Requirements: 8.1, 8.2_

  - [ ]* 3.4 Write property tests for ledger filters and activity monitor detection
    - **Property 1: Ledger filter correctness**
    - **Property 2: Ledger pagination math**
    - **Property 3: Win streak detection completeness**
    - **Property 4: High velocity detection completeness**
    - **Property 5: IP correlation detection completeness**
    - **Property 6: Large withdrawal detection completeness**
    - **Validates: Requirements 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 3.2, 3.3, 3.4, 3.5**

- [x] 4. Admin action.php enhancements
  - [x] 4.1 Add `ban` action to `backend/api/admin/action.php`
    - When `action === 'ban'`, set `is_banned = 1` on the user and return success
    - Expand the `in_array` check to include `'ban'` and `'clear_fraud_flag'`
    - _Requirements: 4.4, 5.6_

  - [x] 4.2 Add `clear_fraud_flag` action to `backend/api/admin/action.php`
    - When `action === 'clear_fraud_flag'`, set `fraud_flagged = 0` on the user and return success
    - _Requirements: 5.5_

  - [x] 4.3 Enhance `backend/api/admin/users.php` to include `fraud_flagged` and `is_banned`
    - Add `is_banned, fraud_flagged` to the SELECT query
    - _Requirements: 5.4_

- [x] 5. Checkpoint — Verify all backend changes
  - Ensure all tests pass, ask the user if questions arise.

- [x] 6. Frontend new pages
  - [x] 6.1 Add API client methods for new endpoints
    - Add `adminLedger(params)` — GET `/admin/ledger.php?${qs}`
    - Add `adminActivityMonitor()` — GET `/admin/activity_monitor.php`
    - Add `adminLotteryGameDetail(roundId)` — GET `/admin/lottery_games.php?round_id=${roundId}`
    - _Requirements: 1.1, 3.1, 8.2_

  - [x] 6.2 Create `frontend/src/pages/admin/LedgerExplorer.jsx`
    - Filter bar: user_id/email input, type dropdown, date range pickers, reference_type dropdown, reference_id input
    - Paginated table: id, user_id, email, type, amount, direction, balance_after, reference_id, reference_type, created_at
    - Filter changes reset to page 1 and re-fetch
    - Pagination controls (prev/next)
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6_

  - [x] 6.3 Create `frontend/src/pages/admin/ActivityMonitor.jsx`
    - Table: user_id, email, flag_type, details, timestamp
    - "Dismiss" button removes row from local state
    - "Ban User" button calls `api.adminAction({ action: 'ban', id: userId })` then removes row
    - _Requirements: 4.1, 4.2, 4.3, 4.4_

- [x] 7. Frontend enhancements to existing pages
  - [x] 7.1 Enhance `frontend/src/pages/admin/Users.jsx` with fraud badge
    - Display `fraud_flagged` badge (e.g. red "Fraud" badge) and `is_banned` badge
    - Add "Clear Flag" button calling `api.adminAction({ action: 'clear_fraud_flag', id })` for flagged users
    - Add "Ban" button calling `api.adminAction({ action: 'ban', id })` for flagged users
    - _Requirements: 5.4, 5.5, 5.6_

  - [x] 7.2 Enhance `frontend/src/pages/admin/LotteryGames.jsx` with payout details
    - Add columns: winner_name, server_seed (truncated), final_combined_hash (truncated) for finished rounds
    - Add expandable detail row per round that fetches bets via `adminLotteryGameDetail(roundId)`
    - Show bet table: display_name, amount, chance, client_seed
    - Show provably fair data: server_seed, combined_hash in detail view
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_

- [x] 8. Wire new pages into AdminLayout
  - [x] 8.1 Update `frontend/src/components/AdminLayout.jsx`
    - Import LedgerExplorer and ActivityMonitor
    - Add sidebar NavLinks: "Ledger Explorer" → `/admin/ledger`, "Activity Monitor" → `/admin/activity-monitor`
    - Add Routes: `<Route path="ledger" element={<LedgerExplorer />} />`, `<Route path="activity-monitor" element={<ActivityMonitor />} />`
    - _Requirements: 2.1, 4.1_

- [x] 9. Final checkpoint — Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirement acceptance criteria for traceability
- The design uses PHP (backend) and JavaScript/JSX (frontend) — no language selection needed
- Property tests validate universal correctness properties from the design document
