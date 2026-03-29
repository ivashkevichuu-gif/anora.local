# Requirements Document

## Introduction

This feature adds a Referral System and Platform Commission model to the anora.bet lottery platform. Every lottery round deducts a 2% platform commission and a 1% referral allocation from the pot. If the winner was referred by an eligible user, the 1% goes to the referrer; otherwise it goes to the platform. The winner receives the remainder of the pot after deductions, guaranteeing the financial invariant: `winner_net + commission + referral_bonus = pot` exactly. The feature includes referral code generation, referral tracking on registration, idempotent payout logic with database-level uniqueness guarantees, a full audit trail, and an admin System Balance panel.

## Glossary

- **System**: The anora.bet lottery platform backend (PHP/MySQL InnoDB).
- **Payout_Engine**: The component inside `finishGameSafe()` responsible for distributing winnings at the end of each lottery round.
- **Referral_Manager**: The backend component responsible for generating, validating, and resolving referral codes.
- **Registration_Handler**: The backend component at `backend/api/auth/register.php` that creates new user accounts.
- **System_Balance**: The platform's internal ledger (single row, `id=1`) that accumulates commission and unclaimed referral funds. Balance SHALL always be >= 0.
- **System_Transactions_Log**: The append-only `system_transactions` table recording every credit to the System_Balance.
- **User_Transactions_Log**: The append-only `user_transactions` table recording every balance change for each user, each row carrying a `payout_id` for tracing.
- **Admin_Panel**: The React admin interface accessible at `/admin/*`.
- **Account_Page**: The React user account page at `/account`.
- **ref_code**: A 12-character alphanumeric string (`bin2hex(random_bytes(6))`) uniquely identifying a user for referral purposes.
- **referred_by**: The `user_id` of the user who referred the registering user.
- **Pot**: The total amount of funds accumulated in a lottery round from all bets.
- **MIN_POT_FOR_COMMISSION**: `0.50` — minimum pot value below which no commission is deducted and the full pot is paid to the winner.
- **Commission**: `GREATEST(ROUND(pot * 0.02, 2), 0.01)` when `pot >= MIN_POT_FOR_COMMISSION`.
- **Referral_Bonus**: `GREATEST(ROUND(pot * 0.01, 2), 0.01)` when `pot >= MIN_POT_FOR_COMMISSION`.
- **Winner_Net**: `pot - commission - referral_bonus` — computed by subtraction to absorb rounding remainder. This is NOT guaranteed to be exactly 97%; it is the remainder after deductions.
- **Payout_Status**: A field on `lottery_games` (`pending` | `paid`) that prevents double-payout.
- **Payout_ID**: A UUID generated once per payout, stored on `lottery_games` and propagated to all related `user_transactions` and `system_transactions` rows for end-to-end tracing.
- **Eligible_Referrer**: A referrer whose eligibility is evaluated at payout time using a snapshot locked via `SELECT ... FOR UPDATE`: `is_verified = 1`, `is_banned = 0`, `account_age >= 24h`, and has at least one completed deposit. The `referral_locked` flag (set to `1` at registration when all eligibility checks passed) provides a fast pre-check; the authoritative check is always the live locked referrer row at payout time. If the referrer's state changes between registration and payout (e.g. banned, deposit refunded), the live check takes precedence.
- **Referral_Snapshot**: A JSON field `referral_snapshot` stored on the winner's `users` row at registration time, capturing `{ "referrer_id": N, "is_verified": true, "had_deposit": true, "created_at": "...", "locked_at": "..." }`. Used as a fallback if the referrer row is deleted before payout.

---

## Requirements

### Requirement 1: Platform Commission Deduction

**User Story:** As the platform operator, I want a 2% commission deducted from every winning pot, so that the platform generates revenue from each lottery round.

#### Acceptance Criteria

