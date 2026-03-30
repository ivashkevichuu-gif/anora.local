# Requirements Document

## Introduction

This document covers two interconnected features for the anora.bet lottery platform:

1. **Referral System + Platform Commission** — Every lottery round deducts a 2% platform commission and a 1% referral allocation from the pot. The winner receives the remainder. Full audit trail, idempotent payout engine, and admin visibility.

2. **Multi-Room Lottery** — Three independent lottery rooms with fixed bet steps ($1 / $10 / $100), each with its own pot, lifecycle, and game state. A tabbed React UI allows instant room switching. A strict frontend state machine (IDLE → BETTING → COUNTDOWN → DRAWING → RESULT) prevents race conditions and double triggers.

All payout logic (commission, referral, idempotency) applies identically across all rooms.

## Glossary

- **System**: The anora.bet lottery platform backend (PHP/MySQL InnoDB).
- **Room**: One of three independent lottery contexts identified by bet step: `1`, `10`, or `100`. Each room runs its own game lifecycle independently.
- **Bet_Step**: The fixed bet amount for a room — `$1`, `$10`, or `$100`. All bets in a room are exactly one Bet_Step.
- **Payout_Engine**: The component inside `finishGameSafe($gameId)` responsible for distributing winnings at the end of each lottery round.
- **Referral_Manager**: The backend component responsible for generating, validating, and resolving referral codes.
- **Registration_Handler**: The backend component at `backend/api/auth/register.php` that creates new user accounts.
- **System_Balance**: The platform's internal ledger (single row, `id=1`) that accumulates commission and unclaimed referral funds. Balance SHALL always be >= 0.
- **System_Transactions_Log**: The append-only `system_transactions` table recording every credit to the System_Balance.
- **User_Transactions_Log**: The append-only `user_transactions` table recording every balance change for each user, each row carrying a `payout_id` for tracing.
- **Frontend_State_Machine**: The React state machine controlling UI phases per room: `IDLE → BETTING → COUNTDOWN → DRAWING → RESULT`.
- **Admin_Panel**: The React admin interface accessible at `/admin/*`.
- **Account_Page**: The React user account page at `/account`.
- **ref_code**: A 12-character string (`strtoupper(bin2hex(random_bytes(6)))`) uniquely identifying a user for referral purposes.
- **referred_by**: The `user_id` of the user who referred the registering user.
- **Pot**: The total amount of funds accumulated in a lottery round from all bets.
- **MIN_POT_FOR_COMMISSION**: `0.50` — minimum pot value below which no commission is deducted and the full pot is paid to the winner.
- **Commission**: `GREATEST(ROUND(pot * 0.02, 2), 0.01)` when `pot >= MIN_POT_FOR_COMMISSION`.
- **Referral_Bonus**: `GREATEST(ROUND(pot * 0.01, 2), 0.01)` when `pot >= MIN_POT_FOR_COMMISSION`.
- **Winner_Net**: `pot - commission - referral_bonus` — computed by subtraction to absorb rounding remainder.
- **Payout_Status**: A field on `lottery_games` (`pending` | `paid`) that prevents double-payout.
- **Payout_ID**: A UUID generated at the start of each payout transaction, propagated to all related log rows for end-to-end tracing.
- **Eligible_Referrer**: A referrer whose live locked row satisfies: `is_verified = 1`, `is_banned = 0`, `created_at <= NOW() - INTERVAL 24 HOUR`, and has at least one completed deposit. The `referral_locked` flag provides a fast pre-check; the live locked row is always authoritative.
- **Referral_Snapshot**: JSON stored on the winner's `users` row at registration: `{ "referrer_id": N, "is_verified": true, "had_deposit": true, "created_at": "...", "locked_at": "..." }`. Audit record only.

---

## Requirements

### Requirement 1: Platform Commission Deduction

**User Story:** As the platform operator, I want a 2% commission deducted from every winning pot across all rooms, so that the platform generates revenue from each lottery round.

#### Acceptance Criteria

