# Implementation Plan: Ledger State Machine

## Overview

Bottom-up architectural refactor: append-only ledger accounting + backend game state machine. Each phase builds on the previous, starting with database schema, then core services, then integration of all existing code paths, and finally frontend migration.

## Tasks

- [x] 1. Database schema — new tables and indexes
  - [x] 1.1 Create `user_balances` table
    - Add to `database.sql`: `user_balances (user_id INT PRIMARY KEY, balance DECIMAL(20,8) NOT NULL DEFAULT 0.00)`
    - _Requirements: 28.1, 28.2_
  - [x] 1.2 Create `ledger_entries` table
    - Add to `database.sql`: full DDL with columns `id`, `user_id`, `type`, `amount DECIMAL(20,8)`, `direction ENUM('credit','debit')`, `balance_after DECIMAL(20,8)`, `reference_id`, `reference_type`, `metadata JSON`, `created_at`
    - Add indexes: `idx_user_id`, `idx_user_created`, `idx_reference`, `idx_type`
    - Add UNIQUE KEY `uniq_reference (reference_type, reference_id, user_id, type)`
    - _Requirements: 1.1, 1.2, 1.3, 1.5, 1.6, 1.7_
  - [x] 1.3 Create `game_rounds` table
    - Add to `database.sql`: full DDL with `id`, `room TINYINT`, `status ENUM('waiting','active','spinning','finished')`, `server_seed`, `server_seed_hash`, `total_pot`, `winner_id`, `started_at`, `spinning_at`, `finished_at`, `payout_status ENUM('pending','paid')`, `payout_id`, `commission`, `referral_bonus`, `winner_net`, snapshot columns, `created_at`
    - Add indexes: `idx_room_status`, `idx_status`, UNIQUE KEY `uniq_payout (payout_id)`
    - _Requirements: 8.1, 8.2, 8.3, 8.4_
  - [x] 1.4 Create `game_bets` table
    - Add to `database.sql`: `game_bets (id, round_id FK→game_rounds, user_id FK→users, amount DECIMAL(10,2), client_seed VARCHAR(64), ledger_entry_id FK→ledger_entries, created_at)`
    - Add indexes: `idx_round_id`, `idx_user_round`
    - _Requirements: 9.1, 9.2, 9.3_

- [x] 2. Checkpoint — Verify schema
  - Run the new DDL statements against a test database, ensure all tables and indexes are created without errors. Ask the user if questions arise.

- [x] 3. Implement LedgerService
  - [x] 3.1 Create `backend/includes/ledger_service.php` — LedgerService class
    - Constructor accepts `PDO $pdo`
    - Define `SYSTEM_USER_ID` constant (0 or dedicated row)
    - Implement `addEntry(int $userId, string $type, float $amount, string $direction, ?string $referenceId, ?string $referenceType, ?array $metadata): array`
      - Check for existing entry matching `(reference_type, reference_id, user_id, type)` — return existing if found (idempotency)
      - `SELECT balance FROM user_balances WHERE user_id = ? FOR UPDATE` (insert row with 0.00 if missing)
      - Compute `balance_after`: credit adds, debit subtracts
      - Throw "Insufficient balance" if debit would go negative
      - INSERT into `ledger_entries`
      - UPDATE `user_balances SET balance = ?`
      - UPDATE `users SET balance = ?` (denormalized cache)
      - Return inserted row
    - Implement `getBalanceForUpdate(int $userId): float`
    - Auto-populate metadata fields (`ip`, `user_agent`, `source`) from request context
    - Require non-null `reference_id` and `reference_type` for all entries
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 3.1, 3.2, 3.3, 3.4, 28.3, 28.4, 28.5, 28.6_
  - [ ]* 3.2 Write property test for LedgerService balance consistency
    - **Property 1: Round-trip consistency — for any sequence of credits and debits, replaying operations produces the same final balance_after**
    - **Validates: Requirements 23.1, 23.2, 25.1, 25.2**
  - [ ]* 3.3 Write property test for LedgerService idempotency
    - **Property 2: Idempotent duplicate handling — calling addEntry twice with the same (reference_type, reference_id, user_id, type) returns the same row without double-inserting**
    - **Validates: Requirements 1.3, 2.2**
  - [ ]* 3.4 Write property test for LedgerService insufficient balance
    - **Property 3: Non-negative balance invariant — debit entries that would produce a negative balance_after are always rejected**
    - **Validates: Requirements 2.4, 23.4**