1. WHEN a lottery round finishes AND `pot >= 0.50`, THE Payout_Engine SHALL compute `commission = GREATEST(ROUND(pot * 0.02, 2), 0.01)`.
2. WHEN a lottery round finishes AND `pot < 0.50`, THE Payout_Engine SHALL set `commission = 0.00` and `referral_bonus = 0.00` and pay the full pot to the winner (micro-pot exception).
3. **Effective rake note:** For pots between `0.50` and approximately `1.00`, the minimum thresholds (`0.01` each) may cause the effective combined rake to exceed 3%. This is expected behaviour and SHALL be documented in operator-facing materials. Example: `pot = 0.51` → `commission = 0.01`, `referral_bonus = 0.01`, effective rake ≈ 3.92%.
3. WHEN commission is non-zero, THE Payout_Engine SHALL acquire `SELECT balance FROM system_balance WHERE id = 1 FOR UPDATE` before updating, then execute `UPDATE system_balance SET balance = balance + ? WHERE id = 1` inside the payout transaction.
4. THE System_Balance `balance` SHALL never go below `0.00`; the Payout_Engine SHALL assert `system_balance.balance + commission >= 0` before committing.
5. WHEN commission is non-zero, THE Payout_Engine SHALL record a row in System_Transactions_Log with `type = 'commission'`, `amount = commission`, `source_user_id = winner_id`, `game_id = game_id`, `payout_id = payout_id`.
6. WHEN a lottery round finishes with zero bets, THE Payout_Engine SHALL NOT credit any commission or create any log entries.
7. THE System_Balance table SHALL contain exactly one row (`id = 1`); the application SHALL never INSERT additional rows.

---

### Requirement 2: Referral Bonus Payout and Transaction Logging

**User Story:** As a referrer, I want to earn 1% of the pot whenever a user I referred wins, and as an operator I want every payout fully logged so analytics are accurate.

#### Acceptance Criteria

1. WHEN a lottery round finishes AND `pot >= 0.50` AND the winner has a non-null `referred_by` AND the referrer row (locked via `FOR UPDATE`) satisfies all Eligible_Referrer criteria at payout time, THE Payout_Engine SHALL compute `referral_bonus = GREATEST(ROUND(pot * 0.01, 2), 0.01)` and credit it to the referrer's `balance`. Eligibility is evaluated in this order: (a) check `referral_locked = 1` on the winner's row as a fast pre-check; (b) lock the referrer row via `FOR UPDATE`; (c) re-verify `is_verified`, `is_banned`, `account_age`, and deposit existence on the live row. If the referrer row no longer exists, fall back to the `referral_snapshot` JSON on the winner's row to determine if the referral was originally valid — if valid in snapshot but referrer deleted, treat as unclaimed.
2. WHEN criterion 1 applies, THE Payout_Engine SHALL increment the referrer's `referral_earnings` by `referral_bonus` in the same transaction.
3. WHEN criterion 1 applies, THE Payout_Engine SHALL record **one** entry in System_Transactions_Log: `type = 'commission'`, `amount = commission` (2%), `payout_id = payout_id`. No `referral_unclaimed` entry is created because the 1% was paid to the referrer.
4. WHEN a lottery round finishes AND `pot >= 0.50` AND the winner has NO eligible referrer (null `referred_by`, deleted referrer, banned referrer, or ineligible referrer), THE Payout_Engine SHALL credit `commission + referral_bonus` to the System_Balance and record **two** entries in System_Transactions_Log:
   - `type = 'commission'`, `amount = commission` (2%), `source_user_id = winner_id`, `game_id = game_id`, `payout_id = payout_id`
   - `type = 'referral_unclaimed'`, `amount = referral_bonus` (1%), `source_user_id = winner_id`, `game_id = game_id`, `payout_id = payout_id`
5. WHEN the winner's `referred_by` is non-null BUT the referrer has `is_banned = 1`, THE Payout_Engine SHALL treat the referral bonus as unclaimed (criterion 4 applies) and log `[Referral] Banned referrer {id} — bonus unclaimed for game {game_id}`.

---

### Requirement 3: Winner Net Payout Invariant and Non-Negative Guarantees

**User Story:** As a player, I want to receive the remainder of the pot after platform deductions, with a guaranteed financial invariant that no money is created or lost.

#### Acceptance Criteria

1. WHEN `pot >= 0.50`, THE Payout_Engine SHALL compute:
   - `commission = GREATEST(ROUND(pot * 0.02, 2), 0.01)`
   - `referral_bonus = GREATEST(ROUND(pot * 0.01, 2), 0.01)`
   - `winner_net = pot - commission - referral_bonus`
