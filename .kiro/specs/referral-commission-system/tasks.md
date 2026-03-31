# Implementation Plan: Referral Commission System + Multi-Room Lottery

## Overview

Incremental implementation ordered so each layer depends only on what's already built:
database schema → backend core → API endpoints → frontend hooks → frontend components → admin panel.

## Tasks

- [x] 1. Database migrations
  - [x] 1.1 Add referral and audit columns to `users`
    - `ALTER TABLE users ADD COLUMN IF NOT EXISTS` for: `ref_code VARCHAR(32) UNIQUE NOT NULL DEFAULT ''`, `referred_by INT NULL`, `referral_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00`, `registration_ip VARCHAR(45) DEFAULT NULL`, `is_banned TINYINT(1) NOT NULL DEFAULT 0`, `referral_locked TINYINT(1) NOT NULL DEFAULT 0`, `referral_snapshot JSON DEFAULT NULL`
    - Add `FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL` and `INDEX idx_referred_by (referred_by)`
    - Apply to `database.sql` as safe `IF NOT EXISTS` migration block
    - _Requirements: 14.1_

  - [x] 1.2 Add multi-room and payout columns to `lottery_games`
    - `ALTER TABLE lottery_games ADD COLUMN IF NOT EXISTS` for: `room TINYINT NOT NULL DEFAULT 1`, `payout_status ENUM('pending','paid') NOT NULL DEFAULT 'pending'`, `payout_id VARCHAR(36) DEFAULT NULL`, `commission DECIMAL(12,2) DEFAULT NULL`, `referral_bonus DECIMAL(12,2) DEFAULT NULL`, `winner_net DECIMAL(12,2) DEFAULT NULL`
    - Add `INDEX idx_room_status (room, status)`
    - _Requirements: 14.2_

  - [x] 1.3 Add `room` column to `lottery_bets`
    - `ALTER TABLE lottery_bets ADD COLUMN IF NOT EXISTS room TINYINT NOT NULL DEFAULT 1`
    - _Requirements: 14.3_

  - [x] 1.4 Create `system_balance`, `system_transactions`, `user_transactions`, `registration_attempts` tables
    - `CREATE TABLE IF NOT EXISTS system_balance (id INT NOT NULL DEFAULT 1 PRIMARY KEY, balance DECIMAL(15,2) NOT NULL DEFAULT 0.00)` + seed row
    - `CREATE TABLE IF NOT EXISTS system_transactions` with all columns, `UNIQUE KEY uq_game_payout_type (game_id, payout_id, type)`, `INDEX idx_created_at`
    - `CREATE TABLE IF NOT EXISTS user_transactions` with all columns, `UNIQUE KEY uq_game_user_type (game_id, user_id, type, payout_id)`, `INDEX idx_user_created`
    - `CREATE TABLE IF NOT EXISTS registration_attempts` with `INDEX idx_ip_created (ip, created_at)`
    - _Requirements: 14.4, 14.5, 14.6, 14.7_

- [x] 2. Checkpoint — verify migrations
  - Ensure all migrations apply cleanly with no errors. Confirm new columns and tables exist. Ask the user if questions arise.

