# ANORA Platform Lifecycle — v1.8.4739

> Domain: anora.bet | Stack: PHP 8.4 + MySQL 8 (InnoDB) + React + Vite

---

## 1. Architecture Overview

```
Frontend (React SPA)
    ↓ REST API (PHP endpoints under /backend/api/)
    ↓ Services Layer (LedgerService, GameEngine, InvoiceService, PayoutService, WebhookHandler)
    ↓ MySQL (InnoDB, append-only ledger)
    ↓ External: NOWPayments API (crypto deposits/withdrawals)
```

- Frontend builds to web root via Vite (`frontend/` → `../`)
- `.htaccess` handles SPA routing — all non-file requests → `index.html`
- Backend is stateless PHP endpoints with session-based auth
- `game_worker.php` — long-running process, drives game state machine
- `bot_runner.php` — cron every 2 seconds, provides liquidity
- `cron/cleanup.php` — periodic cleanup of stale data

---

## 2. Financial System — Append-Only Ledger

All money movement goes through `LedgerService` (`backend/includes/ledger_service.php`).

### Tables
- `ledger_entries` — append-only, source of truth for all balance mutations
- `user_balances` — materialized balance per user, locked via `SELECT ... FOR UPDATE`
- `users.balance` — denormalized cache (2 decimal places), kept in sync

### Rules
- Every financial operation produces a ledger entry with `reference_id` + `reference_type`
- Idempotency: duplicate `(reference_type, reference_id, user_id, type)` returns existing entry
- Debits that would produce negative balance are rejected
- `SYSTEM_USER_ID = 0` receives platform commission and unclaimed referral bonuses

### Ledger Entry Types
| Type | Direction | Source |
|------|-----------|--------|
| `deposit` | credit | Fiat deposit, bot top-up, migration |
| `crypto_deposit` | credit | NOWPayments webhook (confirmed invoice) |
| `bet` | debit | GameEngine::placeBet() |
| `win` | credit | GameEngine::finishRound() |
| `system_fee` | credit | 2% commission → System Account |
| `referral_bonus` | credit | 1% → referrer or System Account |
| `withdrawal` | debit | Fiat withdrawal |
| `crypto_withdrawal` | debit | PayoutService::createPayout() |
| `crypto_withdrawal_refund` | credit | Failed/rejected payout refund |

---

## 3. Game Engine — Server-Side State Machine

`GameEngine` (`backend/includes/game_engine.php`) manages the full round lifecycle.

### State Machine
```
waiting → active → spinning → finished
```

- `waiting` — round open for bets, no countdown yet
- `active` — ≥2 distinct players joined, 30-second countdown running
- `spinning` — countdown expired, winner being selected
- `finished` — payouts distributed, immutable snapshot stored

### Bet Placement (`placeBet`)
1. Rate limit: max 5/sec, 60/min per user
2. Lock `game_rounds` row FOR UPDATE
3. Validate status is `waiting` or `active`
4. Lock `user_balances` FOR UPDATE — check sufficient funds
5. Debit via `LedgerService::addEntry(type='bet')`
6. Insert `game_bets` row with `ledger_entry_id`
7. If ≥2 distinct players and status is `waiting` → transition to `active`

### Winner Selection (`finishRound`)
1. Lock `game_rounds` FOR UPDATE, check `payout_status != 'paid'`
2. Aggregate bets per user, build cumulative weight array
3. Compute combined hash: `SHA-256(server_seed:client_seed1:...:seedN:round_id)`
4. `hashToFloat()` — first 13 hex chars → 52-bit int / (2^52-1)
5. `computeTarget()` — `hashToFloat(hash) * totalWeight`
6. `lowerBound()` — O(log n) binary search on cumulative weights
7. Payout: `winner_net = pot - commission(2%) - referral_bonus(1%)`
8. Lock all involved `user_balances` in sorted order (deadlock prevention)
9. Credit winner, system fee, referral bonus via ledger
10. Store immutable snapshot (`final_bets_snapshot`, `final_combined_hash`, etc.)
11. Set `payout_status='paid'`, generate UUID `payout_id`
12. Retry: up to 3 attempts on deadlock with 50ms/100ms/150ms backoff
13. Create new `waiting` round for same room

