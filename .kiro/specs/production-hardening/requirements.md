# Requirements Document

## Introduction

This document specifies six production-hardening gaps identified by a security audit of the anora.bet lottery platform. The platform already has a LedgerService with idempotency, a GameEngine with a state machine, file-locked game_worker, payout protection, provably fair RNG (SHA-256), referral abuse protection, bot controls, and basic anti-fraud (rate limits + win streak logging). These requirements close the remaining gaps: admin ledger visibility, suspicious activity monitoring, automated win streak flagging, a race condition fix in bet placement, mandatory client seeds, and detailed round history with payouts.

## Glossary

- **Admin_Panel**: The administrative interface accessible only to authenticated admin users, protected by the `requireAdmin` guard.
- **Ledger_Explorer**: A new admin page that displays paginated, filterable rows from the `ledger_entries` table.
- **Activity_Monitor**: A new admin page that auto-detects and displays flagged suspicious user activity.
- **GameEngine**: The PHP class (`backend/includes/game_engine.php`) responsible for round lifecycle, bet placement, winner selection, and payout distribution.
- **LedgerService**: The PHP class (`backend/includes/ledger_service.php`) responsible for idempotent balance mutations via the `ledger_entries` and `user_balances` tables.
- **Ledger_Entry**: A row in the `ledger_entries` table representing a single balance mutation (credit or debit) for a user.
- **Fraud_Flag**: A boolean column (`fraud_flagged`) on the `users` table indicating that automated anti-fraud logic has flagged the account.
- **Client_Seed**: A per-bet random string contributed by the client to the provably fair combined hash. Stored in `game_bets.client_seed`.
- **Round_History**: The enhanced admin lottery games view showing detailed payout breakdown, bet details, and provably fair data for each finished round.
- **Balance_Lock**: A `SELECT ... FOR UPDATE` row-level lock on `user_balances` that serializes concurrent balance mutations for a given user.

## Requirements

### Requirement 1: Admin Ledger Explorer Backend

**User Story:** As an admin, I want to query ledger entries with pagination and filters, so that I can audit balance mutations in real-time.

#### Acceptance Criteria

1. WHEN an authenticated admin sends a GET request to `/backend/api/admin/ledger.php`, THE Ledger_Explorer SHALL return a JSON response containing a paginated list of Ledger_Entry rows.
2. THE Ledger_Explorer SHALL include the following fields for each Ledger_Entry: `id`, `user_id`, `user email`, `type`, `amount`, `direction`, `balance_after`, `reference_id`, `reference_type`, `created_at`.
3. WHEN the request includes a `user_id` or `email` query parameter, THE Ledger_Explorer SHALL return only Ledger_Entry rows matching that user.
4. WHEN the request includes a `type` query parameter, THE Ledger_Explorer SHALL return only Ledger_Entry rows matching that type (deposit, bet, win, system_fee, referral_bonus, withdrawal, or other valid types).
5. WHEN the request includes `date_from` and `date_to` query parameters, THE Ledger_Explorer SHALL return only Ledger_Entry rows with `created_at` within that date range (inclusive).
6. WHEN the request includes a `reference_type` query parameter, THE Ledger_Explorer SHALL return only Ledger_Entry rows matching that reference_type.
7. WHEN the request includes a `reference_id` query parameter, THE Ledger_Explorer SHALL return only Ledger_Entry rows matching that exact reference_id.
8. THE Ledger_Explorer SHALL accept `page` and `per_page` query parameters and return the corresponding page of results, along with `total_count`, `total_pages`, and `page` in the response.
9. IF an unauthenticated or non-admin user sends a request to the Ledger_Explorer endpoint, THEN THE Ledger_Explorer SHALL return HTTP 403 Forbidden.

### Requirement 2: Admin Ledger Explorer Frontend

**User Story:** As an admin, I want a dedicated "Ledger Explorer" page in the admin panel, so that I can visually browse and filter ledger entries.

#### Acceptance Criteria

1. THE Admin_Panel SHALL include a "Ledger Explorer" navigation link in the sidebar that routes to `/admin/ledger`.
2. WHEN the admin navigates to `/admin/ledger`, THE Ledger_Explorer SHALL display a paginated table with columns: id, user_id, email, type, amount, direction, balance_after, reference_id, reference_type, created_at.
3. THE Ledger_Explorer SHALL provide filter controls for: user_id or email, type (dropdown), date range (from/to date pickers), and reference_type.
4. THE Ledger_Explorer SHALL provide a search input for reference_id.
5. WHEN the admin changes any filter or search value, THE Ledger_Explorer SHALL re-fetch results from the backend starting at page 1.
6. THE Ledger_Explorer SHALL display pagination controls allowing the admin to navigate between pages.

### Requirement 3: Suspicious Activity Monitor Backend

**User Story:** As an admin, I want the system to auto-detect suspicious activity patterns, so that I can investigate potential fraud or abuse.

#### Acceptance Criteria