- [x] 3. Payout engine — `backend/includes/lottery.php`
  - [x] 3.1 Add `computePayoutAmounts(float $pot): array`
    - Pure function: if `$pot >= 0.50` compute `commission = GREATEST(ROUND($pot * 0.02, 2), 0.01)`, `referral_bonus = GREATEST(ROUND($pot * 0.01, 2), 0.01)`, `winner_net = $pot - commission - referral_bonus`; else all zero and `winner_net = $pot`
    - If `winner_net < 0` fall back to full-pot and log `[Payout] CRITICAL: winner_net < 0 for game {id}, falling back to full-pot payout`
    - Returns `['commission' => float, 'referral_bonus' => float, 'winner_net' => float]`
    - _Requirements: 1.1, 1.2, 3.1, 3.2, 3.3_

  - [x] 3.2 Write property test for `computePayoutAmounts` (P1, P2)
    - **Property 1: Financial Invariant** — generate random `pot >= 0.50`, assert `winner_net + commission + referral_bonus === pot` and all values `>= 0`
    - **Property 2: Micro-Pot Exception** — generate random `pot` in `[0.01, 0.49]`, assert `commission === 0`, `referral_bonus === 0`, `winner_net === pot`
    - Use eris/PHPUnit; tag `// Feature: referral-commission-system, Property 1` and `Property 2`
    - **Validates: Requirements 1.1, 1.2, 3.1**

  - [x] 3.3 Add `resolveReferrer(PDO $pdo, int $winnerId): ?array`
    - Query winner's `referred_by`; if null return null
    - Fast pre-check: if `referral_locked = 0` return null
    - Lock referrer row via `SELECT * FROM users WHERE id = ? FOR UPDATE`
    - Re-verify live: `is_verified = 1`, `is_banned = 0`, `created_at <= NOW() - INTERVAL 24 HOUR`, has completed deposit in `transactions`
    - If referrer row deleted between pre-check and lock, return null
    - If `is_banned = 1`, log `[Referral] Banned referrer {id} — bonus unclaimed for game {id}` and return null
    - _Requirements: 2.1, 2.5, 12.4, 12.5, 12.6_

  - [x] 3.4 Extend `finishGameSafe` with payout engine phase
    - After `pickWeightedWinner`, call `computePayoutAmounts($pot)` to get `commission`, `referral_bonus`, `winner_net`
    - Generate `$payoutId = $pdo->query("SELECT UUID()")->fetchColumn()`; check `payout_status = 'paid'` guard first — if already paid, rollback and return cached result
    - Lock users in deterministic order: `SELECT * FROM users WHERE id IN (winner_id[, referrer_id]) ORDER BY id ASC FOR UPDATE`
    - Lock `system_balance WHERE id = 1 FOR UPDATE`
    - `UPDATE users SET balance = balance + winner_net WHERE id = winner_id`
    - `INSERT INTO user_transactions (user_id, type, amount, game_id, payout_id) VALUES (winner_id, 'win', winner_net, game_id, payout_id)`
    - Call `resolveReferrer`; if eligible: credit referrer balance + `referral_earnings`, insert `user_transactions` row `type='referral_bonus'`, insert one `system_transactions` row `type='commission'`, update `system_balance += commission`
    - If no eligible referrer: insert two `system_transactions` rows (`type='commission'` and `type='referral_unclaimed'`), update `system_balance += commission + referral_bonus`
    - `UPDATE lottery_games SET payout_status='paid', payout_id=?, commission=?, referral_bonus=?, winner_net=?, status='finished', winner_id=?, finished_at=NOW() WHERE id=?`
    - Wrap entire payout phase in retry loop: up to 3 retries on MySQL error 1213/1205; after 3 failures log `[Payout] FATAL: 3 retries exhausted for game {id}` and rethrow
    - On unique constraint violation treat as already-paid, rollback, log warning
    - Remove old direct `UPDATE users SET balance = balance + pot` and `INSERT INTO transactions` winner credit (replaced by payout engine)
    - _Requirements: 1.4, 1.5, 1.6, 2.1, 2.2, 2.3, 2.4, 3.4, 3.5, 3.6, 3.7, 4.1, 4.2, 4.3, 4.4, 4.6, 4.7, 15.1, 15.2, 16.1_

  - [x] 3.5 Write property tests for payout engine (P3–P8)
    - **Property 3: System Balance Monotonicity** — generate random payout sequences, verify `system_balance >= 0` after each
    - **Property 4: Payout Audit Trail** — generate payouts with/without referrer, verify correct `system_transactions` row counts
    - **Property 5: Referrer Credit** — generate pots with eligible referrers, verify balance and `referral_earnings` deltas
    - **Property 6: Payout Idempotency** — call payout engine twice on same game, verify no second-call side effects
    - **Property 7: Payout ID Propagation** — verify all log rows share same non-null `payout_id`
    - **Property 8: Snapshot Consistency** — verify stored `commission/referral_bonus/winner_net` match credited amounts
    - Use eris/PHPUnit; tag each with `// Feature: referral-commission-system, Property N`
    - **Validates: Requirements 1.4, 1.5, 1.6, 2.1, 2.2, 2.3, 2.4, 3.7, 4.2, 4.3, 4.6, 16.1, 16.2**