1. WHEN a lottery round finishes AND `pot >= 0.50`, THE Payout_Engine SHALL compute `commission = GREATEST(ROUND(pot * 0.02, 2), 0.01)`.
2. WHEN a lottery round finishes AND `pot < 0.50`, THE Payout_Engine SHALL set `commission = 0.00` and `referral_bonus = 0.00` and pay the full pot to the winner (micro-pot exception).
3. **Effective rake note:** For pots between `0.50` and approximately `1.00`, minimum thresholds may cause effective combined rake to exceed 3%. Example: `pot = 0.51` → effective rake ≈ 3.92%. This is expected and SHALL be documented in operator materials.
4. WHEN commission is non-zero, THE Payout_Engine SHALL acquire `SELECT balance FROM system_balance WHERE id = 1 FOR UPDATE` then execute `UPDATE system_balance SET balance = balance + ? WHERE id = 1` inside the payout transaction.
5. THE System_Balance `balance` SHALL never go below `0.00`; the Payout_Engine SHALL assert `system_balance.balance + commission >= 0` before committing.
6. WHEN commission is non-zero, THE Payout_Engine SHALL record a row in System_Transactions_Log with `type = 'commission'`, `amount = commission`, `source_user_id = winner_id`, `game_id = game_id`, `payout_id = payout_id`.
7. WHEN a lottery round finishes with zero bets, THE Payout_Engine SHALL NOT credit any commission or create any log entries.
8. THE System_Balance table SHALL contain exactly one row (`id = 1`); the application SHALL never INSERT additional rows.

---

### Requirement 2: Referral Bonus Payout and Transaction Logging

**User Story:** As a referrer, I want to earn 1% of the pot whenever a user I referred wins any room, and as an operator I want every payout fully logged.

#### Acceptance Criteria

1. WHEN a lottery round finishes AND `pot >= 0.50` AND the winner has a non-null `referred_by` AND the referrer row (locked via `FOR UPDATE`) satisfies all Eligible_Referrer criteria, THE Payout_Engine SHALL compute `referral_bonus = GREATEST(ROUND(pot * 0.01, 2), 0.01)` and credit it to the referrer's `balance`. Eligibility order: (a) check `referral_locked = 1` as fast pre-check; (b) lock referrer row via `FOR UPDATE`; (c) re-verify live row. If referrer row no longer exists, treat as unclaimed.
2. WHEN criterion 1 applies, THE Payout_Engine SHALL increment the referrer's `referral_earnings` by `referral_bonus` in the same transaction.
3. WHEN criterion 1 applies, THE Payout_Engine SHALL record **one** System_Transactions_Log entry: `type = 'commission'`, `amount = commission` (2%), `payout_id = payout_id`.
4. WHEN the winner has NO eligible referrer, THE Payout_Engine SHALL credit `commission + referral_bonus` to System_Balance and record **two** System_Transactions_Log entries:
   - `type = 'commission'`, `amount = commission` (2%), `source_user_id = winner_id`, `game_id = game_id`, `payout_id = payout_id`
   - `type = 'referral_unclaimed'`, `amount = referral_bonus` (1%), `source_user_id = winner_id`, `game_id = game_id`, `payout_id = payout_id`
5. WHEN the referrer has `is_banned = 1`, THE Payout_Engine SHALL treat the bonus as unclaimed (criterion 4 applies) and log `[Referral] Banned referrer {id} — bonus unclaimed for game {game_id}`.

---

### Requirement 3: Winner Net Payout Invariant and Non-Negative Guarantees

**User Story:** As a player, I want to receive the remainder of the pot after deductions, with a guaranteed financial invariant that no money is created or lost.

#### Acceptance Criteria

1. WHEN `pot >= 0.50`, THE Payout_Engine SHALL compute:
   - `commission = GREATEST(ROUND(pot * 0.02, 2), 0.01)`
   - `referral_bonus = GREATEST(ROUND(pot * 0.01, 2), 0.01)`
   - `winner_net = pot - commission - referral_bonus`
