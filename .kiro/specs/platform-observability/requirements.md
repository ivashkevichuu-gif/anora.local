# Requirements Document

## Introduction

This document specifies five observability and integrity features for the anora.bet lottery platform that close gaps not covered by the existing ledger-state-machine and production-hardening specs. The features are: device fingerprinting for enhanced anti-fraud detection, an admin finance dashboard for platform-wide financial overview, a global balance reconciliation cron job for runtime integrity verification, an admin games/RTP analytics page, and a health check endpoint that verifies the platform's three core monetary invariants on demand.

The platform already has: an append-only LedgerService with idempotency and `user_balances` lock table, a GameEngine with state machine (waiting → active → spinning → finished), a System Account (SYSTEM_USER_ID = 1) for platform revenue, basic anti-fraud (rate limits, win streak auto-flagging, IP correlation), an admin ledger explorer, and an activity monitor covering win streaks, high velocity, IP correlation, and large withdrawals.

## Glossary

- **System**: The anora.bet lottery platform backend (PHP 8.4 / MySQL InnoDB).
- **Admin_Panel**: The administrative React SPA accessible only to authenticated admin users, with sidebar navigation and route-based pages.
- **LedgerService**: The PHP class (`backend/includes/ledger_service.php`) responsible for idempotent balance mutations via the `ledger_entries` and `user_balances` tables.
- **GameEngine**: The PHP class (`backend/includes/game_engine.php`) responsible for round lifecycle, bet placement, winner selection, and payout distribution.
- **System_Account**: The dedicated system user (SYSTEM_USER_ID = 1) that receives platform fees and unclaimed referral bonuses as ledger entries.
- **Device_Fingerprint**: A composite identifier derived from a client's IP address, user-agent string, and canvas hash, used to correlate sessions and detect multi-accounting.
- **Fingerprint_Store**: The `device_fingerprints` table that stores per-session fingerprint data linked to users.
- **Finance_Dashboard**: A new admin page displaying platform-wide financial aggregates: total deposits, total withdrawals, system profit, and net platform position.
- **Reconciliation_Job**: A cron job that periodically verifies the global balance invariant: sum of all `user_balances` equals sum of all credits minus sum of all debits in `ledger_entries`.
- **RTP**: Return To Player — the ratio of total payouts to players divided by total bets, expressed as a percentage.
- **Games_Analytics**: A new admin page displaying game rounds with filters, per-round winners, and RTP calculations (global and per-room).
- **Monetary_Invariant**: One of three core platform guarantees: (1) no money created, (2) no money lost, (3) everything traceable.
- **Health_Check**: An admin endpoint that verifies all three Monetary_Invariants on demand and returns pass/fail status with details.
- **Activity_Monitor**: The existing admin endpoint (`backend/api/admin/activity_monitor.php`) that detects win streaks, high velocity, IP correlation, and large withdrawals.

---

## Requirements

### Requirement 1: Device Fingerprint Collection Schema

**User Story:** As a platform operator, I want device fingerprint data stored per session, so that anti-fraud rules can correlate accounts and detect suspicious device patterns.

#### Acceptance Criteria

1. THE System SHALL create a `device_fingerprints` table with columns: `id` (INT AUTO_INCREMENT PRIMARY KEY), `user_id` (INT NOT NULL, FK → users.id), `session_id` (VARCHAR(128) NOT NULL), `ip_address` (VARCHAR(45) NOT NULL), `user_agent` (TEXT NOT NULL), `canvas_hash` (VARCHAR(64) DEFAULT NULL), `created_at` (DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP).
2. THE System SHALL create indexes on `device_fingerprints`: `idx_user_id (user_id)`, `idx_ip_address (ip_address)`, `idx_canvas_hash (canvas_hash)`, `idx_created_at (created_at)`.
3. THE System SHALL provide a `POST /backend/api/game/fingerprint.php` endpoint that accepts `{ "canvas_hash": "..." }` from the authenticated client and inserts a Fingerprint_Store row with the user's current session ID, IP address, user-agent, and the provided canvas hash.
4. WHEN a user places a bet via `GameEngine::placeBet`, THE GameEngine SHALL store the user's current IP address and user-agent in the `game_bets` metadata or the corresponding `ledger_entries.metadata` JSON field.
5. THE fingerprint endpoint SHALL require a valid user session; IF no session exists, THEN THE endpoint SHALL return HTTP 401 Unauthorized.

---

### Requirement 2: Device Fingerprint Anti-Fraud Rules

**User Story:** As a platform operator, I want automated rules that flag suspicious device fingerprint patterns, so that multi-accounting and bot-like behavior are detected early.

#### Acceptance Criteria