- [x] 4. Multi-room core — `backend/includes/lottery.php`
  - [x] 4.1 Add `$room` parameter to `getOrCreateActiveGame`, `getGameState`, `getLastFinishedGame`
    - `getOrCreateActiveGame(PDO $pdo, int $room): array` — validate `$room IN (1, 10, 100)` or throw `InvalidArgumentException`; scope all queries with `WHERE room = ?`; set `room = $room` on INSERT
    - `getGameState(PDO $pdo, int $room, ?int $userId): array` — pass `$room` through; include `room` field in returned game object
    - `getLastFinishedGame(PDO $pdo, int $room): ?array` — scope to `WHERE g.room = ? AND g.status = 'finished'`
    - _Requirements: 5.2, 5.7, 6.1, 6.6_

  - [x] 4.2 Add `$room` parameter to `placeBet`; insert `user_transactions` bet row
    - `placeBet(PDO $pdo, int $userId, int $room, string $clientSeed): array`
    - Validate `$room IN (1, 10, 100)`; bet amount = `$room` (not hardcoded `LOTTERY_BET`)
    - Insert `room` column into `lottery_bets`
    - After deducting balance, insert `user_transactions (user_id, type='bet', amount=$room, game_id, payout_id=NULL)`
    - Remove old `INSERT INTO transactions` bet record (replaced by `user_transactions`)
    - _Requirements: 5.5, 5.8, 6.2, 6.4, 15.3_

  - [x] 4.3 Write property tests for room isolation (P9, P10, P11)
    - **Property 9: Room Independence** — place bets in two rooms, verify no cross-room pot/winner contamination
    - **Property 10: Room API Scoping** — generate valid/invalid room values, verify status returns correct room or HTTP 400
    - **Property 11: Bet Amount = Room Step** — generate random valid rooms and users, verify deducted amount equals room value
    - Use eris/PHPUnit; tag `// Feature: referral-commission-system, Property 9/10/11`
    - **Validates: Requirements 5.2, 5.3, 6.1, 6.2, 6.3, 6.4**

- [x] 5. Checkpoint — verify backend core
  - Ensure all tests pass. Confirm `computePayoutAmounts`, `resolveReferrer`, `finishGameSafe` payout phase, and room-scoped functions work correctly. Ask the user if questions arise.