- [x] 4. Balance migration script
  - [x] 4.1 Create `backend/migrations/migrate_balances.php`
    - Read each user's `users.balance`
    - For users with `balance > 0`: insert ledger entry with `type='deposit'`, `direction='credit'`, `reference_type='migration'`, `reference_id='initial_migration'`
    - Skip users with `balance <= 0`
    - Idempotent: skip if migration entry already exists for user
    - Populate `user_balances` table for ALL users (including balance=0)
    - Create `user_balances` row for System_Account with current `system_balance.balance` value
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6_
  - [ ]* 4.2 Write unit test for migration idempotency
    - Run migration twice, verify no duplicate entries and balances unchanged
    - _Requirements: 4.3_

- [x] 5. Checkpoint — Verify LedgerService and migration
  - Ensure all tests pass, ask the user if questions arise.

- [x] 6. Refactor deposits to use LedgerService
  - [x] 6.1 Modify `backend/api/account/deposit.php`
    - Replace `UPDATE users SET balance = balance + ?` with `LedgerService::addEntry(type='deposit', direction='credit')`
    - Keep existing `transactions` table insert for backward compatibility
    - Use `reference_type='fiat_deposit'`, `reference_id=transaction_id`
    - _Requirements: 5.1, 5.3, 22.3, 24.3_
  - [x] 6.2 Modify `backend/includes/webhook_handler.php` — deposit path
    - In `handleDeposit()`: replace `UPDATE users SET balance = balance + ?` with `LedgerService::addEntry(type='crypto_deposit', direction='credit')`
    - Use `reference_type='crypto_invoice'`, `reference_id=nowpayments_invoice_id`
    - Keep existing `user_transactions` insert for backward compatibility
    - _Requirements: 5.2, 5.3, 19.1, 19.4, 22.3_

- [x] 7. Refactor withdrawals to use LedgerService
  - [x] 7.1 Modify `backend/api/account/withdraw.php`
    - Replace `UPDATE users SET balance = balance - ?` with `LedgerService::addEntry(type='withdrawal', direction='debit')`
    - Use `reference_type='fiat_withdrawal'`, `reference_id=transaction_id`
    - Keep existing `transactions` and `withdrawal_requests` inserts
    - _Requirements: 6.1, 6.5, 22.4_
  - [x] 7.2 Modify `backend/includes/payout_service.php` — crypto withdrawal
    - In `createPayout()`: replace `UPDATE users SET balance = balance - ?` with `LedgerService::addEntry(type='crypto_withdrawal', direction='debit')`
    - In `refundPayout()` and `rejectPayout()`: replace `UPDATE users SET balance = balance + ?` with `LedgerService::addEntry(type='crypto_withdrawal_refund', direction='credit')`
    - Add bot withdrawal block: reject if `is_bot = 1`
    - Use `reference_type='crypto_payout'`, `reference_id=payout_id`
    - _Requirements: 6.2, 6.3, 6.5, 17.8, 19.2, 19.3, 19.4, 22.4_
  - [x] 7.3 Modify `backend/includes/webhook_handler.php` — payout refund path
    - In `handlePayout()` failed/expired branch: replace `UPDATE users SET balance = balance + ?` with `LedgerService::addEntry(type='crypto_withdrawal_refund', direction='credit')`
    - _Requirements: 6.3, 19.3_

- [x] 8. Checkpoint — Verify deposit and withdrawal ledger integration
  - Ensure all tests pass, ask the user if questions arise.