2. `winner_net` SHALL be computed by subtraction only. The invariant is enforced by this computation, NOT by re-summing for an equality check.
3. IF `winner_net < 0`, THE Payout_Engine SHALL set `commission = 0`, `referral_bonus = 0`, `winner_net = pot`, pay full pot to winner, and log `[Payout] CRITICAL: winner_net < 0 for game {id}, falling back to full-pot payout`. For `pot < 0`, `commission < 0`, or `referral_bonus < 0`, THE Payout_Engine SHALL rollback and log a critical error.
4. THE Payout_Engine SHALL execute ALL balance updates AND ALL log inserts within a single atomic InnoDB transaction at isolation level `READ COMMITTED`. Balance updates MUST be performed BEFORE log inserts. IF any operation fails, the entire transaction SHALL rollback.
5. THE Payout_Engine SHALL acquire `FOR UPDATE` locks in this deterministic order to prevent deadlocks:
   1. `lottery_games` row (by game id)
   2. `users` rows via `SELECT * FROM users WHERE id IN (winner_id, referrer_id) ORDER BY id ASC FOR UPDATE`
   3. `system_balance` row (`id = 1`)
6. THE Payout_Engine SHALL retry up to **3 times** on MySQL error `1213` (deadlock) or `1205` (lock wait timeout), using a fresh transaction each time. After 3 failures: log `[Payout] FATAL: 3 retries exhausted for game {id}` and surface the error.
7. Only ONE payout worker may process a given game at a time. The `FOR UPDATE` lock on `lottery_games` combined with the `payout_status` guard provides mutual exclusion for concurrent cron and manual triggers.

---

### Requirement 4: Idempotent Payout with Database-Level Uniqueness

**User Story:** As the platform operator, I want the payout to execute exactly once per game at both application and database level.

#### Acceptance Criteria

1. THE `lottery_games` table SHALL have `payout_status ENUM('pending','paid') NOT NULL DEFAULT 'pending'` and `payout_id VARCHAR(36) DEFAULT NULL`.
2. WHEN the Payout_Engine begins a payout, it SHALL acquire `SELECT * FROM lottery_games WHERE id = ? FOR UPDATE`, then check `payout_status`; IF `payout_status = 'paid'`, it SHALL abort and return the existing result (`winner_net`, `commission`, `referral_bonus`, `payout_id`) WITHOUT performing any operations.
3. THE `payout_id` UUID SHALL be generated at the **start** of the transaction (`SET @payout_id = UUID()`) and used in ALL log inserts and the final game update, so no log row can exist without a `payout_id`.
4. THE `system_transactions` table SHALL have `UNIQUE KEY uq_game_payout_type (game_id, payout_id, type)`.
5. THE `user_transactions` table SHALL have `UNIQUE KEY uq_game_user_type (game_id, user_id, type, payout_id)`.
6. ALL `user_transactions` rows created during a payout SHALL carry the `payout_id`.
7. IF a database-level unique constraint violation occurs during payout, THE Payout_Engine SHALL treat it as already-paid, rollback, and log a warning without propagating an error.

---

### Requirement 5: Multi-Room Lottery Architecture

**User Story:** As a player, I want to choose between three independent lottery rooms ($1, $10, $100) so that I can play at my preferred stake level.

#### Acceptance Criteria

1. THE System SHALL support exactly **three rooms** identified by their bet step: `1`, `10`, and `100`.
2. Each room SHALL maintain a completely independent game lifecycle: separate `game_id`, separate pot, separate player list, separate countdown timer.
3. A player joining Room $10 SHALL NOT affect the pot or player count of Room $1 or Room $100.
4. THE `lottery_games` table SHALL have a `room TINYINT NOT NULL DEFAULT 1` column with `CHECK (room IN (1, 10, 100))` (enforced at application level).
5. THE `lottery_bets` table SHALL have a `room TINYINT NOT NULL DEFAULT 1` column consistent with the game's room.
6. THE System SHALL maintain `CREATE INDEX idx_room_status ON lottery_games(room, status)` for efficient active-game lookups per room.
7. THE `getOrCreateActiveGame($pdo, $room)` function SHALL accept a `$room` parameter and scope all queries to that room.
8. THE `placeBet($pdo, $userId, $room, $clientSeed)` function SHALL validate that `$room IN (1, 10, 100)` and that the bet amount equals the room's Bet_Step.
9. THE `finishGameSafe($pdo, $gameId)` function SHALL NOT require a room parameter — it operates on a specific game_id which already encodes the room.