- [x] 6. Registration handler — `backend/api/auth/register.php`
  - [x] 6.1 Add rate limiting and disposable email check
    - Before any user writes: INSERT into `registration_attempts (ip, created_at)` using `$_SERVER['REMOTE_ADDR']`
    - COUNT rows for same IP in last 1 hour (including just-inserted); if count > 3, rollback INSERT, return HTTP 429
    - Skip rate limit for bot users (`is_bot = 1` — not applicable at registration, so always apply for new registrations)
    - After email format check: extract domain, reject if in blocklist (`mailinator.com`, `guerrillamail.com`, `tempmail.com`, `throwaway.email`, `yopmail.com`, `sharklasers.com`, `trashmail.com`, `maildrop.cc`, `dispostable.com`, `fakeinbox.com`), return HTTP 400 `"Email domain not allowed."`
    - _Requirements: 13.1, 13.2, 13.3, 20.1_

  - [x] 6.2 Add `ref_code` generation with retry loop
    - After user INSERT: generate `ref_code = strtoupper(bin2hex(random_bytes(6)))`, attempt `UPDATE users SET ref_code = ? WHERE id = ?`
    - On `DUPLICATE KEY` error, retry up to 3 times; if all fail, return HTTP 500 with logged error
    - _Requirements: 10.1, 10.2, 10.3, 10.4_

  - [x] 6.3 Add referral code capture and eligibility check
    - Read `referral_code` from request body; if present, look up `SELECT * FROM users WHERE ref_code = ? COLLATE utf8mb4_unicode_ci`
    - Check all Eligible_Referrer criteria: `is_verified = 1`, `is_banned = 0`, `created_at <= NOW() - INTERVAL 24 HOUR`, has completed deposit, IP doesn't match `$_SERVER['REMOTE_ADDR']`
    - If all pass: set `referred_by = referrer_id`, `referral_locked = 1`, `referral_snapshot = JSON({referrer_id, is_verified, had_deposit, created_at, locked_at})`
    - If any check fails: `referred_by = NULL`, log reason with `[Referral]` prefix; registration continues without error
    - Same-IP block: log `[Referral] Same-IP block: referrer_id={id} ip={ip}`
    - Store `registration_ip = $_SERVER['REMOTE_ADDR']`
    - _Requirements: 11.4, 11.5, 11.6, 12.1, 12.2, 12.3, 12.4, 12.5, 12.6, 20.2, 20.3, 20.4_

  - [x] 6.4 Write property tests for registration (P14, P16, P17, P18, P20)
    - **Property 14: ref_code Format** — generate random registrations, verify `ref_code` matches `/^[0-9A-F]{12}$/`
    - **Property 16: Referral Eligibility** — generate referrer states (banned, unverified, young account, no deposit, same IP), verify `referred_by = NULL`
    - **Property 17: referred_by Immutability** — attempt update via any API endpoint, verify field unchanged
    - **Property 18: Rate Limiting** — generate IPs with > 3 attempts in last hour, verify HTTP 429
    - **Property 20: Disposable Email** — generate emails with blocked domains, verify all rejected
    - Use eris/PHPUnit; tag each with `// Feature: referral-commission-system, Property N`
    - **Validates: Requirements 10.1, 11.5, 11.6, 12.1, 13.1, 20.1, 20.5**

- [x] 7. API endpoints — update `status.php` and `bet.php`
  - [x] 7.1 Update `backend/api/lottery/status.php` to accept `?room=` param
    - Read `$room = (int)($_GET['room'] ?? 1)`; validate against `[1, 10, 100]`; if invalid return HTTP 400 `"Invalid room. Must be 1, 10, or 100."`
    - Pass `$room` to `getGameState($pdo, $room, $userId)` and `getLastFinishedGame($pdo, $room)`
    - _Requirements: 6.1, 6.3, 6.6_

  - [x] 7.2 Update `backend/api/lottery/bet.php` to accept `room` from body
    - Read `$room = (int)($input['room'] ?? 1)`; validate against `[1, 10, 100]`; if invalid return HTTP 400
    - Pass `$room` to `placeBet($pdo, $userId, $room, $clientSeed)`
    - _Requirements: 6.2, 6.3, 6.4_

  - [x] 7.3 Create `backend/api/admin/system_balance.php`
    - Require valid admin session (reuse existing admin auth pattern from `backend/api/admin/me.php`)
    - Return: `system_balance.balance`, `SUM(amount) WHERE type='commission'`, `SUM(amount) WHERE type='referral_unclaimed'`
    - Paginated `system_transactions` (20/page): `id, amount, type, source_user_id, payout_id, created_at` + join `users.email` as `source_email`
    - Accept `?page=` query param
    - _Requirements: 18.2, 18.3, 18.4_