- [x] 9. Implement GameEngine
  - [x] 9.1 Create `backend/includes/game_engine.php` — GameEngine class
    - Constructor accepts `PDO $pdo` and `LedgerService $ledger`
    - Define state machine: `waiting` → `active` → `spinning` → `finished`
    - Implement `getOrCreateRound(int $room): array` — get active round or create new one in `waiting` status with server seed
    - Implement state transition validation: reject invalid transitions (e.g. `waiting` → `spinning`)
    - Use `SELECT ... FOR UPDATE` on `game_rounds` row before any transition
    - _Requirements: 10.1, 10.6, 10.7_
  - [x] 9.2 Implement `GameEngine::placeBet(int $userId, int $room, string $clientSeed): array`
    - Validate room is `waiting` or `active`, bet amount matches room, user has sufficient balance
    - Within single transaction: call `LedgerService::addEntry(type='bet', direction='debit')`, insert `game_bets` row with `ledger_entry_id`, update `game_rounds.total_pot`
    - Rate limit: 5 bets per user per second (reuse `LOTTERY_MAX_BETS_PER_SEC`)
    - If distinct player count reaches ≥ 2 and round is `waiting`, transition to `active` within same transaction
    - Reject bets if status is `spinning` or `finished`
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6, 7.1, 22.1_
  - [x] 9.3 Implement `GameEngine::finishRound(int $roundId): array` — winner selection and payout
    - Extract `pickWeightedWinner`, `computePayoutAmounts`, `resolveReferrer`, `hashToFloat`, `computeTarget`, `buildCombinedHashFromSeeds`, `lowerBound` from existing `lottery.php` — reuse these functions directly (do NOT rewrite)
    - Within single transaction with strict lock ordering: (1) lock `game_rounds` FOR UPDATE, (2) lock `user_balances` rows sorted by user_id ASC, (3) System_Account last
    - Double-payout protection: check `payout_status = 'paid'` → exit immediately
    - Credit winner via `LedgerService::addEntry(type='win')`
    - Credit system fee via `LedgerService::addEntry` on System_Account `(type='system_fee')`
    - Credit referrer or route unclaimed bonus to System_Account `(type='referral_bonus')`
    - Store immutable snapshot: `final_bets_snapshot`, `final_combined_hash`, `final_rand_unit`, `final_target`, `final_total_weight`
    - Set `payout_status='paid'`, generate UUID `payout_id`
    - Retry mechanism: up to 3 attempts on deadlock/lock timeout with 50ms/100ms/150ms backoff
    - Create new round in `waiting` status for same room
    - _Requirements: 10.4, 10.5, 12.1, 12.2, 12.3, 12.4, 12.5, 12.6, 12.7, 12.8, 7.2, 7.3, 7.4, 7.5, 22.2, 3.5_
  - [x] 9.4 Implement `GameEngine::getGameState(int $room, ?int $userId): array`
    - Return current round state: `round_id`, `status`, `total_pot`, `countdown`, `winner`, `server_seed_hash`, `server_seed` (only if finished), `room`, aggregated bets, `unique_players`, `total_bets`, `my_stats`
    - Compute countdown as `max(0, 30 - elapsed)` when status is `active`
    - Read-only: NO state transitions triggered
    - _Requirements: 14.1, 14.2, 14.3_
  - [ ]* 9.5 Write property test for GameEngine state transitions
    - **Property 4: State machine validity — only valid transitions (waiting→active→spinning→finished) are accepted; all others are rejected**
    - **Validates: Requirements 10.1, 10.6**
  - [ ]* 9.6 Write property test for payout distribution
    - **Property 5: Payout conservation — for any pot ≥ $0.50, winner_net + commission + referral_bonus = total_pot (no money created or destroyed)**
    - **Validates: Requirements 7.5, 12.2, 25.3**
  - [ ]* 9.7 Write property test for bet-pot consistency
    - **Property 6: Bet-pot consistency — sum of all bet debit ledger entries for a round equals game_rounds.total_pot**
    - **Validates: Requirements 25.3, 22.1**

- [x] 10. Implement Game Worker
  - [x] 10.1 Create `backend/game_worker.php`
    - Continuous loop with 1-second sleep between iterations
    - Each iteration processes all three rooms (1, 10, 100)
    - `processWaitingRounds()`: check waiting rounds with ≥ 2 players → transition to `active`
    - `processActiveRounds()`: check active rounds with expired countdown (30s) → transition to `spinning`
    - `processSpinningRounds()`: execute `GameEngine::finishRound()` → transition to `finished`, create new round
    - Use `SELECT ... FOR UPDATE` on each `game_rounds` row before transitions
    - _Requirements: 13.1, 13.2, 13.3, 13.4, 10.2, 10.3, 10.8_