2. `winner_net` SHALL be computed by subtraction: `winner_net = pot - commission - referral_bonus`. The invariant is enforced by this computation, NOT by re-summing values for an equality check.
3. THE Payout_Engine SHALL assert all of the following before executing any balance updates; IF `winner_net < 0` (can occur when minimums exceed pot), THE Payout_Engine SHALL set `commission = 0`, `referral_bonus = 0`, `winner_net = pot`, credit the full pot to the winner, and log `[Payout] CRITICAL: winner_net < 0 for game {id}, falling back to full-pot payout`. For all other assertion failures (`pot < 0`, `commission < 0`, `referral_bonus < 0`), THE Payout_Engine SHALL rollback and log a critical error:
   - `pot >= 0`
   - `commission >= 0`
   - `referral_bonus >= 0`
4. THE Payout_Engine SHALL execute ALL balance updates AND ALL transaction log inserts within a **single atomic InnoDB transaction** at isolation level `READ COMMITTED` (`SET TRANSACTION ISOLATION LEVEL READ COMMITTED` before `BEGIN`). This reduces phantom locks and deadlock probability compared to the default `REPEATABLE READ`. Balance updates MUST be performed BEFORE inserting transaction logs, and logs MUST reflect the exact committed values. IF any single operation fails, the entire transaction SHALL rollback.
5. THE Payout_Engine SHALL acquire `FOR UPDATE` locks in the following deterministic order to prevent deadlocks:
   1. `lottery_games` row (by game id)
   2. `users` rows — acquired via `SELECT * FROM users WHERE id IN (winner_id, referrer_id) ORDER BY id ASC FOR UPDATE` so locks are always taken in ascending id order regardless of which id is larger
   3. `system_balance` row (`id = 1`)
6. THE Payout_Engine SHALL retry the entire payout transaction up to **3 times** on transient failures: MySQL error `1213` (deadlock detected) or `1205` (lock wait timeout). Each retry SHALL use a fresh transaction. IF all 3 retries fail, THE Payout_Engine SHALL log `[Payout] FATAL: 3 retries exhausted for game {id}` and surface the error to the caller.
7. Only ONE payout worker may process a given game at a time. The `SELECT * FROM lottery_games WHERE id = ? FOR UPDATE` lock combined with the `payout_status` guard (criterion 2 of Requirement 4) ensures mutual exclusion even when `finishGameSafe()` is triggered concurrently by cron and a manual call.

---

### Requirement 4: Idempotent Payout with Database-Level Uniqueness

**User Story:** As the platform operator, I want the payout to execute exactly once per game at both application and database level, so that no player or referrer is ever paid twice.

#### Acceptance Criteria

1. THE `lottery_games` table SHALL have `payout_status ENUM('pending','paid') NOT NULL DEFAULT 'pending'` and `payout_id VARCHAR(36) DEFAULT NULL`.
2. WHEN the Payout_Engine begins a payout, it SHALL first acquire `SELECT * FROM lottery_games WHERE id = ? FOR UPDATE`, then check `payout_status`; IF `payout_status = 'paid'`, it SHALL abort and return the existing payout result (`winner_net`, `commission`, `referral_bonus`, `payout_id`) to the caller WITHOUT performing any operations.
3. WHEN the Payout_Engine successfully completes all balance updates and log inserts, it SHALL set `payout_status = 'paid'` and `payout_id = @payout_id` in the same atomic transaction. The `payout_id` UUID SHALL be generated at the **start** of the transaction (`SET @payout_id = UUID()`) and used in ALL log inserts and the final game update, so that no log row can exist without a `payout_id`.
4. THE `system_transactions` table SHALL have `UNIQUE KEY uq_game_payout_type (game_id, payout_id, type)` so that duplicate entries for the same payout event are rejected at the database level.
5. THE `user_transactions` table SHALL have `UNIQUE KEY uq_game_user_type (game_id, user_id, type, payout_id)` so that duplicate win/referral_bonus entries for the same game, user, type, and payout event are rejected at the database level.
6. ALL `user_transactions` rows created during a payout SHALL carry the `payout_id` value for end-to-end tracing.
7. IF a database-level unique constraint violation occurs during payout, THE Payout_Engine SHALL treat it as an already-paid game, rollback, and log a warning without propagating an error to the caller.

---

### Requirement 5: Referral Code Generation

**User Story:** As a new user, I want a unique referral code assigned to my account at registration, so that I can share it with others to earn referral bonuses.