- [x] 8. Cleanup cron — `backend/cron/cleanup.php`
  - Create `backend/cron/cleanup.php`
  - `DELETE FROM registration_attempts WHERE created_at < NOW() - INTERVAL 7 DAY`
  - Log `[Cleanup] Deleted {N} registration_attempts older than 7 days`
  - _Requirements: 19.1, 19.3, 19.4, 19.5_

  - [x] 8.1 Write property test for cleanup retention (P21)
    - **Property 21: Cleanup Retention** — insert rows with mixed ages, run cleanup, verify only rows older than 7 days are deleted and recent rows are preserved
    - Use eris/PHPUnit; tag `// Feature: referral-commission-system, Property 21`
    - **Validates: Requirements 19.1**

- [x] 9. Checkpoint — verify backend API layer
  - Ensure all tests pass. Confirm room-scoped status/bet endpoints, system_balance admin endpoint, and cleanup script work correctly. Ask the user if questions arise.

- [x] 10. Frontend API client — `frontend/src/api/client.js`
  - Update `lotteryStatus(room = 1)` to call `/backend/api/lottery/status.php?room=${room}`
  - Update `lotteryBet(room, clientSeed)` to include `room` in request body: `{ room, client_seed: clientSeed }`
  - Add `adminSystemBalance(page = 1)` → `GET /backend/api/admin/system_balance.php?page=${page}`
  - _Requirements: 6.1, 6.2, 18.3_

- [x] 11. Frontend hooks
  - [x] 11.1 Update `frontend/src/hooks/useLottery.js` to accept `room` param
    - Accept `room` parameter (default `1`)
    - Poll `/lottery/status.php?room=${room}` every 1s
    - `placeBet()` sends `{ room, client_seed }` in body via `api.lotteryBet(room, seed)`
    - Reset state when `room` changes (clear `state`, `previous`, `betError`)
    - _Requirements: 7.6_

  - [x] 11.2 Update `frontend/src/hooks/useGameMachine.js` to add `COUNTDOWN` phase
    - Add `COUNTDOWN` phase between `BETTING` and `DRAWING` (rename current `SPINNING` → `DRAWING`)
    - Valid transitions: `IDLE→BETTING`, `BETTING→COUNTDOWN` (backend status `countdown`), `COUNTDOWN→DRAWING` (backend status `finished`), `DRAWING→RESULT` (timer), `RESULT→BETTING` (new game_id)
    - `BETTING→COUNTDOWN` transition: backend `status === 'countdown'`
    - `COUNTDOWN→DRAWING` transition: backend `status === 'finished'` while in `COUNTDOWN` (or `BETTING` with same game_id)
    - Reject invalid transitions: `IDLE→DRAWING`, `BETTING→RESULT`, `DRAWING→BETTING`, `RESULT→DRAWING`
    - _Requirements: 8.1, 8.2, 8.3, 8.4_

  - [x] 11.3 Write property tests for state machine (P12, P13, P15)
    - **Property 12: Valid Transitions Only** — generate random backend event sequences, verify only valid transitions occur and invalid ones are rejected
    - **Property 13: DRAWING Phase Duration** — generate phase transitions, verify RESULT persists >= 7500ms before transitioning on new game_id
    - **Property 15: Referral TTL** — generate random timestamps and TTL values, verify expired `anora_ref` entries are deleted on read and non-expired entries are returned
    - Use fast-check (npm); tag `// Feature: referral-commission-system, Property N`
    - **Validates: Requirements 8.2, 8.3, 8.4, 8.7, 9.1, 9.2, 9.4, 11.2**

- [x] 12. Frontend `App.jsx` — referral capture and new route
  - In `App.jsx` on mount: read `?ref=CODE` from URL; if present store `{ code: CODE, expires: Date.now() + 7*24*60*60*1000 }` to `localStorage` under key `anora_ref`
  - When reading `anora_ref`: check `expires`; if expired delete key and return null
  - Add lazy-loaded route `/admin/system-balance` → `<SystemBalance />` inside `<AdminRoute>`
  - _Requirements: 11.1, 11.2, 18.1_

- [x] 13. Frontend `authService.js` — pass referral code on register
  - Update `register(email, password, referralCode?)` to include `referral_code` in request body when present
  - _Requirements: 11.3_