1. WHEN an authenticated admin sends a GET request to `/backend/api/admin/activity_monitor.php`, THE Activity_Monitor SHALL return a JSON array of flagged events.
2. THE Activity_Monitor SHALL detect win streak flags: users with 10 or more wins (rows in `game_rounds` where `winner_id` matches) within the last 24 hours.
3. THE Activity_Monitor SHALL detect high velocity flags: users who placed 50 or more bets (rows in `game_bets`) within any 5-minute window in the last 24 hours.
4. THE Activity_Monitor SHALL detect IP correlation flags: groups of 2 or more distinct non-bot users who share the same `registration_ip` value.
5. THE Activity_Monitor SHALL detect large withdrawal flags: individual ledger entries of type `withdrawal` with amount greater than 1000.00 created within the last 7 days.
6. THE Activity_Monitor SHALL return the following fields for each flag: `user_id`, `email`, `flag_type`, `details`, `timestamp`.
7. IF an unauthenticated or non-admin user sends a request to the Activity_Monitor endpoint, THEN THE Activity_Monitor SHALL return HTTP 403 Forbidden.

### Requirement 4: Suspicious Activity Monitor Frontend

**User Story:** As an admin, I want a dedicated "Activity Monitor" page in the admin panel, so that I can review flagged events and take action.

#### Acceptance Criteria

1. THE Admin_Panel SHALL include an "Activity Monitor" navigation link in the sidebar that routes to `/admin/activity-monitor`.
2. WHEN the admin navigates to `/admin/activity-monitor`, THE Activity_Monitor SHALL display a table of flagged events with columns: user_id, email, flag_type, details, timestamp.
3. THE Activity_Monitor SHALL provide a "Dismiss" action for each flag that removes the flag from the displayed list.
4. THE Activity_Monitor SHALL provide a "Ban User" action for each flag that sends a ban request to the existing `/backend/api/admin/action.php` endpoint and updates the display.

### Requirement 5: Win Streak Auto-Suspension

**User Story:** As a platform operator, I want accounts with suspicious win streaks to be automatically flagged, so that potential fraud is surfaced without manual monitoring.

#### Acceptance Criteria

1. THE `users` table SHALL include a `fraud_flagged` column of type TINYINT with a default value of 0.
2. WHEN a user wins a round and the GameEngine detects 10 or more wins by that user within the last 24 hours, THE GameEngine SHALL set `fraud_flagged` to 1 on that user's row in the `users` table.
3. WHILE a user has `fraud_flagged` set to 1, THE GameEngine SHALL allow the user to continue placing bets and participating in rounds.
4. THE Admin_Panel users list SHALL display the `fraud_flagged` status for each user, visually highlighting flagged accounts.
5. THE Admin_Panel SHALL allow an admin to clear the `fraud_flagged` flag on a user (set to 0).
6. THE Admin_Panel SHALL allow an admin to ban a flagged user via the existing ban mechanism.

### Requirement 6: Fix Race Condition on Bet Placement

**User Story:** As a platform operator, I want the balance check and debit in bet placement to occur under the same database lock, so that concurrent bets cannot overdraw a user's balance.

#### Acceptance Criteria

1. WHEN GameEngine::placeBet() processes a bet, THE GameEngine SHALL call LedgerService::getBalanceForUpdate() to acquire a row-level lock on the user's `user_balances` row BEFORE calling LedgerService::addEntry().
2. THE GameEngine SHALL use the locked balance returned by getBalanceForUpdate() to verify the user has sufficient funds before proceeding with the debit.
3. IF the locked balance is less than the bet amount, THEN THE GameEngine SHALL throw an "Insufficient balance" exception and roll back the transaction.
4. FOR ALL concurrent bet requests by the same user, THE GameEngine SHALL serialize balance checks via the row-level lock, preventing any overdraw.

### Requirement 7: Mandatory Client Seeds

**User Story:** As a platform operator, I want every bet to include a client seed, so that all rounds have full entropy contribution from the client side.

#### Acceptance Criteria

1. WHEN GameEngine::placeBet() receives an empty or missing clientSeed parameter, THE GameEngine SHALL auto-generate a client seed.
2. THE GameEngine SHALL format the auto-generated client seed as four dash-separated unsigned 32-bit integers (e.g., `3284719283-1928374650-482917364-1029384756`), matching the frontend client seed format.
3. THE GameEngine SHALL generate the four integers using `random_bytes` for cryptographic randomness.
4. FOR ALL bets stored in `game_bets`, THE `client_seed` column SHALL contain a non-null, non-empty value.

### Requirement 8: Admin Round History with Payout Details

**User Story:** As an admin, I want to see detailed payout breakdowns and provably fair data for each finished round, so that I can audit game outcomes.

#### Acceptance Criteria

1. THE admin lottery games endpoint SHALL return the following additional fields for each finished round: `winner_name` (nickname or email), `winner_net`, `commission`, `referral_bonus`, `payout_id`, `server_seed`, `final_combined_hash`.
2. WHEN an admin requests details for a specific round via a `round_id` query parameter, THE endpoint SHALL return all bets in that round including: `user_id`, `display_name`, `amount`, `chance` (proportion of total pot), and `client_seed`.
3. THE admin lottery games frontend SHALL display payout columns (winner_name, winner_net, commission, referral_bonus, payout_id) for each finished round row.
4. THE admin lottery games frontend SHALL provide an expandable detail view for each round that shows all bets with user names, amounts, and chances.
5. THE admin lottery games frontend SHALL display provably fair data (server_seed, combined_hash) in the expanded detail view for finished rounds.
