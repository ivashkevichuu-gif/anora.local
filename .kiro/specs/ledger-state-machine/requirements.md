# Requirements Document

## Introduction

This document specifies two interconnected architectural changes to the anora.bet lottery platform:

1. **Ledger-Based Accounting**: Replace the mutable `users.balance` column as the source of truth with an append-only `ledger_entries` table. Every financial operation (deposits, bets, wins, fees, referrals, withdrawals, crypto flows) produces an immutable ledger entry. The user's authoritative balance is derived from the last ledger entry's `balance_after` column. The existing `users.balance` column is retained as a denormalized cache but is no longer the source of truth. A dedicated `user_balances` table serves as the row-level lock target for concurrency control, eliminating the ORDER BY id DESC LIMIT 1 FOR UPDATE hotspot on `ledger_entries`.

2. **Backend Game State Machine**: Move all game lifecycle logic from the frontend `useGameMachine.js` hook to a backend `GameEngine` service. The backend controls all state transitions through a formal state machine (`waiting` → `active` → `spinning` → `finished`). State transitions are driven exclusively by a background `game_worker.php` process, NOT by API endpoints. The frontend becomes a pure display layer that polls the backend for current state.

Both features are tightly coupled: the GameEngine uses the LedgerService for all financial operations (bet deduction, winner payout, fee distribution), and the LedgerService replaces all direct `users.balance` mutations across the entire platform.

## Glossary

- **System**: The anora.bet lottery platform backend (PHP 8.4 / MySQL InnoDB).
- **Ledger_Entry**: A single immutable row in the `ledger_entries` table representing one financial operation, containing `user_id`, `type`, `amount`, `direction` (credit/debit), `balance_after`, `reference_id`, `reference_type`, and `metadata`.
- **LedgerService**: The backend PHP service responsible for all ledger operations: adding entries, computing balances, and providing concurrency control via the `user_balances` lock table.
- **GameEngine**: The backend PHP service that controls the full game lifecycle state machine, replacing frontend-driven game logic.
- **Game_Round**: A row in the `game_rounds` table representing a single lottery round with a status field tracking its lifecycle phase.
- **Game_Bet**: A row in the `game_bets` table representing a single bet placed by a user in a specific game round.
- **Balance_After**: The `balance_after` column on a `ledger_entries` row, representing the user's authoritative balance immediately after that entry was applied.
- **Ledger_Type**: One of: `deposit`, `bet`, `win`, `system_fee`, `referral_bonus`, `withdrawal`, `withdrawal_refund`, `crypto_deposit`, `crypto_withdrawal`, `crypto_withdrawal_refund`.
- **Game_Status**: One of: `waiting`, `active`, `spinning`, `finished` — the four phases of a game round's lifecycle.
- **Room_Type**: One of: `1`, `10`, `100` — the three bet-amount tiers for lottery rooms.
- **Provably_Fair_System**: The existing SHA-256 based system using server seeds, client seeds, and deterministic winner selection via weighted cumulative distribution.
- **Bot_System**: The existing automated bot players (`is_bot = 1`) that participate in lottery games to maintain liquidity.
- **Referral_System**: The existing referral commission system where eligible referrers earn 1% of the pot when their referred user wins.
- **User_Balances**: The `user_balances` table (`user_id INT PRIMARY KEY, balance DECIMAL(20,8) NOT NULL DEFAULT 0.00`) used as the row-level lock target for concurrency control. Updated after every ledger entry. The ledger remains the source of truth; `user_balances` is the lock target.
- **System_Account**: A dedicated system user with `SYSTEM_USER_ID = 0` (or a dedicated row in `users`). System fees, unclaimed referral bonuses, and other platform revenue are recorded as ledger entries on the System_Account, replacing the `system_balance` singleton table.
- **Game_Worker**: The background process (`backend/game_worker.php`) that runs in a continuous loop, driving all game state transitions (waiting → active → spinning → finished). API endpoints do NOT trigger state transitions.

---

## Requirements

### Requirement 1: Ledger Entries Table Schema

**User Story:** As a developer, I want an append-only ledger table that records every financial operation with crypto-safe precision and idempotency guarantees, so that the platform has a complete, immutable audit trail and can derive balances from ledger state.

#### Acceptance Criteria