- [x] 11. Checkpoint — Verify GameEngine and Game Worker
  - Ensure all tests pass, ask the user if questions arise.

- [x] 12. API endpoints — game status, bet, verify
  - [x] 12.1 Create `backend/api/game/status.php`
    - Accept `room` query parameter, validate room ∈ {1, 10, 100}
    - Call `GameEngine::getGameState($room, $userId)` — strictly read-only, no state transitions
    - Return previous finished round data for same room
    - Return current user balance from `users.balance` cache
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5, 13.5_
  - [x] 12.2 Create `backend/api/game/bet.php`
    - Accept POST `{ "room": 1, "client_seed": "..." }`, require valid session
    - Delegate to `GameEngine::placeBet($userId, $room, $clientSeed)`
    - Return `{ "ok": true, "state": {...}, "balance": ... }` on success
    - Return HTTP 400 `{ "error": "..." }` on failure
    - _Requirements: 15.1, 15.2, 15.3, 15.4_
  - [x] 12.3 Create `backend/api/game/verify.php`
    - Accept `game_id` query parameter
    - Return full verification data: server seed, client seeds, combined hash, random unit, target, cumulative weights, winner index, hash format constant
    - Use `final_bets_snapshot` from `game_rounds` when available
    - Fall back to `lottery_games` + `lottery_bets` for legacy games
    - Reuse `hashToFloat`, `computeTarget`, `buildCombinedHashFromSeeds`, `lowerBound` from `lottery.php`
    - _Requirements: 16.1, 16.2, 16.3, 24.1, 24.2_

- [x] 13. Refactor bot_runner.php
  - [x] 13.1 Modify `backend/bot_runner.php`
    - Replace `placeBet($pdo, ...)` calls with `GameEngine::placeBet($botId, $room, $seed)`
    - Replace `topUpBots()` direct SQL with `LedgerService::addEntry(type='deposit', direction='credit', reference_type='bot_topup')`
    - Add bot balance cap check: skip top-up if balance > $50,000 (configurable `BOT_MAX_BALANCE`)
    - Include `is_bot = true` in ledger entry metadata for all bot operations
    - Keep existing bot selection, multi-bet, and activity spike logic unchanged
    - Use `GameEngine::getOrCreateRound()` instead of `getOrCreateActiveGame()`
    - _Requirements: 17.1, 17.2, 17.3, 17.4, 17.5, 17.6, 17.7_

- [x] 14. System Account setup
  - [x] 14.1 Create System Account user and configuration
    - Define `SYSTEM_USER_ID` constant in `ledger_service.php`
    - Insert system user row in `users` table (or use id=0) with `is_bot=1`, `is_verified=1`
    - Create `user_balances` row for System_Account
    - System fees and unclaimed referral bonuses route to System_Account via ledger entries
    - _Requirements: 26.1, 26.2, 26.3, 26.4, 26.5_

- [x] 15. Checkpoint — Verify API endpoints, bot runner, and system account
  - Ensure all tests pass, ask the user if questions arise.