- [x] 14. Frontend `Register.jsx` — read and clear `anora_ref`
  - On form submit: read `anora_ref` from localStorage (with TTL check); pass `code` as `referralCode` to `authService.register`
  - On successful registration: remove `anora_ref` from localStorage
  - _Requirements: 11.3, 11.7_

- [x] 15. Frontend lottery components
  - [x] 15.1 Update `LotteryPanel.jsx` to accept `room` prop
    - Accept `room` prop; pass to `useLottery(room)` and `useGameMachine`
    - Update bet amount display to show `$${room}` per bet
    - Update `canBet` logic to use `COUNTDOWN` and `DRAWING` phases (not `SPINNING`)
    - Update `uiLocked` to cover both `DRAWING` and `RESULT` phases
    - _Requirements: 7.4, 8.4, 9.4_

  - [x] 15.2 Update `frontend/src/pages/Home.jsx` to render room tabs
    - Add `activeRoom` state (default `1`)
    - Render three tab buttons: `$1`, `$10`, `$100` — visually highlight active tab
    - On tab click: update `activeRoom`, which causes `LotteryPanel` to remount/reset via `key={activeRoom}`
    - Render `<LotteryPanel key={activeRoom} room={activeRoom} />`
    - Tabs must be keyboard-navigable (use `<button>` elements with proper focus styles)
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7_

- [x] 16. Frontend Account page — referral dashboard and transaction history
  - In `frontend/src/pages/Account.jsx`:
    - Add referral dashboard section: display `https://anora.bet/?ref={ref_code}`, `referral_earnings`, referred user count (fetch from `/api/auth/me` which must return `ref_code`, `referral_earnings`, `referred_count`)
    - Add one-click copy button with "Copied!" confirmation for 2 seconds
    - Add paginated `user_transactions` history table: columns `type`, `amount`, `game_id`, `payout_id`, `created_at`
    - Fetch transactions from a new endpoint or extend existing account API
  - Update `backend/api/auth/me.php` to include `ref_code`, `referral_earnings`, and `referred_count` (COUNT of users with `referred_by = current_user_id`)
  - Add `backend/api/account/transactions.php` (if not already serving `user_transactions`): paginated `user_transactions` for current user
  - _Requirements: 15.5, 17.1, 17.2, 17.3, 17.4_

- [x] 17. Admin panel — System Balance page and nav entry
  - Create `frontend/src/pages/admin/SystemBalance.jsx`
    - Display current `system_balance.balance`, sum of commission entries, sum of referral_unclaimed entries
    - Paginated table of `system_transactions`: amount, type, source user email, payout_id, date
    - Fetch from `adminSystemBalance(page)` in `client.js`
    - Redirect to `/admin/login` if unauthenticated (handled by `AdminRoute`)
  - Update `frontend/src/components/AdminLayout.jsx`:
    - Add `<NavLink to="/admin/system-balance">System Balance</NavLink>` nav entry
    - Add `<Route path="system-balance" element={<SystemBalance />} />` inside Routes
  - _Requirements: 18.1, 18.2, 18.3, 18.4_

- [x] 18. Admin Lottery Games page — display new financial columns
  - Update `frontend/src/pages/admin/LotteryGames.jsx` to display `room`, `commission`, `referral_bonus`, `winner_net`, `payout_id` for finished games
  - Update `backend/api/admin/lottery_games.php` to include these columns in the response
  - _Requirements: 16.3_

- [x] 19. Final checkpoint — full integration
  - Ensure all tests pass. Verify end-to-end: place bets in each room, trigger finish, confirm winner balance, referrer balance, system_balance, all log rows, and game snapshot. Ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation at each layer boundary
- Property tests validate universal correctness; unit tests cover specific edge cases
- The payout engine replaces the old direct `UPDATE users SET balance = balance + pot` and `INSERT INTO transactions` winner credit — both must be removed in task 3.4