1. THE System SHALL create a `ledger_entries` table with columns: `id` (INT AUTO_INCREMENT PRIMARY KEY), `user_id` (INT NOT NULL, FK → users.id), `type` (VARCHAR(32) NOT NULL), `amount` (DECIMAL(20,8) NOT NULL), `direction` (ENUM('credit','debit') NOT NULL), `balance_after` (DECIMAL(20,8) NOT NULL), `reference_id` (VARCHAR(64) DEFAULT NULL), `reference_type` (VARCHAR(32) DEFAULT NULL), `metadata` (JSON DEFAULT NULL), `created_at` (DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP).
2. THE System SHALL create indexes on `ledger_entries`: `idx_user_id (user_id)`, `idx_user_created (user_id, created_at)`, `idx_reference (reference_type, reference_id)`, `idx_type (type)`.
3. THE System SHALL create a UNIQUE KEY `uniq_reference (reference_type, reference_id, user_id, type)` on `ledger_entries` to enforce idempotency: duplicate financial operations with the same reference_type, reference_id, user_id, and type combination SHALL be rejected at the database level.
4. THE System SHALL enforce append-only semantics on `ledger_entries`: application code SHALL use INSERT statements only and SHALL NOT execute UPDATE or DELETE statements against the `ledger_entries` table.
5. THE `ledger_entries.type` column SHALL accept the following values: `deposit`, `bet`, `win`, `system_fee`, `referral_bonus`, `withdrawal`, `withdrawal_refund`, `crypto_deposit`, `crypto_withdrawal`, `crypto_withdrawal_refund`.
6. THE `ledger_entries.amount` column SHALL store positive values only; the `direction` column SHALL indicate whether the entry is a credit (balance increase) or debit (balance decrease).
7. THE `ledger_entries.amount` and `balance_after` columns SHALL use DECIMAL(20,8) precision to support crypto-safe sub-cent operations.
8. THE `ledger_entries.metadata` JSON column SHALL include the following fields when available: `ip` (client IP address), `user_agent` (client user agent string), and `source` (one of: `game_engine`, `deposit`, `webhook`, `admin`, `bot_runner`, `game_worker`).

---

### Requirement 2: LedgerService — Core Operations

**User Story:** As a developer, I want a centralized LedgerService that handles all balance mutations through ledger entries with idempotency and scalable locking, so that no code path can bypass the ledger.

#### Acceptance Criteria

1. THE LedgerService SHALL provide an `addEntry(int $userId, string $type, float $amount, string $direction, ?string $referenceId, ?string $referenceType, ?array $metadata): array` method that inserts a new Ledger_Entry and returns the inserted row.
2. WHEN `addEntry` is called, THE LedgerService SHALL first check for an existing Ledger_Entry matching the same `(reference_type, reference_id, user_id, type)` combination; IF a matching entry exists, THE LedgerService SHALL return the existing entry without inserting a new row (idempotent duplicate handling).
3. WHEN `addEntry` is called and no duplicate exists, THE LedgerService SHALL acquire the user's balance by executing `SELECT balance FROM user_balances WHERE user_id = ? FOR UPDATE`, compute the new `balance_after` as `current_balance + amount` for credits or `current_balance - amount` for debits, insert the new Ledger_Entry with the computed `balance_after`, and update `user_balances SET balance = new_balance`.
4. WHEN `addEntry` is called with `direction = 'debit'` AND the computed `balance_after` would be negative, THE LedgerService SHALL throw an exception with message "Insufficient balance" without inserting any row.
5. THE LedgerService SHALL provide a `getBalanceForUpdate(int $userId): float` method that executes `SELECT balance FROM user_balances WHERE user_id = ? FOR UPDATE` and returns the locked balance value, or `0.00` if no row exists for the user.
6. WHEN `addEntry` is called for a user with no existing `user_balances` row, THE LedgerService SHALL treat the starting balance as `0.00` and insert a new `user_balances` row.
7. THE LedgerService SHALL update `users.balance` to match the new `balance_after` value after each successful `addEntry` call, maintaining the denormalized cache.
8. THE LedgerService SHALL auto-populate `metadata` fields `ip`, `user_agent`, and `source` from the request context when available; for background workers and cron jobs, `source` SHALL be set to `game_worker` or `bot_runner` as appropriate.
9. WHEN `addEntry` is called, THE LedgerService SHALL require non-null `reference_id` and `reference_type` parameters for all financial operations; every ledger entry SHALL have a traceable reference.

---

### Requirement 3: LedgerService — Concurrency Control

**User Story:** As a developer, I want the LedgerService to prevent race conditions and double-spends using a scalable locking strategy, so that concurrent operations on the same user's balance are serialized without hotspot contention.

#### Acceptance Criteria

1. WHEN `addEntry` is called, THE LedgerService SHALL use `SELECT balance FROM user_balances WHERE user_id = ? FOR UPDATE` to acquire a row-level lock on the `user_balances` table before computing the new balance, instead of locking the latest `ledger_entries` row.
2. WHILE a transaction holds a `FOR UPDATE` lock on a user's `user_balances` row, THE LedgerService SHALL block concurrent `addEntry` calls for the same user until the lock is released.
3. THE LedgerService SHALL execute all ledger operations within the caller's existing InnoDB transaction; the caller is responsible for `BEGIN`, `COMMIT`, and `ROLLBACK`.
4. IF a deadlock (MySQL error 1213) or lock wait timeout (MySQL error 1205) occurs during `addEntry`, THEN THE LedgerService SHALL propagate the exception to the caller for retry handling.
5. THE System SHALL enforce a strict lock ordering for ALL multi-user transactions: (1) `game_rounds` row (FOR UPDATE), (2) `user_balances` rows sorted by `user_id` ASC, (3) System_Account `user_balances` row (SYSTEM_USER_ID last). This lock ordering is a hard rule to prevent deadlocks.