#### Acceptance Criteria

1. WHEN a new user account is created, THE Registration_Handler SHALL generate a `ref_code` using `strtoupper(bin2hex(random_bytes(6)))` producing a 12-character uppercase hex string.
2. THE Registration_Handler SHALL attempt to INSERT the user with the generated `ref_code`; IF a `DUPLICATE KEY` error occurs on `ref_code`, it SHALL regenerate and retry up to **3** times.
3. IF all 3 attempts fail, THE Registration_Handler SHALL return HTTP 500 with a logged error. (Collision probability at 3 retries with 12-char hex codes is astronomically low — no timestamp fallback needed.)
4. THE System SHALL store `ref_code` as `VARCHAR(32) UNIQUE NOT NULL` with an index on the `users` table.
5. Bot users (`is_bot = 1`) SHALL also receive a `ref_code` at creation time using the same generation logic.

---

### Requirement 6: Referral Link Capture with TTL

**User Story:** As a potential new user arriving via a referral link, I want the platform to remember who referred me for 7 days, so that my referrer is credited when I register.

#### Acceptance Criteria

1. WHEN a user visits `https://anora.bet/?ref=CODE`, THE Frontend SHALL extract the `ref` query parameter and store it in `localStorage` as `{ code: CODE, expires: Date.now() + 7 * 24 * 60 * 60 * 1000 }` under the key `anora_ref`.
2. WHEN reading `anora_ref` from `localStorage`, THE Frontend SHALL check the `expires` timestamp; IF expired, it SHALL delete the entry and treat it as absent.
3. WHEN a stored, non-expired `anora_ref` exists AND the user submits the registration form, THE Frontend SHALL include `referral_code` in the registration API request body.
4. WHEN the Registration_Handler receives a `referral_code`, it SHALL look up the user whose `ref_code` matches (case-insensitive).
5. IF a matching referrer is found AND the referrer is an Eligible_Referrer AND the referrer's `registration_ip` does not match the new user's IP, THE Registration_Handler SHALL set `referred_by` to the referrer's `id`.
6. IF any eligibility check fails, THE Registration_Handler SHALL set `referred_by = NULL` and complete registration without error.
7. WHEN registration completes successfully, THE Frontend SHALL remove `anora_ref` from `localStorage`.

---

### Requirement 7: Referral Validation and Immutability

**User Story:** As the platform operator, I want referral relationships to be immutable and abuse-resistant.

#### Acceptance Criteria

1. `referred_by` SHALL be set only at account creation and SHALL NOT be updatable by any API endpoint after that.
2. THE System SHALL store `registration_ip VARCHAR(45)` on the `users` table and populate it at registration.
3. WHEN a new user's IP matches the referrer's `registration_ip`, THE Registration_Handler SHALL set `referred_by = NULL` and log `[Referral] Same-IP block: referrer_id={id} ip={ip}`.
4. THE Registration_Handler SHALL require the referrer to have `created_at <= NOW() - INTERVAL 24 HOUR` before setting `referred_by`; IF the referrer account is younger than 24 hours, `referred_by` SHALL be set to NULL.
5. THE Registration_Handler SHALL require the referrer to have at least one completed deposit before setting `referred_by`; IF no deposit exists, `referred_by` SHALL be set to NULL.
6. THE Registration_Handler SHALL require the referrer to have `is_banned = 0`; IF the referrer is banned, `referred_by` SHALL be set to NULL.
7. THE System SHALL add `INDEX idx_referred_by (referred_by)` on the `users` table.

---

### Requirement 8: Registration Rate Limiting

**User Story:** As the platform operator, I want to limit registration attempts per IP address, so that mass account creation for referral farming is prevented.

#### Acceptance Criteria

1. THE Registration_Handler SHALL enforce the rate limit using an INSERT-then-check pattern to prevent race conditions:
   - INSERT a row into `registration_attempts (ip, created_at)` immediately.
   - COUNT rows for the same IP in the last 1 hour (including the just-inserted row).
   - IF the count exceeds **3**, rollback the INSERT and reject with HTTP 429: "Too many registrations from this IP. Please try again later."
   - This INSERT-first approach prevents concurrent requests from all passing the count check simultaneously.