- [x] 16. Frontend migration — polling hook and component updates
  - [x] 16.1 Update `frontend/src/api/client.js`
    - Add new game API methods: `gameStatus(room)` → `GET /backend/api/game/status.php?room=`, `gameBet(room, clientSeed)` → `POST /backend/api/game/bet.php`, `gameVerify(gameId)` → `GET /backend/api/game/verify.php?game_id=`
    - Keep existing `lottery*` methods for backward compatibility during migration
    - _Requirements: 20.2_
  - [x] 16.2 Replace `frontend/src/hooks/useGameMachine.js`
    - Create simplified hook that maps backend status directly to UI phases: `waiting` → BETTING, `active` → COUNTDOWN, `spinning` → DRAWING, `finished` → RESULT
    - Remove local state machine reducer logic — frontend becomes pure display layer
    - Keep `SPIN_DURATION` and `RESULT_HOLD` timers for animation
    - _Requirements: 20.1, 20.3_
  - [x] 16.3 Update `frontend/src/hooks/useLottery.js`
    - Switch from `api.lotteryStatus(room)` to `api.gameStatus(room)`
    - Switch from `api.lotteryBet(room, seed)` to `api.gameBet(room, seed)`
    - Map new response shape (`round_id` instead of `game.id`, new status values) to existing state variables
    - _Requirements: 20.2, 20.4_
  - [x] 16.4 Update `frontend/src/components/lottery/*` components
    - Update `LotteryPanel.jsx`, `BetsTable.jsx`, `Participants.jsx`, `PotDisplay.jsx`, `CountdownTimer.jsx`, `WinnerAnimation.jsx`, `PreviousGame.jsx`, `PlaceBetButton.jsx` to use new data source
    - `PlaceBetButton`: disable when backend reports `spinning` or `finished`
    - All components continue to function with new data shape — backward compatible UI
    - _Requirements: 20.4, 20.5_

- [x] 17. Admin panel compatibility
  - [x] 17.1 Update admin endpoints for ledger/game_rounds compatibility
    - `backend/api/admin/users.php`: continue reading `users.balance` cache for display
    - `backend/api/admin/transactions.php`: return data from both `user_transactions` and `ledger_entries`, or `ledger_entries` only post-migration
    - `backend/api/admin/lottery_games.php`: return data from `game_rounds` for new games, `lottery_games` for legacy
    - `backend/api/admin/system_balance.php`: read System_Account balance from `user_balances` for SYSTEM_USER_ID, fall back to `system_balance` singleton during migration
    - `backend/api/admin/action.php`: use `LedgerService::addEntry` for any balance modifications (ban, unban, manual adjustments)
    - _Requirements: 21.1, 21.2, 21.3, 21.4, 21.5, 24.3_

- [x] 18. Anti-fraud monitoring hooks
  - [x] 18.1 Add anti-fraud checks to GameEngine and LedgerService
    - Configurable max bets-per-minute rate limit per user (default: 60/min) — hard block that rejects bet
    - Suspicious win streak detection: log warning when user wins > N times in M hours (default N=10, M=24), flag in metadata
    - Log `ip` and `user_agent` in ledger entry metadata for every bet and financial operation
    - Win streak and multi-account correlation are monitoring/flagging only — do not block operations
    - _Requirements: 27.1, 27.2, 27.3, 27.4, 1.8_

- [x] 19. Checkpoint — Verify frontend, admin panel, and anti-fraud
  - Ensure all tests pass, ask the user if questions arise.

- [x] 20. Final integration and legacy compatibility
  - [x] 20.1 Ensure legacy data compatibility
    - Retain `lottery_games` and `lottery_bets` tables — do NOT delete or alter existing data
    - Verify `game/verify.php` works for both `game_rounds` (new) and `lottery_games` (legacy) game IDs
    - Keep `user_transactions` inserts for backward compatibility
    - _Requirements: 24.1, 24.2, 24.3, 24.4_
  - [ ]* 20.2 Write integration test for full game lifecycle
    - Test complete flow: create round → place bets → countdown expires → winner selected → payouts distributed → new round created
    - Verify ledger entries balance consistency after full cycle
    - Verify `users.balance` cache matches `user_balances.balance` matches last `ledger_entries.balance_after`
    - _Requirements: 22.1, 22.2, 23.1, 23.2, 23.3, 23.5_
  - [ ]* 20.3 Write integration test for deposit → bet → win → withdraw lifecycle
    - Test full user financial lifecycle through ledger
    - Verify round-trip balance consistency
    - _Requirements: 25.1, 25.2_

- [x] 21. Final checkpoint — Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation after each major phase
- The existing `lottery.php` provably fair functions (`hashToFloat`, `computeTarget`, `buildCombinedHashFromSeeds`, `lowerBound`, `computePayoutAmounts`, `resolveReferrer`) are reused in GameEngine — not rewritten
- Legacy tables (`lottery_games`, `lottery_bets`, `user_transactions`) are retained for historical data
- The `game_worker.php` is a long-running process — run it manually in a terminal, not via automated tests