---

### Requirement 4: Balance Migration from users.balance to Ledger

**User Story:** As a platform operator, I want existing user balances migrated into the ledger and user_balances table, so that all users have a valid ledger history and lock target after the migration.

#### Acceptance Criteria

1. THE System SHALL provide a migration script that reads each user's current `users.balance` and inserts a single Ledger_Entry with `type = 'deposit'`, `direction = 'credit'`, `amount = users.balance`, `balance_after = users.balance`, `reference_type = 'migration'`, and `reference_id = 'initial_migration'` for every user with `balance > 0`.
2. THE migration script SHALL skip users with `balance = 0` or `balance < 0`.
3. THE migration script SHALL be idempotent: WHEN a Ledger_Entry with `reference_type = 'migration'` AND `reference_id = 'initial_migration'` already exists for a user, THE script SHALL skip that user.
4. AFTER migration, THE System SHALL use `ledger_entries` as the source of truth for all balance reads and writes; `users.balance` SHALL be maintained as a denormalized cache only.
5. THE migration script SHALL populate the `user_balances` table with one row per user, setting `balance` equal to the user's current `users.balance` value, for all users (including those with balance = 0).
6. THE migration script SHALL create a `user_balances` row for the System_Account (SYSTEM_USER_ID) with the current `system_balance.balance` value.

---

### Requirement 5: Ledger Integration — Deposits

**User Story:** As a player, I want my fiat and crypto deposits recorded in the ledger, so that my balance is updated through the ledger system.

#### Acceptance Criteria

1. WHEN a fiat deposit is processed via `backend/api/account/deposit.php`, THE System SHALL call `LedgerService::addEntry` with `type = 'deposit'`, `direction = 'credit'`, and the deposit amount, within a single InnoDB transaction.
2. WHEN a crypto deposit is confirmed via the Webhook_Handler, THE System SHALL call `LedgerService::addEntry` with `type = 'crypto_deposit'`, `direction = 'credit'`, and the credited USD amount, within a single InnoDB transaction.
3. THE System SHALL stop executing direct `UPDATE users SET balance = balance + ?` statements for deposit operations; all balance changes SHALL go through LedgerService.

---

### Requirement 6: Ledger Integration — Withdrawals

**User Story:** As a player, I want my withdrawals and refunds recorded in the ledger, so that all outgoing funds are tracked immutably.

#### Acceptance Criteria

1. WHEN a fiat withdrawal is approved, THE System SHALL call `LedgerService::addEntry` with `type = 'withdrawal'`, `direction = 'debit'`, and the withdrawal amount, within a single InnoDB transaction.
2. WHEN a crypto withdrawal is requested via PayoutService, THE System SHALL call `LedgerService::addEntry` with `type = 'crypto_withdrawal'`, `direction = 'debit'`, and the withdrawal amount, within a single InnoDB transaction.
3. WHEN a crypto withdrawal fails and is refunded, THE System SHALL call `LedgerService::addEntry` with `type = 'crypto_withdrawal_refund'`, `direction = 'credit'`, and the refund amount, within a single InnoDB transaction.
4. WHEN a fiat withdrawal is refunded, THE System SHALL call `LedgerService::addEntry` with `type = 'withdrawal_refund'`, `direction = 'credit'`, and the refund amount, within a single InnoDB transaction.
5. THE System SHALL stop executing direct `UPDATE users SET balance = balance ± ?` statements for withdrawal operations; all balance changes SHALL go through LedgerService.

---

### Requirement 7: Ledger Integration — Game Payouts

**User Story:** As a developer, I want all game-related financial operations (bets, wins, fees, referral bonuses) routed through the ledger, so that the payout engine produces a complete financial trail.

#### Acceptance Criteria

1. WHEN a player places a bet, THE System SHALL call `LedgerService::addEntry` with `type = 'bet'`, `direction = 'debit'`, `amount = room_bet_amount`, and `reference_id = game_round_id`.
2. WHEN a winner is paid out, THE System SHALL call `LedgerService::addEntry` with `type = 'win'`, `direction = 'credit'`, `amount = winner_net`, and `reference_id = game_round_id`.
3. WHEN a system fee is collected, THE System SHALL call `LedgerService::addEntry` on the System_Account (SYSTEM_USER_ID) with `type = 'system_fee'`, `direction = 'credit'`, `amount = commission`, and `reference_id = game_round_id`.
4. WHEN a referral bonus is paid to an eligible referrer, THE System SHALL call `LedgerService::addEntry` with `type = 'referral_bonus'`, `direction = 'credit'`, `amount = referral_bonus`, and `reference_id = game_round_id`.
5. THE existing `computePayoutAmounts()` function SHALL continue to compute winner_net (97%), commission (2%), and referral_bonus (1%) using the same logic and thresholds.

---

### Requirement 8: Game Rounds Table Schema

**User Story:** As a developer, I want a new `game_rounds` table with a proper status ENUM and double-payout protection, so that the backend can track game lifecycle phases safely.

#### Acceptance Criteria