2. THE rate limit INSERT and check SHALL occur before any database writes for the new user account.
3. THE `registration_attempts` table SHALL have `INDEX idx_ip_created (ip, created_at)`.
4. Bot users (`is_bot = 1`) SHALL be exempt from the rate limit.

---

### Requirement 9: Database Schema Changes

**User Story:** As a developer, I want the database schema updated to support referral tracking, system balance accounting, idempotency, payout tracing, and full audit trails.

#### Acceptance Criteria

1. THE System SHALL add to the `users` table:
   - `ref_code VARCHAR(32) UNIQUE NOT NULL`
   - `referred_by INT NULL` (FK → `users.id` ON DELETE SET NULL)
   - `referral_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00`
   - `registration_ip VARCHAR(45) DEFAULT NULL`
   - `is_banned TINYINT(1) NOT NULL DEFAULT 0`
   - `referral_locked TINYINT(1) NOT NULL DEFAULT 0` — set to `1` at registration when all referral eligibility checks pass; fast pre-check at payout time
   - `referral_snapshot JSON DEFAULT NULL` — set at registration to `{"referrer_id": N, "is_verified": true, "had_deposit": true, "created_at": "...", "locked_at": "..."}` when `referral_locked = 1`; used for audit only, never for payout decisions if referrer row is deleted before payout
   - `INDEX idx_referred_by (referred_by)`
2. THE System SHALL create `system_balance`: `id INT NOT NULL DEFAULT 1 PRIMARY KEY`, `balance DECIMAL(15,2) NOT NULL DEFAULT 0.00`, seeded with `(1, 0.00)`. The PRIMARY KEY on `id` ensures the InnoDB clustered index is on the single row, making `SELECT ... FOR UPDATE` on `id = 1` a single-row lock with minimal contention.
3. THE System SHALL create `system_transactions`: `id INT AUTO_INCREMENT PRIMARY KEY`, `game_id INT NULL` (FK → `lottery_games.id` ON DELETE SET NULL), `payout_id VARCHAR(36) DEFAULT NULL`, `amount DECIMAL(12,2) NOT NULL`, `type VARCHAR(32) NOT NULL`, `source_user_id INT NULL` (FK → `users.id` ON DELETE SET NULL), `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `UNIQUE KEY uq_game_payout_type (game_id, payout_id, type)`, `INDEX idx_created_at (created_at)`.
4. THE System SHALL create `user_transactions`: `id INT AUTO_INCREMENT PRIMARY KEY`, `user_id INT NOT NULL` (FK → `users.id` ON DELETE CASCADE), `payout_id VARCHAR(36) DEFAULT NULL`, `type VARCHAR(32) NOT NULL`, `amount DECIMAL(12,2) NOT NULL`, `game_id INT NULL` (FK → `lottery_games.id` ON DELETE SET NULL), `note VARCHAR(255) DEFAULT NULL`, `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `UNIQUE KEY uq_game_user_type (game_id, user_id, type, payout_id)`, `INDEX idx_user_created (user_id, created_at)`.
5. THE System SHALL add to `lottery_games`: `payout_status ENUM('pending','paid') NOT NULL DEFAULT 'pending'`, `payout_id VARCHAR(36) DEFAULT NULL`, `commission DECIMAL(12,2) DEFAULT NULL`, `referral_bonus DECIMAL(12,2) DEFAULT NULL`, `winner_net DECIMAL(12,2) DEFAULT NULL`.
6. THE System SHALL create `registration_attempts`: `id INT AUTO_INCREMENT PRIMARY KEY`, `ip VARCHAR(45) NOT NULL`, `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `INDEX idx_ip_created (ip, created_at)`.
7. ALL schema changes SHALL be applied as `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` / `CREATE TABLE IF NOT EXISTS` migrations safe to run on the existing database without data loss.

---

### Requirement 10: User Audit Trail

**User Story:** As a player, I want a complete history of every balance change on my account, with each entry traceable to a specific payout event.

#### Acceptance Criteria

1. WHEN a user wins a lottery round, THE Payout_Engine SHALL insert into `user_transactions`: `type = 'win'`, `amount = winner_net`, `user_id = winner_id`, `game_id = game_id`, `payout_id = payout_id`.
2. WHEN a referrer earns a referral bonus, THE Payout_Engine SHALL insert into `user_transactions`: `type = 'referral_bonus'`, `amount = referral_bonus`, `user_id = referrer_id`, `game_id = game_id`, `payout_id = payout_id`.
3. WHEN a user places a lottery bet, THE bet handler SHALL insert into `user_transactions`: `type = 'bet'`, `amount = bet_amount`, `user_id = user_id`, `game_id = game_id`.
4. THE `user_transactions` table SHALL be append-only; no UPDATE or DELETE operations SHALL be performed on it by the application.
5. THE Account_Page SHALL display the user's `user_transactions` history (type, amount, date) in a paginated table.

---

### Requirement 11: Game Financial Snapshot

**User Story:** As the platform operator, I want each finished game to store its exact financial breakdown for independent verification.

#### Acceptance Criteria

1. WHEN the Payout_Engine completes a payout, it SHALL store on the `lottery_games` row: `commission`, `referral_bonus`, `winner_net`, `payout_status = 'paid'`, `payout_id = UUID()`.
2. THE stored values SHALL match exactly what was credited to each party in the same transaction.
3. THE admin Lottery Games page SHALL display `commission`, `referral_bonus`, `winner_net`, and `payout_id` columns for finished games.

---

### Requirement 12: User Referral Dashboard

**User Story:** As a registered user, I want to see my referral link, total referral earnings, and how many users I have referred.

#### Acceptance Criteria

1. THE Account_Page SHALL display the referral link `https://anora.bet/?ref={ref_code}`.
2. THE Account_Page SHALL display `referral_earnings` from the `/api/auth/me` response (which SHALL include `ref_code` and `referral_earnings`).
3. THE Account_Page SHALL display the count of users with `referred_by = current_user_id`.
4. THE Account_Page SHALL provide a one-click copy button with a "Copied!" confirmation shown for at least 2 seconds.