---

### Requirement 6: Multi-Room Backend API

**User Story:** As a frontend developer, I want clean API endpoints scoped by room so that each tab can independently fetch and update its room's state.

#### Acceptance Criteria

1. `GET /backend/api/lottery/status.php?room=10` SHALL return the current game state for Room $10 only, including: `game` (id, status, total_pot, countdown, winner, room), `bets`, `unique_players`, `total_bets`, `my_stats`.
2. `POST /backend/api/lottery/bet.php` SHALL accept `{ "room": 10, "client_seed": "..." }` in the request body and place a bet of exactly `$10` in Room $10.
3. THE backend SHALL validate that `room` is one of `[1, 10, 100]`; invalid room values SHALL return HTTP 400.
4. THE backend SHALL validate that the user's balance is sufficient for the room's Bet_Step before placing a bet.
5. All existing rate limiting, client_seed validation, and payout logic SHALL apply identically across all rooms.
6. THE `status.php` endpoint SHALL return `room` in the game object so the frontend can verify it received data for the correct room.

---

### Requirement 7: Frontend Room Tabs UI

**User Story:** As a player, I want a tabbed interface to switch between the three lottery rooms instantly, so that I can monitor and participate in any room.

#### Acceptance Criteria

1. THE Lottery page SHALL display three tabs: `$1`, `$10`, `$100`.
2. THE active tab SHALL be visually highlighted (distinct background, border, or color).
3. WHEN a user clicks a tab, THE Frontend SHALL:
   - Immediately switch the active room state.
   - Reset the local animation state (cancel any in-progress winner animation for the previous room).
   - Begin polling the new room's status endpoint.
4. WHEN switching rooms, THE Frontend SHALL NOT carry over pot display, bet list, countdown, or winner data from the previous room.
5. THE default active room on page load SHALL be Room $1.
6. THE Frontend SHALL poll `GET /backend/api/lottery/status.php?room={activeRoom}` every 1 second for the active room only. Inactive rooms SHALL NOT be polled.
7. THE room selector SHALL be accessible and keyboard-navigable.

---

### Requirement 8: Frontend State Machine (Per Room)

**User Story:** As a developer, I want a strict per-room state machine so that UI transitions are deterministic and race conditions are impossible.

#### Acceptance Criteria

1. Each room SHALL have an independent state machine instance with phases: `IDLE → BETTING → COUNTDOWN → DRAWING → RESULT`.
2. Valid transitions SHALL be:
   - `IDLE → BETTING`: backend game status is `waiting` or `countdown`
   - `BETTING → COUNTDOWN`: backend game status changes to `countdown`
   - `COUNTDOWN → DRAWING`: backend game status changes to `finished` (triggers winner animation)
   - `DRAWING → RESULT`: winner animation completes (after `SPIN_DURATION + RESULT_HOLD` ms)
   - `RESULT → BETTING`: backend reports a new `game_id` for this room
3. THE state machine SHALL reject invalid transitions — e.g. `IDLE → DRAWING` SHALL NOT be allowed.
4. WHEN the state is `DRAWING`, THE Frontend SHALL lock all betting UI and ignore backend `finished` status updates (the animation is already running).
5. WHEN the state is `RESULT`, THE Frontend SHALL keep the winner carousel visible and locked until a new `game_id` is detected from the backend.
6. WHEN a user switches rooms, THE state machine for the previous room SHALL be suspended (polling stops); the new room's state machine SHALL resume from its last known state or `IDLE`.
7. THE `RESULT` phase SHALL persist for a minimum of `SPIN_DURATION (5500ms) + RESULT_HOLD (2000ms) = 7500ms` before transitioning to `BETTING` on a new game_id.