1. THE System SHALL create a `game_rounds` table with columns: `id` (INT AUTO_INCREMENT PRIMARY KEY), `room` (TINYINT NOT NULL), `status` (ENUM('waiting','active','spinning','finished') NOT NULL DEFAULT 'waiting'), `server_seed` (VARCHAR(64) DEFAULT NULL), `server_seed_hash` (VARCHAR(64) DEFAULT NULL), `total_pot` (DECIMAL(15,2) NOT NULL DEFAULT 0.00), `winner_id` (INT DEFAULT NULL, FK → users.id), `started_at` (DATETIME DEFAULT NULL), `spinning_at` (DATETIME DEFAULT NULL), `finished_at` (DATETIME DEFAULT NULL), `payout_status` (ENUM('pending','paid') NOT NULL DEFAULT 'pending'), `payout_id` (VARCHAR(36) DEFAULT NULL), `commission` (DECIMAL(12,2) DEFAULT NULL), `referral_bonus` (DECIMAL(12,2) DEFAULT NULL), `winner_net` (DECIMAL(12,2) DEFAULT NULL), `final_bets_snapshot` (JSON DEFAULT NULL), `final_combined_hash` (VARCHAR(64) DEFAULT NULL), `final_rand_unit` (DECIMAL(20,12) DEFAULT NULL), `final_target` (DECIMAL(20,12) DEFAULT NULL), `final_total_weight` (DECIMAL(15,2) DEFAULT NULL), `created_at` (DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP).
2. THE System SHALL create indexes on `game_rounds`: `idx_room_status (room, status)`, `idx_status (status)`.
3. THE `game_rounds.room` column SHALL accept values `1`, `10`, or `100` corresponding to the three Room_Type tiers.
4. THE System SHALL create a UNIQUE KEY `uniq_payout (payout_id)` on `game_rounds` to prevent duplicate payouts from concurrent workers.

---

### Requirement 9: Game Bets Table Schema

**User Story:** As a developer, I want a `game_bets` table linked to game_rounds, so that bets are associated with the new state machine rounds.

#### Acceptance Criteria

1. THE System SHALL create a `game_bets` table with columns: `id` (INT AUTO_INCREMENT PRIMARY KEY), `round_id` (INT NOT NULL, FK → game_rounds.id), `user_id` (INT NOT NULL, FK → users.id), `amount` (DECIMAL(10,2) NOT NULL), `client_seed` (VARCHAR(64) DEFAULT NULL), `ledger_entry_id` (INT DEFAULT NULL, FK → ledger_entries.id), `created_at` (DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP).
2. THE System SHALL create indexes on `game_bets`: `idx_round_id (round_id)`, `idx_user_round (user_id, round_id)`.
3. THE `game_bets.ledger_entry_id` column SHALL reference the Ledger_Entry that recorded the balance deduction for the bet.

---

### Requirement 10: GameEngine — State Machine Transitions

**User Story:** As a developer, I want the backend GameEngine to control all game state transitions driven by a background worker, so that the frontend and API endpoints cannot manipulate game phases.

#### Acceptance Criteria

1. THE GameEngine SHALL enforce the following state transition sequence: `waiting` → `active` → `spinning` → `finished`, and then create a new round in `waiting` status.
2. WHEN a game round is in `waiting` status AND a second distinct player places a bet (reaching ≥ 2 unique players), THE GameEngine SHALL transition the round to `active` status and set `started_at` to the current timestamp.
3. WHEN a game round is in `active` status AND the countdown timer (30 seconds from `started_at`) expires, THE Game_Worker SHALL transition the round to `spinning` status and set `spinning_at` to the current timestamp.
4. WHEN a game round is in `spinning` status, THE Game_Worker SHALL execute winner selection using the existing Provably_Fair_System, distribute payouts via LedgerService, transition the round to `finished` status, and set `finished_at` to the current timestamp.
5. WHEN a game round transitions to `finished` status, THE Game_Worker SHALL create a new game round in `waiting` status for the same room.
6. THE GameEngine SHALL reject state transitions that violate the defined sequence (e.g., `waiting` → `spinning` is invalid).
7. THE GameEngine SHALL use `SELECT ... FOR UPDATE` on the `game_rounds` row before executing any state transition to prevent concurrent transitions.
8. THE `active` → `spinning` and `spinning` → `finished` transitions SHALL be driven exclusively by the Game_Worker background process, NOT by API endpoints.

---

### Requirement 11: GameEngine — Place Bet

**User Story:** As a player, I want to place bets through the backend GameEngine, so that bet validation and balance deduction are server-authoritative.

#### Acceptance Criteria