1. THE Activity_Monitor SHALL detect multi-account IP flags: WHEN 3 or more distinct non-bot user accounts have placed bets from the same IP address within the last 7 days (based on `device_fingerprints.ip_address`), THE Activity_Monitor SHALL generate a flag with `flag_type = 'multi_account_ip'`.
2. THE Activity_Monitor SHALL detect canvas hash correlation flags: WHEN 2 or more distinct non-bot user accounts share the same non-null `canvas_hash` value in `device_fingerprints`, THE Activity_Monitor SHALL generate a flag with `flag_type = 'canvas_correlation'`.
3. THE Activity_Monitor SHALL detect anomalous win rate flags: WHEN a non-bot user's win count divided by total rounds participated in exceeds 40% over the last 100 rounds, THE Activity_Monitor SHALL generate a flag with `flag_type = 'anomalous_win_rate'`.
4. THE Activity_Monitor SHALL detect rapid bet speed flags: WHEN a non-bot user places 10 or more bets within a 10-second window (based on `game_bets.created_at`), THE Activity_Monitor SHALL generate a flag with `flag_type = 'rapid_bet_speed'`.
5. EACH fingerprint-based flag SHALL include: `user_id`, `email`, `flag_type`, `details` (human-readable description), and `timestamp`.
6. THE fingerprint-based flags SHALL appear alongside existing Activity_Monitor flags (win_streak, high_velocity, ip_correlation, large_withdrawal) in the same response array.

---

### Requirement 3: Admin Finance Dashboard Backend

**User Story:** As an admin, I want a single endpoint that returns platform-wide financial aggregates, so that I can monitor the financial health of the platform at a glance.

#### Acceptance Criteria

1. WHEN an authenticated admin sends a GET request to `/backend/api/admin/finance_dashboard.php`, THE Finance_Dashboard SHALL return a JSON response containing platform-wide financial aggregates.
2. THE Finance_Dashboard SHALL compute `total_deposits` as the sum of `amount` from `ledger_entries` WHERE `type IN ('deposit', 'crypto_deposit')` AND `direction = 'credit'` AND the user is not a bot (`users.is_bot = 0`).
3. THE Finance_Dashboard SHALL compute `total_withdrawals` as the sum of `amount` from `ledger_entries` WHERE `type IN ('withdrawal', 'crypto_withdrawal')` AND `direction = 'debit'` AND the user is not a bot.
4. THE Finance_Dashboard SHALL compute `system_profit` as the current balance of the System_Account from `user_balances` WHERE `user_id = SYSTEM_USER_ID`.
5. THE Finance_Dashboard SHALL compute `net_platform_position` as `total_deposits - total_withdrawals`.
6. THE Finance_Dashboard SHALL compute `total_bets_volume` as the sum of `amount` from `ledger_entries` WHERE `type = 'bet'` AND `direction = 'debit'` AND the user is not a bot.
7. THE Finance_Dashboard SHALL compute `total_payouts_volume` as the sum of `amount` from `ledger_entries` WHERE `type = 'win'` AND `direction = 'credit'` AND the user is not a bot.
8. THE Finance_Dashboard SHALL return all values as JSON with keys: `total_deposits`, `total_withdrawals`, `system_profit`, `net_platform_position`, `total_bets_volume`, `total_payouts_volume`.
9. IF an unauthenticated or non-admin user sends a request to the Finance_Dashboard endpoint, THEN THE Finance_Dashboard SHALL return HTTP 403 Forbidden.

---

### Requirement 4: Admin Finance Dashboard Frontend

**User Story:** As an admin, I want a "Finance Dashboard" page in the admin panel, so that I can visually review platform financial health.

#### Acceptance Criteria

1. THE Admin_Panel SHALL include a "Finance Dashboard" navigation link in the sidebar that routes to `/admin/finance`.
2. WHEN the admin navigates to `/admin/finance`, THE Finance_Dashboard SHALL display six metric cards: Total Deposits, Total Withdrawals, System Profit, Net Platform Position, Total Bets Volume, Total Payouts Volume.
3. EACH metric card SHALL display the value formatted as USD currency with two decimal places.
4. THE Finance_Dashboard SHALL fetch data from `/backend/api/admin/finance_dashboard.php` on mount and display a loading indicator while the request is in progress.
5. THE Finance_Dashboard SHALL display an error message if the backend request fails.
6. THE Finance_Dashboard SHALL provide a "Refresh" button that re-fetches the data from the backend.

---

### Requirement 5: Global Balance Reconciliation Cron Job

**User Story:** As a platform operator, I want a periodic cron job that verifies the global balance invariant, so that any discrepancy between ledger entries and user balances is detected and alerted immediately.

#### Acceptance Criteria