---

### Requirement 13: Admin System Balance Panel

**User Story:** As an admin, I want a dedicated System Balance page to monitor platform revenue and referral activity.

#### Acceptance Criteria

1. THE Admin_Panel SHALL include a "System Balance" navigation entry in the admin sidebar.
2. THE page SHALL display: current `system_balance.balance`, sum of `commission` type entries, sum of `referral_unclaimed` type entries.
3. THE page SHALL display a paginated table of `system_transactions` with columns: amount, type, source user email, payout_id, date.
4. ALL data SHALL be fetched from a protected admin API endpoint requiring a valid admin session.

---

### Requirement 15: Data Retention and Cleanup

**User Story:** As the platform operator, I want transient tables cleaned up automatically so that the database does not grow unboundedly.

#### Acceptance Criteria

1. THE `registration_attempts` table SHALL have a retention policy of **7 days**; rows older than 7 days provide no rate-limiting value and SHALL be deleted.
2. THE `system_transactions` table SHALL be retained **permanently** — it is a financial audit log and SHALL never be deleted.
3. THE `user_transactions` table SHALL be retained **permanently** — it is a player audit log and SHALL never be deleted.
4. THE System SHALL provide a cleanup script (or cron job) that executes: `DELETE FROM registration_attempts WHERE created_at < NOW() - INTERVAL 7 DAY;`
5. THE cleanup script SHALL run at least once per day (e.g. via cron: `0 3 * * * php /path/to/cleanup.php`).
6. THE cleanup script SHALL log the number of rows deleted: `[Cleanup] Deleted {N} registration_attempts older than 7 days`.

**User Story:** As the platform operator, I want basic safeguards against referral farming.

#### Acceptance Criteria

1. THE Registration_Handler SHALL reject registration if the email domain is in the disposable list: `mailinator.com`, `guerrillamail.com`, `tempmail.com`, `throwaway.email`, `yopmail.com`, `sharklasers.com`, `trashmail.com`, `maildrop.cc`, `dispostable.com`, `fakeinbox.com`.
2. THE referrer SHALL satisfy all Eligible_Referrer criteria (verified, not banned, age >= 24h, has deposit) before `referred_by` is set.
3. Same-IP registrations SHALL have `referred_by` set to NULL.
4. THE System SHALL log all referral eligibility failures with reason codes for audit purposes.
5. THE System SHALL enforce `referred_by` immutability — no UPDATE endpoint for this field exists.

---

### Requirement 16: Multi-Account Farming Prevention