1. WHEN a player calls `GameEngine::placeBet(userId, amount, roomType)`, THE GameEngine SHALL validate that the room is active (status `waiting` or `active`), the bet amount matches the Room_Type, and the user has sufficient balance via `LedgerService::getBalanceForUpdate`.
2. WHEN validation passes, THE GameEngine SHALL within a single InnoDB transaction: (a) call `LedgerService::addEntry` with `type = 'bet'`, `direction = 'debit'`, (b) insert a `game_bets` row with the `ledger_entry_id`, (c) update `game_rounds.total_pot`.
3. IF the user's balance is insufficient, THEN THE GameEngine SHALL reject the bet with message "Insufficient balance" without modifying any data.
4. IF the game round status is `spinning` or `finished`, THEN THE GameEngine SHALL reject the bet with message "Betting is closed".
5. THE GameEngine SHALL enforce a rate limit of 5 bets per user per second, consistent with the existing `LOTTERY_MAX_BETS_PER_SEC` constant.
6. WHEN the bet causes the distinct player count to reach ≥ 2 AND the round is in `waiting` status, THE GameEngine SHALL transition the round to `active` within the same transaction.

---

### Requirement 12: GameEngine — Winner Selection and Payout Distribution

**User Story:** As a developer, I want the GameEngine to select winners and distribute payouts atomically via the ledger with double-payout protection, so that the payout process is tamper-proof and fully auditable.

#### Acceptance Criteria

1. WHEN the GameEngine executes winner selection, THE GameEngine SHALL use the existing `pickWeightedWinner` function with the same SHA-256 hash, `hashToFloat`, `computeTarget`, and `lowerBound` algorithms to preserve provably fair guarantees.
2. WHEN distributing payouts, THE GameEngine SHALL within a single InnoDB transaction: (a) credit the winner via `LedgerService::addEntry` with `type = 'win'`, `amount = winner_net` (97% of pot for pots ≥ $0.50), (b) record the system fee via `LedgerService::addEntry` on the System_Account with `type = 'system_fee'`, `amount = commission` (2% of pot), (c) credit the eligible referrer via `LedgerService::addEntry` with `type = 'referral_bonus'`, `amount = referral_bonus` (1% of pot), or route the unclaimed bonus to the System_Account.
3. THE GameEngine SHALL store an immutable `final_bets_snapshot` (JSON), `final_combined_hash`, `final_rand_unit`, `final_target`, and `final_total_weight` on the `game_rounds` row at finish time.
4. THE GameEngine SHALL enforce strict lock ordering for payout transactions: (1) `game_rounds` row (FOR UPDATE), (2) `user_balances` rows sorted by `user_id` ASC, (3) System_Account `user_balances` row (SYSTEM_USER_ID last), to prevent deadlocks.
5. THE GameEngine SHALL implement a retry mechanism: up to 3 attempts on MySQL deadlock (error 1213) or lock wait timeout (error 1205), with exponential backoff (50ms, 100ms, 150ms).
6. THE GameEngine SHALL set `payout_status = 'paid'` and generate a UUID `payout_id` to ensure idempotent payout execution.
7. BEFORE executing a payout, THE GameEngine SHALL execute `SELECT payout_status FROM game_rounds WHERE id = ? FOR UPDATE`; IF `payout_status = 'paid'`, THE GameEngine SHALL exit immediately without processing the payout (double-payout protection).
8. THE UNIQUE KEY `uniq_payout (payout_id)` on `game_rounds` SHALL serve as a database-level guard against duplicate payout insertions from concurrent workers.

---

### Requirement 13: Game Worker — Background State Machine Executor

**User Story:** As a platform operator, I want a background worker process that drives all game state transitions, so that game lifecycle is decoupled from API request handling.

#### Acceptance Criteria

1. THE System SHALL provide a `backend/game_worker.php` script that runs in a continuous loop with a 1-second sleep interval between iterations.
2. EACH iteration of the Game_Worker loop SHALL: (a) call `processWaitingRounds()` to check if any waiting rounds have ≥ 2 players and transition them to `active`, (b) call `processActiveRounds()` to check if any active rounds have expired countdowns and transition them to `spinning`, (c) call `processSpinningRounds()` to select winners, execute payouts, transition to `finished`, and create new rounds.
3. THE Game_Worker SHALL process all three Room_Type tiers (1, 10, 100) in each iteration.
4. THE Game_Worker SHALL use `SELECT ... FOR UPDATE` on each `game_rounds` row before executing transitions to prevent concurrent worker conflicts.
5. THE polling endpoint (`GET /backend/api/game/status.php`) SHALL NOT trigger any state transitions; the endpoint SHALL be read-only for game state (except returning current state data).

---

### Requirement 14: GameEngine — Round Lifecycle Polling Endpoint

**User Story:** As a frontend developer, I want a read-only polling endpoint that returns the current game state, so that the React SPA can display the game without controlling its logic.

#### Acceptance Criteria