1. THE System SHALL provide a `backend/cron/reconciliation.php` script that can be executed by a system cron scheduler.
2. WHEN the Reconciliation_Job runs, THE Reconciliation_Job SHALL compute `sum_user_balances` as `SELECT SUM(balance) FROM user_balances`.
3. WHEN the Reconciliation_Job runs, THE Reconciliation_Job SHALL compute `sum_credits` as `SELECT SUM(amount) FROM ledger_entries WHERE direction = 'credit'`.
4. WHEN the Reconciliation_Job runs, THE Reconciliation_Job SHALL compute `sum_debits` as `SELECT SUM(amount) FROM ledger_entries WHERE direction = 'debit'`.
5. THE Reconciliation_Job SHALL verify the invariant: `sum_user_balances` equals `sum_credits - sum_debits` (within a tolerance of 0.01 to account for floating-point rounding).
6. WHEN the invariant holds, THE Reconciliation_Job SHALL log a success message including all three computed values and the timestamp.
7. WHEN the invariant does not hold, THE Reconciliation_Job SHALL log an error message with severity CRITICAL including all three computed values, the expected value, the actual discrepancy amount, and the timestamp.
8. THE Reconciliation_Job SHALL also verify per-user consistency: for each user, `user_balances.balance` SHALL equal the `balance_after` value of the user's most recent `ledger_entries` row (ordered by `id DESC`).
9. WHEN a per-user discrepancy is found, THE Reconciliation_Job SHALL log an error message identifying the user_id, the `user_balances.balance` value, and the expected `balance_after` value.
10. THE Reconciliation_Job SHALL write all results to a log file at `backend/logs/reconciliation.log` with timestamps.
11. THE Reconciliation_Job SHALL complete execution and exit (non-daemon); the scheduling frequency is configured externally via crontab.

---

### Requirement 6: Admin Games Analytics and RTP Backend

**User Story:** As an admin, I want an endpoint that returns game round data with RTP calculations, so that I can monitor game fairness and platform performance.

#### Acceptance Criteria

1. WHEN an authenticated admin sends a GET request to `/backend/api/admin/games_analytics.php`, THE Games_Analytics SHALL return a JSON response containing game round data and RTP metrics.
2. THE Games_Analytics SHALL accept optional query parameters: `room` (filter by room type: 1, 10, or 100), `date_from` and `date_to` (filter by `finished_at` date range), `page` and `per_page` (pagination, default per_page = 20).
3. THE Games_Analytics SHALL return a paginated list of finished game rounds with fields: `id`, `room`, `total_pot`, `winner_id`, `winner_name` (nickname or email), `winner_net`, `commission`, `referral_bonus`, `finished_at`, `player_count` (distinct users in that round).
4. THE Games_Analytics SHALL compute and return `global_rtp` as: `(SUM of winner_net for all matching finished rounds) / (SUM of total_pot for all matching finished rounds) * 100`, expressed as a percentage.
5. THE Games_Analytics SHALL compute and return `rtp_by_room` as an object with keys `1`, `10`, `100`, each containing the RTP percentage for that room calculated using the same formula.
6. THE Games_Analytics SHALL return `total_rounds`, `total_pot_sum`, and `total_payout_sum` aggregate values for the filtered result set.
7. WHEN a `round_id` query parameter is provided, THE Games_Analytics SHALL return detailed data for that single round including all bets: `user_id`, `display_name`, `amount`, `chance`, `client_seed`, and the round's provably fair data (`server_seed`, `final_combined_hash`).
8. IF an unauthenticated or non-admin user sends a request to the Games_Analytics endpoint, THEN THE Games_Analytics SHALL return HTTP 403 Forbidden.

---

### Requirement 7: Admin Games Analytics and RTP Frontend

**User Story:** As an admin, I want a "Games Analytics" page in the admin panel, so that I can visually review game rounds, winners, and RTP metrics.

#### Acceptance Criteria

1. THE Admin_Panel SHALL include a "Games Analytics" navigation link in the sidebar that routes to `/admin/games-analytics`.
2. WHEN the admin navigates to `/admin/games-analytics`, THE Games_Analytics page SHALL display RTP summary cards: Global RTP percentage, and per-room RTP percentages for rooms 1, 10, and 100.
3. THE Games_Analytics page SHALL display a paginated table of finished game rounds with columns: Round ID, Room, Total Pot, Winner, Winner Net, Commission, Referral Bonus, Players, Finished At.
4. THE Games_Analytics page SHALL provide filter controls: room selector (All / 1 / 10 / 100) and date range picker (from/to).
5. WHEN the admin clicks on a round row, THE Games_Analytics page SHALL expand an inline detail view showing all bets for that round (user, amount, chance, client_seed) and provably fair data (server_seed, combined_hash).
6. THE Games_Analytics page SHALL display pagination controls allowing the admin to navigate between pages.
7. WHEN the admin changes any filter, THE Games_Analytics page SHALL re-fetch results from the backend starting at page 1.

---