### Game Worker (`game_worker.php`)
- Continuous loop, 1-second sleep between iterations
- Processes all 3 rooms (1, 10, 100) each iteration
- `active` rounds with expired countdown → `spinning`
- `spinning` rounds → `finishRound()` → `finished`

---

## 4. Multi-Room Lottery

Three independent rooms: $1, $10, $100.

- `room` column on `game_rounds` and `game_bets`
- Bet amount = room value (fixed)
- Room-scoped API endpoints (`?room=N`)
- Frontend tabs with `key={activeRoom}` for clean remount
- Each room has its own independent state machine cycle

---

## 5. Provably Fair System

Every round is independently verifiable.

### Components
- `server_seed` — 64-char hex, generated at round creation, revealed after finish
- `server_seed_hash` — SHA-256 of server_seed, shown during betting
- `client_seed` — per-bet, auto-generated if missing (4 unsigned 32-bit ints, dash-separated)
- `LOTTERY_HASH_FORMAT` — locked format: `%s:%s:%d` (server_seed:seeds:round_id)

### Verification Endpoint
`GET /backend/api/game/verify.php?game_id=N`

Returns: server_seed, client_seeds, combined_hash, rand_unit, target, cumulative weights, winner index. Supports both `game_rounds` (new) and `lottery_games` (legacy).

---

## 6. Referral System

### Registration
- Referral link: `https://anora.bet/?ref=CODE`
- `ref_code` stored in localStorage with 7-day TTL
- On register: `referred_by` set to referrer's user ID
- `referral_locked` flag set after eligibility confirmed

### Payout (per game)
- 2% → platform commission (System Account)
- 1% → referrer (if eligible) or System Account (if unclaimed)
- `winner_net = pot - commission - referral_bonus`

### Referrer Eligibility (`resolveReferrer`)
- `is_verified = 1`
- `is_banned = 0`
- Account age ≥ 24 hours
- At least one completed deposit
- Locked via `SELECT ... FOR UPDATE` during payout

---

## 7. Crypto Payments (NOWPayments)

### Deposits
1. User creates invoice via `InvoiceService` (min $1, rate limit 5/hour)
2. `NowPaymentsClient` calls `POST /v1/invoice`
3. User pays on NOWPayments hosted page
4. Webhook (`WebhookHandler::handleDeposit`) processes status updates
5. On `finished`: credit balance via ledger, cap at original `price_amount` (overpayment protection)
6. Idempotent: skip if already `confirmed`

### Withdrawals
1. User requests payout via `PayoutService` (min $5, max $10k/day, 3/day)
2. Atomic: debit balance → insert payout → insert transaction
3. If amount > $500: `awaiting_approval` (admin manual review)
4. Otherwise: call `POST /v1/payout`, status → `processing`
5. On API failure: immediate refund within transaction
6. Webhook handles `finished` → `completed`, `failed` → refund

### Webhook Security
- HMAC-SHA512 signature validation
- Recursive key sort → JSON encode → `hash_hmac('sha512', ...)`
- `hash_equals()` for timing-attack safety
- Idempotent processing (terminal state guard)

---

## 8. Bot Liquidity System

`bot_runner.php` — cron every 2 seconds.

- 5 bot users (`@bot.internal`, `is_bot=1`)
- 60% bet chance per tick, force join when <2 players
- Bot types: normal (55%), aggressive (35%), whale (10%)
- Multi-bet: normal=1, aggressive=1-2, whale=2-3
- Activity spike: 10% chance of 2-4x multiplier
- Time boost near countdown end (last 5 seconds)
- Auto top-up when balance < $50, cap at $50,000
- Top-ups via `LedgerService` (auditable)

---

## 9. Nickname System