1. THE System SHALL provide a `GET /backend/api/game/status.php` endpoint that accepts a `room` query parameter and returns the current game round state including: `round_id`, `status`, `total_pot`, `countdown` (seconds remaining if `active`), `winner` (if `finished`), `server_seed_hash`, `server_seed` (only if `finished`), `room`, `bets` (aggregated per-user list with display names and chances), `unique_players`, `total_bets`, and `my_stats` (current user's bet count, total bet, and chance).
2. WHEN the round status is `active`, THE endpoint SHALL compute and return the `countdown` as `max(0, 30 - elapsed_seconds)`.
3. THE endpoint SHALL be strictly read-only: the endpoint SHALL NOT trigger any state transitions (waiting → active, active → spinning, or spinning → finished). All transitions are handled by the Game_Worker.
4. THE endpoint SHALL return the previous finished round data for the same room, including winner information and bet list.
5. THE endpoint SHALL return the current user's real-time balance (from `users.balance` cache column) for sidebar display.

---

### Requirement 15: GameEngine — Bet Placement Endpoint

**User Story:** As a frontend developer, I want a bet placement endpoint that validates and processes bets server-side, so that the frontend only needs to send the bet request.

#### Acceptance Criteria

1. THE System SHALL provide a `POST /backend/api/game/bet.php` endpoint that accepts `{ "room": 1, "client_seed": "..." }` and requires a valid user session.
2. WHEN the bet is placed successfully, THE endpoint SHALL return `{ "ok": true, "state": {...}, "balance": ... }` with the updated game state and user balance.
3. IF the bet fails validation, THEN THE endpoint SHALL return HTTP 400 with `{ "error": "..." }` containing the specific error message.
4. THE endpoint SHALL delegate all logic to `GameEngine::placeBet` and return the result of `GameEngine::getGameState`.

---

### Requirement 16: GameEngine — Provably Fair Verification

**User Story:** As a player, I want to verify game results using the same provably fair system, so that trust in the platform is maintained after the migration.

#### Acceptance Criteria

1. THE System SHALL provide a `GET /backend/api/game/verify.php` endpoint that accepts a `game_id` parameter and returns the full verification data: server seed, client seeds, combined hash, random unit, target, cumulative weights, winner index, and the hash format constant.
2. THE verification endpoint SHALL use the immutable `final_bets_snapshot` from the `game_rounds` row when available, falling back to live bet queries for legacy games in the `lottery_games` table.
3. THE `hashToFloat`, `computeTarget`, `buildCombinedHashFromSeeds`, and `lowerBound` functions SHALL remain unchanged to preserve backward compatibility with existing verified games.

---

### Requirement 17: Bot System Integration

**User Story:** As a platform operator, I want the bot system to work with the new GameEngine and ledger with proper isolation and safety limits, so that automated liquidity continues without risk of system drain.

#### Acceptance Criteria

1. THE `bot_runner.php` script SHALL call `GameEngine::placeBet` instead of the legacy `placeBet` function for all bot bet operations.
2. THE Bot_System SHALL have ledger entries for all bot balance operations: bets (debit) and wins (credit).
3. WHEN a bot's balance falls below the configured minimum (`BOT_MIN_BALANCE`), THE `bot_runner.php` SHALL top up the bot's balance via `LedgerService::addEntry` with `type = 'deposit'`, `direction = 'credit'`, and `reference_type = 'bot_topup'`.
4. THE Bot_System SHALL continue to use the same bot selection, multi-bet, and activity spike logic from the existing `bot_runner.php`.
5. THE System SHALL check `is_bot = 1` flag in all relevant queries involving bot users to ensure bot isolation from real user metrics and reporting.
6. THE System SHALL enforce a maximum balance cap for bot users (configurable, default $50,000); WHEN a bot's balance exceeds the cap, THE `bot_runner.php` SHALL skip top-up operations for that bot.
7. THE System SHALL track bot wins separately by including `is_bot = true` in the ledger entry `metadata` for all bot-related entries.
8. THE System SHALL hard-block all withdrawal operations for bot users: WHEN a withdrawal is requested for a user with `is_bot = 1`, THE System SHALL reject the request.

---

### Requirement 18: Referral System Integration

**User Story:** As a platform operator, I want the referral commission system to work through the ledger, so that referral bonuses are tracked immutably.

#### Acceptance Criteria

1. WHEN an eligible referrer receives a bonus, THE GameEngine SHALL call `LedgerService::addEntry` with `type = 'referral_bonus'`, `direction = 'credit'`, `reference_id = game_round_id`, and update `users.referral_earnings`.
2. THE existing referrer eligibility checks SHALL be preserved: `is_verified = 1`, `is_banned = 0`, account age ≥ 24 hours, at least one completed deposit.
3. WHEN no eligible referrer exists, THE GameEngine SHALL route the referral bonus amount to the System_Account via `LedgerService::addEntry` with `type = 'referral_bonus'`, `direction = 'credit'`, and `reference_type = 'referral_unclaimed'`.

---

### Requirement 19: Crypto Payments Ledger Integration

**User Story:** As a developer, I want the existing crypto payment flows (deposits, withdrawals, refunds) to use the ledger, so that crypto operations are part of the unified financial trail.

#### Acceptance Criteria

1. WHEN the Webhook_Handler confirms a crypto deposit, THE Webhook_Handler SHALL call `LedgerService::addEntry` with `type = 'crypto_deposit'` and `direction = 'credit'` instead of directly updating `users.balance`.
2. WHEN the PayoutService creates a crypto withdrawal, THE PayoutService SHALL call `LedgerService::addEntry` with `type = 'crypto_withdrawal'` and `direction = 'debit'` instead of directly updating `users.balance`.
3. WHEN the PayoutService or Webhook_Handler refunds a failed crypto withdrawal, THE refund handler SHALL call `LedgerService::addEntry` with `type = 'crypto_withdrawal_refund'` and `direction = 'credit'` instead of directly updating `users.balance`.
4. THE existing crypto payment validation logic (rate limits, daily caps, minimum amounts, HMAC signature verification) SHALL remain unchanged.

---

### Requirement 20: Frontend Migration to Polling-Only

**User Story:** As a frontend developer, I want the React SPA to display game state from backend polling without local state machine logic, so that the frontend is a pure display layer.

#### Acceptance Criteria

1. THE frontend `useGameMachine.js` hook SHALL be replaced with a simplified hook that maps backend game status directly to UI phases: `waiting` → BETTING, `active` → COUNTDOWN, `spinning` → DRAWING, `finished` → RESULT.
2. THE frontend SHALL poll the `GET /backend/api/game/status.php` endpoint at 1-second intervals for real-time game state updates.
3. THE frontend SHALL display the game state (pot, players, countdown, winner) based solely on the backend response without local state transitions.
4. THE frontend `PlaceBetButton` component SHALL call `POST /backend/api/game/bet.php` and disable itself when the backend reports status `spinning` or `finished`.
5. THE frontend SHALL maintain backward compatibility during migration: existing UI components (`LotteryPanel`, `BetsTable`, `Participants`, `PotDisplay`, `CountdownTimer`, `WinnerAnimation`, `PreviousGame`) SHALL continue to function with the new data source.

---

### Requirement 21: Admin Panel Compatibility

**User Story:** As an admin, I want the admin panel to continue working with the new ledger and game system, so that platform management is uninterrupted.

#### Acceptance Criteria

1. THE admin users list endpoint SHALL return user balances from the `users.balance` cache column for display performance.
2. THE admin transactions endpoint SHALL return data from both `user_transactions` (legacy) and `ledger_entries` (new) tables, or from `ledger_entries` only if legacy data has been migrated.
3. THE admin lottery games endpoint SHALL return data from `game_rounds` for new games and `lottery_games` for legacy games.
4. THE admin system balance endpoint SHALL read the System_Account balance from `user_balances` (for SYSTEM_USER_ID) or fall back to the `system_balance` singleton table during migration.
5. THE admin action endpoint (ban, unban, manual balance adjustments) SHALL use `LedgerService::addEntry` for any balance modifications.

---

### Requirement 22: Transaction Atomicity

**User Story:** As a developer, I want all financial operations to be atomic InnoDB transactions, so that partial failures cannot leave the system in an inconsistent state.

#### Acceptance Criteria

1. WHEN a bet is placed, THE System SHALL execute the ledger entry insertion, user_balances update, game_bets insertion, and game_rounds pot update within a single InnoDB transaction.
2. WHEN a payout is distributed, THE System SHALL execute the winner credit, system fee (to System_Account), referral bonus (or unclaimed routing to System_Account), user_balances updates, and game_rounds status update within a single InnoDB transaction.
3. WHEN a deposit is processed, THE System SHALL execute the ledger entry insertion, `user_balances` update, and `users.balance` cache update within a single InnoDB transaction.
4. WHEN a withdrawal is processed, THE System SHALL execute the ledger entry insertion, `user_balances` update, payout record creation, and `users.balance` cache update within a single InnoDB transaction.
5. IF any step within a transaction fails, THEN THE System SHALL roll back the entire transaction and propagate the error to the caller.
6. THE System SHALL use READ COMMITTED isolation level for all transactions, consistent with the existing payout engine configuration.

---

### Requirement 23: Ledger Balance Consistency

**User Story:** As a platform operator, I want the ledger balance to always be consistent and reconstructable, so that audits can verify the complete financial history.

#### Acceptance Criteria

1. FOR ALL users, the `balance_after` value of the most recent Ledger_Entry SHALL equal the sum of all credit amounts minus the sum of all debit amounts for that user.
2. FOR ALL consecutive Ledger_Entry pairs (ordered by `id` ASC) for the same user, the later entry's `balance_after` SHALL equal the earlier entry's `balance_after` plus the later entry's `amount` (if credit) or minus the later entry's `amount` (if debit).
3. FOR ALL users, the `users.balance` cache column SHALL equal the `balance_after` of the user's most recent Ledger_Entry.
4. FOR ALL Ledger_Entry rows, `balance_after` SHALL be greater than or equal to `0.00`.
5. FOR ALL users, the `user_balances.balance` value SHALL equal the `balance_after` of the user's most recent Ledger_Entry.

---

### Requirement 24: Legacy Data Compatibility

**User Story:** As a developer, I want the new system to coexist with legacy tables during migration, so that the transition is gradual and reversible.

#### Acceptance Criteria

1. THE System SHALL retain the `lottery_games` and `lottery_bets` tables for historical data and verification of past games.
2. THE provably fair verification endpoint SHALL support both `game_rounds` (new) and `lottery_games` (legacy) tables, selecting the appropriate table based on the `game_id` range or existence.
3. THE `user_transactions` table SHALL continue to receive inserts for backward compatibility with existing admin panel queries until the admin panel is fully migrated to read from `ledger_entries`.
4. THE System SHALL NOT delete or alter existing data in `lottery_games`, `lottery_bets`, or `user_transactions` tables during the migration.

---

### Requirement 25: Ledger Entry Round-Trip Consistency

**User Story:** As a developer, I want to verify that the ledger maintains round-trip consistency, so that reconstructing balance from entries always matches the stored balance_after.

#### Acceptance Criteria

1. FOR ALL valid sequences of ledger operations (deposits, bets, wins, withdrawals, refunds) applied to a user starting from balance 0.00, replaying the operations in order SHALL produce the same final `balance_after` as the last Ledger_Entry's `balance_after` value.
2. FOR ALL users, computing the balance by summing all credit amounts and subtracting all debit amounts from `ledger_entries` SHALL produce a value equal to the most recent `balance_after` for that user.
3. FOR ALL game rounds that reach `finished` status, the sum of all `bet` type debit entries for that round SHALL equal the `game_rounds.total_pot` value.

---

### Requirement 26: System Account — Ledger-Based Platform Accounting

**User Story:** As a platform operator, I want all system revenue (fees, unclaimed referral bonuses) tracked as ledger entries on a dedicated system account, so that platform finances are auditable through the same ledger as user finances.

#### Acceptance Criteria

1. THE System SHALL define a SYSTEM_USER_ID constant (value `0` or a dedicated system user row in the `users` table) representing the platform's system account.
2. THE System_Account SHALL have its own `user_balances` row and ledger entries, functioning identically to any user account.
3. WHEN a system fee (commission) is collected from a game payout, THE GameEngine SHALL call `LedgerService::addEntry` on the System_Account with `type = 'system_fee'`, `direction = 'credit'`, `amount = commission`, and `reference_id = game_round_id`.
4. WHEN a referral bonus is unclaimed (no eligible referrer), THE GameEngine SHALL call `LedgerService::addEntry` on the System_Account with `type = 'referral_bonus'`, `direction = 'credit'`, `amount = referral_bonus`, `reference_type = 'referral_unclaimed'`, and `reference_id = game_round_id`.
5. THE System_Account ledger entries SHALL replace or supplement the existing `system_balance` singleton table and `system_transactions` table for tracking platform revenue.
6. THE admin system balance endpoint SHALL read the System_Account balance from `user_balances` for SYSTEM_USER_ID.

---

### Requirement 27: Anti-Fraud Monitoring Hooks

**User Story:** As a platform operator, I want anti-fraud monitoring hooks that detect suspicious activity, so that the platform can flag and rate-limit potentially fraudulent behavior.

#### Acceptance Criteria

1. THE System SHALL enforce a configurable maximum bets-per-minute rate limit per user (default: 60 bets per minute); WHEN a user exceeds this limit, THE System SHALL reject the bet with message "Rate limit exceeded".
2. THE System SHALL implement a suspicious win streak detection hook: WHEN a user wins more than a configurable threshold N times within M hours (configurable, default N=10, M=24), THE System SHALL log a warning and flag the user's account in metadata for admin review.
3. THE System SHALL log the `ip` and `user_agent` for every bet placement and financial operation in the ledger entry `metadata`, enabling multi-account detection correlation by administrators.
4. THE anti-fraud rate limit (bets per minute) SHALL be a hard block that rejects the bet; the win streak detection and multi-account correlation SHALL be monitoring/flagging hooks that log warnings without blocking operations.

---

### Requirement 28: User Balances Lock Table Schema

**User Story:** As a developer, I want a dedicated `user_balances` table that serves as the row-level lock target for balance operations, so that concurrency control scales without contending on the ledger_entries table.

#### Acceptance Criteria

1. THE System SHALL create a `user_balances` table with columns: `user_id` (INT PRIMARY KEY), `balance` (DECIMAL(20,8) NOT NULL DEFAULT 0.00).
2. THE `user_balances.balance` column SHALL use DECIMAL(20,8) precision, matching the `ledger_entries.amount` and `balance_after` precision.
3. WHEN `LedgerService::addEntry` is called, THE LedgerService SHALL lock the `user_balances` row via `SELECT balance FROM user_balances WHERE user_id = ? FOR UPDATE` instead of scanning `ledger_entries` with `ORDER BY id DESC LIMIT 1 FOR UPDATE`.
4. AFTER inserting a new Ledger_Entry, THE LedgerService SHALL execute `UPDATE user_balances SET balance = ? WHERE user_id = ?` with the new `balance_after` value.
5. THE `user_balances` table SHALL be the lock target only; the `ledger_entries` table remains the source of truth for balance reconstruction and audit.
6. THE System SHALL also update `users.balance` cache column after each ledger entry for backward compatibility with existing queries.