### Requirement 8: Runtime Monetary Invariants Health Check

**User Story:** As a platform operator, I want an on-demand health check endpoint that verifies the three core monetary invariants, so that I can confirm platform integrity at any time without waiting for the cron job.

#### Acceptance Criteria

1. WHEN an authenticated admin sends a GET request to `/backend/api/admin/health_check.php`, THE Health_Check SHALL verify all three Monetary_Invariants and return a JSON response.
2. THE Health_Check SHALL verify "No money created": the sum of all `user_balances.balance` values SHALL equal `SUM(amount) WHERE direction='credit'` minus `SUM(amount) WHERE direction='debit'` from `ledger_entries`, within a tolerance of 0.01.
3. THE Health_Check SHALL verify "No money lost": every row in `user_balances` SHALL have a corresponding most-recent `ledger_entries` row whose `balance_after` matches `user_balances.balance`, within a tolerance of 0.01.
4. THE Health_Check SHALL verify "Everything traceable": every row in `ledger_entries` SHALL have non-null, non-empty `reference_id` and `reference_type` values.
5. THE Health_Check SHALL return a JSON response with structure: `{ "status": "ok" | "fail", "checks": { "no_money_created": { "passed": bool, "details": {...} }, "no_money_lost": { "passed": bool, "mismatched_users": [...] }, "everything_traceable": { "passed": bool, "untraceable_count": int } }, "checked_at": "ISO timestamp" }`.
6. WHEN all three checks pass, THE Health_Check SHALL return `"status": "ok"`.
7. WHEN any check fails, THE Health_Check SHALL return `"status": "fail"` with details identifying the specific failure.
8. IF an unauthenticated or non-admin user sends a request to the Health_Check endpoint, THEN THE Health_Check SHALL return HTTP 403 Forbidden.

---

### Requirement 9: Device Fingerprint Frontend Collection

**User Story:** As a platform operator, I want the frontend to collect and submit device fingerprint data on session start, so that the backend has fingerprint data available for anti-fraud analysis.

#### Acceptance Criteria

1. WHEN a user logs in successfully, THE frontend application SHALL compute a canvas fingerprint hash by rendering a predefined string to an HTML5 Canvas element and hashing the resulting image data using SHA-256.
2. AFTER computing the canvas hash, THE frontend application SHALL send a POST request to `/backend/api/game/fingerprint.php` with the `canvas_hash` value.
3. THE frontend fingerprint collection SHALL execute once per login session and SHALL NOT repeat on subsequent page navigations within the same session.
4. IF the fingerprint submission request fails, THE frontend application SHALL log the error to the console and continue normal operation without blocking the user.

---

### Requirement 10: Reconciliation Cron Job Alerting

**User Story:** As a platform operator, I want the reconciliation cron job to produce machine-readable output, so that external monitoring systems can consume the results and trigger alerts.

#### Acceptance Criteria

1. THE Reconciliation_Job SHALL write a JSON summary file to `backend/logs/reconciliation_latest.json` after each run, containing: `{ "status": "ok" | "fail", "sum_user_balances": number, "sum_credits": number, "sum_debits": number, "expected_balance": number, "discrepancy": number, "per_user_mismatches": int, "checked_at": "ISO timestamp" }`.
2. WHEN the Reconciliation_Job detects a global discrepancy, THE Reconciliation_Job SHALL set `status` to `"fail"` in the JSON summary.
3. WHEN the Reconciliation_Job detects per-user mismatches, THE Reconciliation_Job SHALL set `status` to `"fail"` and include the count of mismatched users in `per_user_mismatches`.
4. WHEN the Reconciliation_Job completes with no discrepancies, THE Reconciliation_Job SHALL set `status` to `"ok"`.
5. THE Reconciliation_Job SHALL exit with code 0 on success and code 1 on any detected discrepancy, enabling cron-based alerting via exit code monitoring.

---

### Requirement 11: Health Check Integration in Admin Panel

**User Story:** As an admin, I want to see the platform health status in the admin panel, so that I can verify monetary invariants without using CLI tools.

#### Acceptance Criteria

1. THE Finance_Dashboard page SHALL include a "Platform Health" section that displays the results of the Health_Check endpoint.
2. WHEN the Finance_Dashboard loads, THE Finance_Dashboard SHALL fetch data from `/backend/api/admin/health_check.php` and display the status of each Monetary_Invariant check (No Money Created, No Money Lost, Everything Traceable) with a pass/fail indicator.
3. WHEN all three checks pass, THE Finance_Dashboard SHALL display a green "All Systems OK" status badge.
4. WHEN any check fails, THE Finance_Dashboard SHALL display a red "Integrity Issue Detected" status badge with details of the failing check.
5. THE Finance_Dashboard SHALL provide a "Re-check" button that re-fetches the health check data on demand.