---

### Requirement 9: Winner Animation Persistence

**User Story:** As a player, I want the winner animation to stay visible long enough for me to read the result, so that I don't miss who won.

#### Acceptance Criteria

1. WHEN the state machine enters `DRAWING`, THE winner animation SHALL start immediately and run for exactly `SPIN_DURATION = 5500ms`.
2. AFTER the animation completes, THE UI SHALL remain in `RESULT` phase showing the winner name, win amount, and highlighted carousel tile for at least `RESULT_HOLD = 2000ms`.
3. THE `RESULT` phase SHALL only end when BOTH conditions are true: (a) `RESULT_HOLD` has elapsed AND (b) the backend reports a new `game_id` for this room.
4. DURING `DRAWING` and `RESULT` phases, THE "Place Bet" button SHALL be disabled and the betting UI SHALL be locked.
5. WHEN a user switches to a different room tab during `DRAWING` or `RESULT`, the animation for the original room SHALL be suspended; returning to that tab SHALL resume the `RESULT` display if still within the hold window.

---

### Requirement 10: Referral Code Generation

**User Story:** As a new user, I want a unique referral code assigned to my account at registration.

#### Acceptance Criteria

1. WHEN a new user account is created, THE Registration_Handler SHALL generate a `ref_code` using `strtoupper(bin2hex(random_bytes(6)))` producing a 12-character uppercase hex string.
2. THE Registration_Handler SHALL attempt to INSERT with the generated `ref_code`; IF a `DUPLICATE KEY` error occurs, it SHALL regenerate and retry up to **3** times.
3. IF all 3 attempts fail, THE Registration_Handler SHALL return HTTP 500 with a logged error.
4. THE System SHALL store `ref_code` as `VARCHAR(32) UNIQUE NOT NULL` with an index.
5. Bot users (`is_bot = 1`) SHALL also receive a `ref_code` using the same logic.

---

### Requirement 11: Referral Link Capture with TTL

**User Story:** As a potential new user arriving via a referral link, I want the platform to remember who referred me for 7 days.

#### Acceptance Criteria

1. WHEN a user visits `https://anora.bet/?ref=CODE`, THE Frontend SHALL store `{ code: CODE, expires: Date.now() + 7*24*60*60*1000 }` in `localStorage` under key `anora_ref`.
2. WHEN reading `anora_ref`, THE Frontend SHALL check `expires`; IF expired, delete and treat as absent.
3. WHEN a stored non-expired `anora_ref` exists AND the user registers, THE Frontend SHALL include `referral_code` in the request body.
4. WHEN the Registration_Handler receives a `referral_code`, it SHALL look up the matching `ref_code` (case-insensitive).
5. IF a matching Eligible_Referrer is found AND IPs don't match, THE Registration_Handler SHALL set `referred_by`.
6. IF any check fails, `referred_by = NULL` and registration completes without error.
7. WHEN registration completes, THE Frontend SHALL remove `anora_ref` from `localStorage`.

---

### Requirement 12: Referral Validation and Immutability

**User Story:** As the platform operator, I want referral relationships to be immutable and abuse-resistant.

#### Acceptance Criteria

1. `referred_by` SHALL be set only at account creation and SHALL NOT be updatable by any API endpoint.
2. THE System SHALL store `registration_ip VARCHAR(45)` and populate it at registration.
3. Same-IP registrations SHALL have `referred_by = NULL` with log `[Referral] Same-IP block: referrer_id={id} ip={ip}`.
4. THE referrer SHALL have `created_at <= NOW() - INTERVAL 24 HOUR`; younger accounts → `referred_by = NULL`.
5. THE referrer SHALL have at least one completed deposit; no deposit → `referred_by = NULL`.
6. THE referrer SHALL have `is_banned = 0`; banned → `referred_by = NULL`.
7. THE System SHALL add `INDEX idx_referred_by (referred_by)` on the `users` table.