- Auto-generated at registration: "Adjective Noun" (100×100 pool)
- Unique constraint, collision retry with 2-digit suffix
- 24-hour cooldown on nickname changes
- Used everywhere instead of email (privacy)
- `COALESCE(u.nickname, u.email)` pattern in all queries

---

## 10. Anti-Fraud & Production Hardening

### Race Condition Fix
- `getBalanceForUpdate()` called before `addEntry()` in `placeBet()`
- Same `FOR UPDATE` lock serializes concurrent bets per user

### Win Streak Auto-Flagging
- ≥10 wins in 24h → `fraud_flagged = 1` on user
- Flagged users can still play (monitoring only)
- Admin can clear flag or ban user

### Activity Monitor (Admin)
- Win streak detection (≥10 wins/24h)
- High velocity detection (≥50 bets in 5-min window)
- IP correlation (≥2 users sharing registration_ip)
- Large withdrawal detection (>$1000 in 7 days)

### Rate Limits
| Action | Limit |
|--------|-------|
| Bets per second | 5 |
| Bets per minute | 60 |
| Crypto deposits per hour | 5 |
| Crypto withdrawals per day | 3 |
| Daily withdrawal cap | $10,000 |

---

## 11. Admin Panel

Accessible at `/admin/` with admin session.

### Pages
- Users — list, fraud badge, ban, clear fraud flag
- Transactions — user transaction history
- Withdrawals — fiat withdrawal management
- Lottery Games — round history with payout details, expandable bet view, provably fair data
- System Balance — System Account balance from ledger
- Crypto Invoices — all invoices with status filter
- Crypto Payouts — all payouts with approve/reject for `awaiting_approval`
- Ledger Explorer — paginated, filterable ledger entries
- Activity Monitor — auto-detected suspicious activity flags

---

## 12. Frontend Architecture

- React 18 + React Router v6 + Vite
- Dark theme with CSS variables (`--bg`, `--bg-surface`, `--accent`, etc.)
- Persistent left sidebar (240px) with hamburger toggle on mobile
- Lazy-loaded pages via `React.lazy()`
- `AuthContext` / `AdminContext` for session management
- `useGameMachine` hook maps backend status → UI phases
- Casino-style reel animation: 200-tile strip, winner at index 150, 3-phase easing, velocity-based motion blur
- `SPIN_DURATION=5500ms`, `RESULT_HOLD=2000ms`

---

## 13. Database Tables

### Core
- `users` — accounts, balance cache, referral, nickname, fraud_flagged
- `user_balances` — materialized balance (source of truth for locking)
- `ledger_entries` — append-only financial audit trail

### Game
- `game_rounds` — server-side state machine rounds
- `game_bets` — individual bets with ledger_entry_id
- `lottery_games` — legacy rounds (retained for historical data)
- `lottery_bets` — legacy bets

### Crypto
- `crypto_invoices` — deposit invoices (NOWPayments)
- `crypto_payouts` — withdrawal payouts (NOWPayments)

### System
- `system_balance` — legacy system balance singleton
- `system_transactions` — legacy system transaction log
- `user_transactions` — backward-compatible transaction audit
- `transactions` — fiat deposit/withdrawal records
- `withdrawal_requests` — fiat withdrawal queue
- `registration_attempts` — rate limiting for registration

---

## 14. Cron Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `bot_runner.php` | Every 2 seconds | Bot liquidity |
| `game_worker.php` | Continuous (1s loop) | Game state machine driver |
| `cron/cleanup.php` | Daily | Expire stale crypto invoices, clean old registration attempts |

---

## 15. Security Measures

- Session-based auth with `requireLogin()` / `requireAdmin()` guards
- HMAC-SHA512 webhook signature validation
- `hash_equals()` for timing-attack safe comparison
- `SELECT ... FOR UPDATE` row-level locking for all financial operations
- Idempotent operations via unique constraints and status guards
- Disposable email blocking at registration
- Registration rate limiting
- Bot users blocked from withdrawals
- No email exposure in public endpoints (nicknames only)
- `__DIR__` based paths in all PHP `require_once`