---

### Requirement 13: Registration Rate Limiting

**User Story:** As the platform operator, I want to limit registrations per IP to prevent mass account creation.

#### Acceptance Criteria

1. THE Registration_Handler SHALL use INSERT-then-check:
   - INSERT a row into `registration_attempts (ip, created_at)`.
   - COUNT rows for same IP in last 1 hour (including just-inserted row).
   - IF count > **3**, rollback INSERT and return HTTP 429.
2. The INSERT and check SHALL occur before any user account writes.
3. THE `registration_attempts` table SHALL have `INDEX idx_ip_created (ip, created_at)`.
4. Bot users (`is_bot = 1`) SHALL be exempt.

---

### Requirement 14: Database Schema Changes

**User Story:** As a developer, I want the database schema updated to support multi-room, referral tracking, system balance, idempotency, and full audit trails.

#### Acceptance Criteria

1. THE System SHALL add to `users`:
   - `ref_code VARCHAR(32) UNIQUE NOT NULL`
   - `referred_by INT NULL` (FK → `users.id` ON DELETE SET NULL)
   - `referral_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00`
   - `registration_ip VARCHAR(45) DEFAULT NULL`
   - `is_banned TINYINT(1) NOT NULL DEFAULT 0`
   - `referral_locked TINYINT(1) NOT NULL DEFAULT 0` — set to `1` at registration when all eligibility checks pass
   - `referral_snapshot JSON DEFAULT NULL` — audit record of eligibility state at registration
   - `INDEX idx_referred_by (referred_by)`
2. THE System SHALL add to `lottery_games`:
   - `room TINYINT NOT NULL DEFAULT 1`
   - `payout_status ENUM('pending','paid') NOT NULL DEFAULT 'pending'`
   - `payout_id VARCHAR(36) DEFAULT NULL`
   - `commission DECIMAL(12,2) DEFAULT NULL`
   - `referral_bonus DECIMAL(12,2) DEFAULT NULL`
   - `winner_net DECIMAL(12,2) DEFAULT NULL`
   - `INDEX idx_room_status (room, status)`
3. THE System SHALL add to `lottery_bets`:
   - `room TINYINT NOT NULL DEFAULT 1`
4. THE System SHALL create `system_balance`: `id INT NOT NULL DEFAULT 1 PRIMARY KEY`, `balance DECIMAL(15,2) NOT NULL DEFAULT 0.00`, seeded with `(1, 0.00)`.
5. THE System SHALL create `system_transactions`: `id INT AUTO_INCREMENT PRIMARY KEY`, `game_id INT NULL`, `payout_id VARCHAR(36) DEFAULT NULL`, `amount DECIMAL(12,2) NOT NULL`, `type VARCHAR(32) NOT NULL`, `source_user_id INT NULL`, `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `UNIQUE KEY uq_game_payout_type (game_id, payout_id, type)`, `INDEX idx_created_at (created_at)`.
6. THE System SHALL create `user_transactions`: `id INT AUTO_INCREMENT PRIMARY KEY`, `user_id INT NOT NULL`, `payout_id VARCHAR(36) DEFAULT NULL`, `type VARCHAR(32) NOT NULL`, `amount DECIMAL(12,2) NOT NULL`, `game_id INT NULL`, `note VARCHAR(255) DEFAULT NULL`, `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `UNIQUE KEY uq_game_user_type (game_id, user_id, type, payout_id)`, `INDEX idx_user_created (user_id, created_at)`.
7. THE System SHALL create `registration_attempts`: `id INT AUTO_INCREMENT PRIMARY KEY`, `ip VARCHAR(45) NOT NULL`, `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `INDEX idx_ip_created (ip, created_at)`.
8. ALL changes SHALL be applied as safe `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` / `CREATE TABLE IF NOT EXISTS` migrations.

---

### Requirement 15: User Audit Trail

**User Story:** As a player, I want a complete history of every balance change traceable to a specific payout event.

#### Acceptance Criteria

1. WHEN a user wins, THE Payout_Engine SHALL insert: `type = 'win'`, `amount = winner_net`, `user_id = winner_id`, `game_id`, `payout_id`.
2. WHEN a referrer earns a bonus, THE Payout_Engine SHALL insert: `type = 'referral_bonus'`, `amount = referral_bonus`, `user_id = referrer_id`, `game_id`, `payout_id`.
3. WHEN a user bets, THE bet handler SHALL insert: `type = 'bet'`, `amount = bet_amount`, `user_id`, `game_id`.
4. THE table SHALL be append-only — no UPDATE or DELETE by the application.
5. THE Account_Page SHALL display `user_transactions` history in a paginated table.

---

### Requirement 16: Game Financial Snapshot

**User Story:** As the platform operator, I want each finished game to store its exact financial breakdown for independent verification.

#### Acceptance Criteria

1. WHEN the Payout_Engine completes a payout, it SHALL store on `lottery_games`: `commission`, `referral_bonus`, `winner_net`, `payout_status = 'paid'`, `payout_id`.
2. THE stored values SHALL match exactly what was credited in the same transaction.
3. THE admin Lottery Games page SHALL display `room`, `commission`, `referral_bonus`, `winner_net`, and `payout_id` for finished games.

---

### Requirement 17: User Referral Dashboard

**User Story:** As a registered user, I want to see my referral link, earnings, and referred user count.

#### Acceptance Criteria

1. THE Account_Page SHALL display `https://anora.bet/?ref={ref_code}`.
2. THE Account_Page SHALL display `referral_earnings` from `/api/auth/me` (which SHALL include `ref_code` and `referral_earnings`).
3. THE Account_Page SHALL display the count of users with `referred_by = current_user_id`.
4. THE Account_Page SHALL provide a one-click copy button with "Copied!" confirmation for at least 2 seconds.

---

### Requirement 18: Admin System Balance Panel

**User Story:** As an admin, I want a dedicated System Balance page to monitor platform revenue and referral activity.

#### Acceptance Criteria

1. THE Admin_Panel SHALL include a "System Balance" navigation entry.
2. THE page SHALL display: current `system_balance.balance`, sum of `commission` entries, sum of `referral_unclaimed` entries.
3. THE page SHALL display a paginated table of `system_transactions`: amount, type, source user email, payout_id, date.
4. ALL data SHALL be fetched from a protected admin endpoint requiring a valid admin session.

---

### Requirement 19: Data Retention and Cleanup

**User Story:** As the platform operator, I want transient tables cleaned up automatically.

#### Acceptance Criteria

1. `registration_attempts` SHALL have a **7-day** retention policy; rows older than 7 days SHALL be deleted.
2. `system_transactions` and `user_transactions` SHALL be retained **permanently**.
3. THE System SHALL provide a cleanup script: `DELETE FROM registration_attempts WHERE created_at < NOW() - INTERVAL 7 DAY;`
4. THE script SHALL run at least once per day via cron: `0 3 * * * php /path/to/cleanup.php`.
5. THE script SHALL log: `[Cleanup] Deleted {N} registration_attempts older than 7 days`.

---

### Requirement 20: Multi-Account Farming Prevention

**User Story:** As the platform operator, I want basic safeguards against referral farming.

#### Acceptance Criteria

1. THE Registration_Handler SHALL reject registration if the email domain is in: `mailinator.com`, `guerrillamail.com`, `tempmail.com`, `throwaway.email`, `yopmail.com`, `sharklasers.com`, `trashmail.com`, `maildrop.cc`, `dispostable.com`, `fakeinbox.com`.
2. THE referrer SHALL satisfy all Eligible_Referrer criteria before `referred_by` is set.
3. Same-IP registrations SHALL have `referred_by = NULL`.
4. THE System SHALL log all referral eligibility failures with reason codes.
5. `referred_by` immutability SHALL be enforced — no UPDATE endpoint exists for this field.
