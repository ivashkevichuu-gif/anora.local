# Design Document: Referral Commission System + Multi-Room Lottery

## Overview

This design extends the existing anora.bet PHP/MySQL/React lottery platform with two interconnected features:

1. **Multi-Room Lottery** — three independent lottery rooms ($1 / $10 / $100), each with its own game lifecycle, pot, and player list. A tabbed React UI allows instant room switching with per-room state machine instances.

2. **Referral & Commission Engine** — every winning payout deducts a 2% platform commission and a 1% referral bonus (paid to the winner's referrer if eligible, otherwise absorbed by the platform). Full idempotency via `payout_id` UUID, deterministic lock ordering, and a 3-retry deadlock strategy.

### Key Design Decisions

- **Room as a first-class column** — `room TINYINT` is added to `lottery_games` and `lottery_bets`. All existing queries are scoped by room. No separate tables per room.
- **Payout engine is a pure PHP function** — `finishGameSafe()` is extended in-place. The payout logic (commission, referral, idempotency) is added as a new inner phase after the winner is selected.
- **Idempotency at two levels** — application-level `payout_status` guard + database-level `UNIQUE` constraints on `system_transactions` and `user_transactions`.
- **Referral eligibility is evaluated at registration time** — `referral_locked` is set once and used as a fast pre-check at payout time; the live row is always re-verified under `FOR UPDATE`.
- **Frontend state machine is per-room** — `useGameMachine` is instantiated once per room tab; switching tabs suspends polling for the old room.

---

## Architecture

### System Layers

```
┌─────────────────────────────────────────────────────────────────┐
│  React 18 + Vite + Tailwind + Framer Motion (frontend/src/)     │
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │  Room $1 Tab │  │ Room $10 Tab │  │ Room $100 Tab│          │
│  │  useGameMachine (IDLE→BETTING→COUNTDOWN→DRAWING→RESULT)      │
│  └──────────────┘  └──────────────┘  └──────────────┘          │
│         │                  │                  │                 │
│  useLottery(room=1)  useLottery(room=10)  useLottery(room=100)  │
│  (polls /status.php?room=N every 1s — only active room)         │
└────────────────────────────┬────────────────────────────────────┘
                             │ HTTP (JSON)
┌────────────────────────────▼────────────────────────────────────┐
│  PHP 8.4 Backend (backend/api/ + backend/includes/)             │
│                                                                 │
│  /lottery/status.php?room=N   → getGameState($pdo, $room, $uid) │
│  /lottery/bet.php             → placeBet($pdo, $uid, $room, $seed)│
│  /auth/register.php           → Registration_Handler            │
│  /admin/system_balance.php    → Admin System Balance API        │
└────────────────────────────┬────────────────────────────────────┘
                             │ PDO / InnoDB
┌────────────────────────────▼────────────────────────────────────┐
│  MySQL InnoDB                                                   │
│                                                                 │
│  lottery_games (+ room, payout_status, payout_id, commission,   │
│                   referral_bonus, winner_net)                   │
│  lottery_bets  (+ room)                                         │
│  users         (+ ref_code, referred_by, referral_earnings,     │
│                   registration_ip, is_banned, referral_locked,  │
│                   referral_snapshot)                            │
│  system_balance (id=1 singleton)                                │
│  system_transactions (append-only)                              │
│  user_transactions   (append-only)                              │
│  registration_attempts (7-day TTL)                              │
└─────────────────────────────────────────────────────────────────┘
```

### Payout Engine Flow

```
finishGameSafe($pdo, $gameId)
  │
  ├─ BEGIN TRANSACTION (READ COMMITTED)
  ├─ SELECT * FROM lottery_games WHERE id=? FOR UPDATE
  ├─ IF payout_status='paid' → ROLLBACK, return cached result
  ├─ SET @payout_id = UUID()
  ├─ pickWeightedWinner() → $winnerId
  │
  ├─ Compute financials:
  │    IF pot >= 0.50:
  │      commission     = GREATEST(ROUND(pot * 0.02, 2), 0.01)
  │      referral_bonus = GREATEST(ROUND(pot * 0.01, 2), 0.01)
  │      winner_net     = pot - commission - referral_bonus
  │      IF winner_net < 0: fallback to full-pot
  │    ELSE:
  │      commission = referral_bonus = 0, winner_net = pot
  │
  ├─ Lock users in deterministic order (ORDER BY id ASC FOR UPDATE)
  ├─ Lock system_balance WHERE id=1 FOR UPDATE
  │
  ├─ UPDATE users SET balance = balance + winner_net WHERE id = winner_id
  ├─ INSERT user_transactions (type='win', payout_id)
  │
  ├─ IF eligible referrer:
  │    UPDATE users SET balance = balance + referral_bonus,
  │                     referral_earnings = referral_earnings + referral_bonus
  │    INSERT user_transactions (type='referral_bonus', payout_id)
  │    INSERT system_transactions (type='commission', amount=commission)
  │    UPDATE system_balance SET balance = balance + commission
  │  ELSE:
  │    INSERT system_transactions (type='commission', amount=commission)
  │    INSERT system_transactions (type='referral_unclaimed', amount=referral_bonus)
  │    UPDATE system_balance SET balance = balance + commission + referral_bonus
  │
  ├─ UPDATE lottery_games SET payout_status='paid', payout_id=@payout_id,
  │       commission=?, referral_bonus=?, winner_net=?, status='finished'
  ├─ COMMIT
  │
  └─ On error 1213/1205: retry up to 3 times with fresh transaction
```

---

## Components and Interfaces

### Backend Components

#### 1. `backend/includes/lottery.php` — Extended

**Modified functions:**

`getOrCreateActiveGame(PDO $pdo, int $room): array`
- Adds `$room` parameter; all queries scoped with `WHERE room = ?`
- Validates `$room IN (1, 10, 100)` or throws `InvalidArgumentException`

`getGameState(PDO $pdo, int $room, ?int $userId): array`
- Adds `$room` parameter; returns `room` field in game object

`placeBet(PDO $pdo, int $userId, int $room, string $clientSeed): array`
- Validates `$room IN (1, 10, 100)`
- Bet amount = `$room` (the room IS the bet step)
- Inserts `room` column into `lottery_bets`
- Inserts `user_transactions` row with `type='bet'`

`finishGameSafe(PDO $pdo, int $gameId): array`
- Extended with payout engine phase (commission + referral + idempotency)
- Retry wrapper: up to 3 attempts on error 1213/1205
- Returns payout result including `commission`, `referral_bonus`, `winner_net`, `payout_id`

**New functions:**

`computePayoutAmounts(float $pot): array`
- Returns `['commission' => float, 'referral_bonus' => float, 'winner_net' => float]`
- Pure function, no DB access — easily unit-testable

`resolveReferrer(PDO $pdo, int $winnerId): ?array`
- Checks `referred_by` on winner row
- If non-null: locks referrer row via `FOR UPDATE`, re-verifies eligibility
- Returns referrer row or null

`getLastFinishedGame(PDO $pdo, int $room): ?array`
- Adds `$room` parameter; scopes to room

#### 2. `backend/api/lottery/status.php` — Modified

- Reads `?room=` query param (default: 1), validates against `[1, 10, 100]`
- Passes `$room` to `getGameState()`
- Returns `room` in game object

#### 3. `backend/api/lottery/bet.php` — Modified

- Reads `room` from JSON body (default: 1), validates
- Passes `$room` to `placeBet()`

#### 4. `backend/api/auth/register.php` — Modified

- Rate limiting: INSERT into `registration_attempts`, COUNT, rollback if > 3
- Disposable email domain check
- `ref_code` generation with 3-retry loop
- Referral code lookup: reads `referral_code` from body, validates eligibility
- Sets `referred_by`, `referral_locked`, `referral_snapshot`, `registration_ip`

#### 5. `backend/api/admin/system_balance.php` — New

- Requires valid admin session
- Returns `system_balance.balance`, aggregated sums, paginated `system_transactions`

#### 6. `backend/cron/cleanup.php` — New

- `DELETE FROM registration_attempts WHERE created_at < NOW() - INTERVAL 7 DAY`
- Logs deleted row count

### Frontend Components

#### 1. `frontend/src/hooks/useLottery.js` — Modified

- Accepts `room` parameter
- Polls `/lottery/status.php?room={room}` every 1s
- `placeBet()` sends `{ room, client_seed }` in body

#### 2. `frontend/src/hooks/useGameMachine.js` — Modified

- Adds `COUNTDOWN` phase between `BETTING` and `DRAWING` (currently `SPINNING`)
- Renamed: `SPINNING` → `DRAWING` to match requirements
- No other logic changes needed

#### 3. `frontend/src/components/lottery/LotteryPanel.jsx` — Modified

- Accepts `room` prop
- Renders room-scoped data from `useLottery(room)`
- Instantiates `useGameMachine` per room

#### 4. `frontend/src/pages/Home.jsx` — Modified

- Renders three room tabs (`$1`, `$10`, `$100`)
- Tracks `activeRoom` state (default: 1)
- Renders `<LotteryPanel room={activeRoom} />`
- On tab switch: resets animation state, switches polling

#### 5. `frontend/src/pages/Account.jsx` — Modified

- Adds referral dashboard section: link, earnings, referred count, copy button
- Adds `user_transactions` history table (paginated)

#### 6. `frontend/src/pages/admin/SystemBalance.jsx` — New

- Displays `system_balance.balance`, commission sum, unclaimed sum
- Paginated `system_transactions` table

#### 7. `frontend/src/components/AdminLayout.jsx` — Modified

- Adds "System Balance" nav entry

#### 8. `frontend/src/api/client.js` — Modified

- `lotteryStatus(room)` → `/lottery/status.php?room=${room}`
- `lotteryBet(room, clientSeed)` → body includes `room`
- `adminSystemBalance(page)` → `/admin/system_balance.php?page=${page}`

#### 9. `frontend/src/App.jsx` — Modified

- Reads `?ref=CODE` on mount, stores to `localStorage` with TTL
- Adds `/admin/system-balance` route

#### 10. `frontend/src/services/authService.js` — Modified

- `register(email, pass, referralCode?)` — passes `referral_code` if present

---

## Data Models

### Modified: `users`

```sql
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS ref_code           VARCHAR(32)    UNIQUE NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS referred_by        INT            NULL,
  ADD COLUMN IF NOT EXISTS referral_earnings  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS registration_ip    VARCHAR(45)    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS is_banned          TINYINT(1)     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS referral_locked    TINYINT(1)     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS referral_snapshot  JSON           DEFAULT NULL,
  ADD CONSTRAINT fk_referred_by FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD INDEX idx_referred_by (referred_by);
```

`referral_locked`: set to `1` at registration when all Eligible_Referrer criteria are met for the referrer. Used as a fast pre-check in the payout engine; the live row is always re-verified under `FOR UPDATE`.

`referral_snapshot`: JSON audit record stored at registration time:
```json
{ "referrer_id": 42, "is_verified": true, "had_deposit": true, "created_at": "2024-01-01T00:00:00Z", "locked_at": "2024-06-01T12:00:00Z" }
```

### Modified: `lottery_games`

```sql
ALTER TABLE lottery_games
  ADD COLUMN IF NOT EXISTS room           TINYINT        NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS payout_status  ENUM('pending','paid') NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS payout_id      VARCHAR(36)    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS commission     DECIMAL(12,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS referral_bonus DECIMAL(12,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS winner_net     DECIMAL(12,2)  DEFAULT NULL,
  ADD INDEX idx_room_status (room, status);
```

### Modified: `lottery_bets`

```sql
ALTER TABLE lottery_bets
  ADD COLUMN IF NOT EXISTS room TINYINT NOT NULL DEFAULT 1;
```

### New: `system_balance`

```sql
CREATE TABLE IF NOT EXISTS system_balance (
  id      INT           NOT NULL DEFAULT 1 PRIMARY KEY,
  balance DECIMAL(15,2) NOT NULL DEFAULT 0.00
);
INSERT IGNORE INTO system_balance (id, balance) VALUES (1, 0.00);
```

Single-row singleton. Application never INSERTs additional rows.

### New: `system_transactions`

```sql
CREATE TABLE IF NOT EXISTS system_transactions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  game_id        INT           NULL,
  payout_id      VARCHAR(36)   DEFAULT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  type           VARCHAR(32)   NOT NULL,  -- 'commission' | 'referral_unclaimed'
  source_user_id INT           NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_payout_type (game_id, payout_id, type),
  INDEX idx_created_at (created_at)
);
```

### New: `user_transactions`

```sql
CREATE TABLE IF NOT EXISTS user_transactions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT           NOT NULL,
  payout_id  VARCHAR(36)   DEFAULT NULL,
  type       VARCHAR(32)   NOT NULL,  -- 'win' | 'referral_bonus' | 'bet'
  amount     DECIMAL(12,2) NOT NULL,
  game_id    INT           NULL,
  note       VARCHAR(255)  DEFAULT NULL,
  created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_user_type (game_id, user_id, type, payout_id),
  INDEX idx_user_created (user_id, created_at)
);
```

### New: `registration_attempts`

```sql
CREATE TABLE IF NOT EXISTS registration_attempts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45) NOT NULL,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_created (ip, created_at)
);
```

7-day retention. Cleaned by `backend/cron/cleanup.php`.

### Frontend State Shape

Per-room state machine state (TypeScript-style for clarity):

```ts
type Phase = 'IDLE' | 'BETTING' | 'COUNTDOWN' | 'DRAWING' | 'RESULT'

interface RoomMachineState {
  phase:  Phase
  bets:   Bet[]
  winner: User | null
  pot:    number
  gameId: number | null
}
```

Valid transitions:
```
IDLE       → BETTING   : backend status is 'waiting' or 'countdown'
BETTING    → COUNTDOWN : backend status changes to 'countdown'
COUNTDOWN  → DRAWING   : backend status changes to 'finished'
DRAWING    → RESULT    : setTimeout fires after SPIN_DURATION + RESULT_HOLD
RESULT     → BETTING   : backend reports new game_id for this room
```

### Referral localStorage Schema

```ts
interface AnoraRef {
  code:    string   // 12-char uppercase hex
  expires: number   // Date.now() + 7*24*60*60*1000
}
// stored under key: 'anora_ref'
```

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Financial Invariant

*For any* lottery game with `pot >= 0.50`, after the payout engine completes: `winner_net + commission + referral_bonus = pot` exactly (computed by subtraction, not re-summed). All three values must be non-negative.

**Validates: Requirements 1.1, 3.1**

---

### Property 2: Micro-Pot Exception

*For any* lottery game with `pot < 0.50`, after the payout engine completes: `commission = 0`, `referral_bonus = 0`, and the winner receives the full `pot`.

**Validates: Requirements 1.2**

---

### Property 3: System Balance Monotonicity and Non-Negativity

*For any* sequence of payout operations, `system_balance.balance` must be `>= 0.00` after every operation, and must increase by exactly `commission` (when referrer is eligible) or `commission + referral_bonus` (when no eligible referrer) per payout.

**Validates: Requirements 1.4, 1.5, 1.8**

---

### Property 4: Payout Audit Trail Completeness

*For any* completed payout with `commission > 0` and an eligible referrer: exactly one `system_transactions` row with `type='commission'` exists for that `(game_id, payout_id)`. For any completed payout with no eligible referrer: exactly two rows exist — one `type='commission'` and one `type='referral_unclaimed'`.

**Validates: Requirements 1.6, 2.3, 2.4**

---

### Property 5: Referrer Credit and Earnings

*For any* completed payout where the winner has an eligible referrer: the referrer's `balance` increases by exactly `referral_bonus`, and `referral_earnings` increases by exactly `referral_bonus`, in the same transaction.

**Validates: Requirements 2.1, 2.2**

---

### Property 6: Payout Idempotency

*For any* game with `payout_status = 'paid'`, calling the payout engine again must return the same `(winner_net, commission, referral_bonus, payout_id)` without modifying any balances, `system_balance`, or inserting any new log rows.

**Validates: Requirements 3.7, 4.2**

---

### Property 7: Payout ID Propagation

*For any* completed payout, all `user_transactions` rows and all `system_transactions` rows created during that payout must carry the same non-null `payout_id` that is stored on `lottery_games.payout_id`.

**Validates: Requirements 4.3, 4.6**

---

### Property 8: Game Financial Snapshot Consistency

*For any* finished game, the values stored in `lottery_games.commission`, `lottery_games.referral_bonus`, and `lottery_games.winner_net` must exactly match what was credited to the respective balances in the same transaction.

**Validates: Requirements 16.1, 16.2**

---

### Property 9: Room Independence

*For any* two distinct rooms R1 and R2, a bet placed in R1 must not change the `total_pot`, `winner_id`, or bet list of any active game in R2.

**Validates: Requirements 5.2, 5.3**

---

### Property 10: Room API Scoping

*For any* valid room value in `{1, 10, 100}`, `GET /lottery/status.php?room=N` must return a game object where `game.room = N`. For any invalid room value, the endpoint must return HTTP 400.

**Validates: Requirements 6.1, 6.3**

---

### Property 11: Bet Amount Equals Room Bet Step

*For any* bet placed in room R, the amount deducted from the user's balance must equal exactly R (the room's bet step), and the `lottery_bets.amount` must equal R.

**Validates: Requirements 6.2, 6.4**

---

### Property 12: State Machine Valid Transitions Only

*For any* room state machine, only the transitions defined in the valid transition table are allowed. Specifically: `IDLE → DRAWING`, `BETTING → RESULT`, `DRAWING → BETTING`, and `RESULT → DRAWING` must never occur.

**Validates: Requirements 8.2, 8.3**

---

### Property 13: DRAWING Phase Locks Betting and Persists for Minimum Duration

*For any* room in `DRAWING` or `RESULT` phase, the "Place Bet" button must be disabled, and the `RESULT` phase must persist for at least `SPIN_DURATION (5500ms) + RESULT_HOLD (2000ms)` before transitioning to `BETTING` on a new `game_id`.

**Validates: Requirements 8.4, 8.7, 9.1, 9.2, 9.4**

---

### Property 14: ref_code Format

*For any* newly registered user (including bots), `ref_code` must be a 12-character uppercase hexadecimal string matching `/^[0-9A-F]{12}$/`.

**Validates: Requirements 10.1, 10.4**

---

### Property 15: Referral TTL Enforcement

*For any* `anora_ref` entry in `localStorage` where `expires < Date.now()`, reading the entry must return null and delete the key. For any non-expired entry, reading must return the stored code.

**Validates: Requirements 11.2**

---

### Property 16: Referral Eligibility Enforcement

*For any* registration attempt where the referrer fails any Eligible_Referrer criterion (not verified, banned, account < 24h old, no completed deposit, or same IP), `referred_by` must be set to `NULL` and registration must complete without error.

**Validates: Requirements 11.5, 11.6, 12.3, 12.4, 12.5, 12.6**

---

### Property 17: referred_by Immutability

*For any* existing user record, no API endpoint must allow updating the `referred_by` field after account creation.

**Validates: Requirements 12.1, 20.5**

---

### Property 18: Registration Rate Limiting

*For any* IP address that has made more than 3 registration attempts in the last hour, the next registration attempt must return HTTP 429 and must not create a user account.

**Validates: Requirements 13.1**

---

### Property 19: User Transaction Audit Trail

*For any* completed payout: a `user_transactions` row with `type='win'` exists for the winner, and if an eligible referrer exists, a row with `type='referral_bonus'` exists for the referrer. For any bet placement, a row with `type='bet'` exists for the bettor. All rows are append-only.

**Validates: Requirements 15.1, 15.2, 15.3, 15.4**

---

### Property 20: Disposable Email Rejection

*For any* registration attempt with an email domain in the blocklist (`mailinator.com`, `guerrillamail.com`, `tempmail.com`, `throwaway.email`, `yopmail.com`, `sharklasers.com`, `trashmail.com`, `maildrop.cc`, `dispostable.com`, `fakeinbox.com`), the registration must be rejected with an error and no user account must be created.

**Validates: Requirements 20.1**

---

### Property 21: Registration Attempts Cleanup

*For any* `registration_attempts` row with `created_at < NOW() - INTERVAL 7 DAY`, after the cleanup script runs, that row must no longer exist. Rows within the 7-day window must be preserved.

**Validates: Requirements 19.1**

---

## Error Handling

### Payout Engine

| Condition | Behavior |
|---|---|
| `payout_status = 'paid'` | Abort, return cached result, no error |
| `winner_net < 0` | Fallback to full-pot payout, log `[Payout] CRITICAL: winner_net < 0 for game {id}` |
| `pot < 0`, `commission < 0`, or `referral_bonus < 0` | Rollback, log critical error, surface exception |
| MySQL error 1213 (deadlock) or 1205 (lock wait timeout) | Retry up to 3 times with fresh transaction |
| 3 retries exhausted | Log `[Payout] FATAL: 3 retries exhausted for game {id}`, surface error |
| Unique constraint violation on log insert | Treat as already-paid, rollback, log warning |
| Referrer row deleted between pre-check and lock | Treat as unclaimed, continue payout |
| Referrer `is_banned = 1` | Treat as unclaimed, log `[Referral] Banned referrer {id} — bonus unclaimed for game {id}` |

### Registration Handler

| Condition | Behavior |
|---|---|
| Rate limit exceeded (> 3/hour/IP) | HTTP 429, rollback `registration_attempts` INSERT |
| Disposable email domain | HTTP 400, `"Email domain not allowed."` |
| `ref_code` DUPLICATE KEY (all 3 retries) | HTTP 500, log error |
| Referral code not found or ineligible | `referred_by = NULL`, registration continues |
| Same-IP referral | `referred_by = NULL`, log `[Referral] Same-IP block: referrer_id={id} ip={ip}` |
| Invalid email format | HTTP 400 |
| Password < 6 chars | HTTP 400 |
| Email already registered | HTTP 400 |

### Multi-Room API

| Condition | Behavior |
|---|---|
| Invalid `room` value | HTTP 400, `"Invalid room. Must be 1, 10, or 100."` |
| Insufficient balance for room bet step | HTTP 400, `"Insufficient balance."` |
| Bet during `finished` status | HTTP 400, `"This round has already finished."` |

### Frontend

| Condition | Behavior |
|---|---|
| Network error during poll | Silent — last known state preserved, retry on next interval |
| Bet API error | Display `betError` message below bet button |
| Expired `anora_ref` in localStorage | Delete key, treat as no referral |
| Room switch during `DRAWING` | Suspend animation, switch room; returning shows `RESULT` if still in hold window |

---

## Testing Strategy

### Dual Testing Approach

Both unit tests and property-based tests are required. Unit tests cover specific examples, integration points, and edge cases. Property tests verify universal correctness across randomized inputs.

### Property-Based Testing

**Library:** PHP — [eris](https://github.com/giorgiosironi/eris) (PHPUnit extension) or [QuickCheck for PHP](https://github.com/steos/php-quickcheck). Frontend — [fast-check](https://github.com/dubzzz/fast-check) (npm).

**Configuration:** Minimum 100 iterations per property test.

**Tag format:** `// Feature: referral-commission-system, Property {N}: {property_text}`

Each correctness property maps to exactly one property-based test:

| Property | Test Description | Library |
|---|---|---|
| P1: Financial Invariant | Generate random `pot >= 0.50`, verify `winner_net + commission + referral_bonus = pot` | eris/PHP |
| P2: Micro-Pot Exception | Generate random `pot` in `[0.01, 0.49]`, verify commission=0, referral_bonus=0 | eris/PHP |
| P3: System Balance Monotonicity | Generate random payout sequences, verify balance never goes negative | eris/PHP |
| P4: Payout Audit Trail | Generate random payout scenarios (with/without referrer), verify correct log row counts | eris/PHP |
| P5: Referrer Credit | Generate random pots with eligible referrers, verify balance and earnings deltas | eris/PHP |
| P6: Payout Idempotency | Generate any completed game, call payout engine twice, verify no second-call side effects | eris/PHP |
| P7: Payout ID Propagation | Generate any payout, verify all log rows share the same payout_id | eris/PHP |
| P8: Snapshot Consistency | Generate any payout, verify stored snapshot matches credited amounts | eris/PHP |
| P9: Room Independence | Generate random bets across two rooms, verify no cross-room contamination | eris/PHP |
| P10: Room API Scoping | Generate random room values, verify status endpoint returns correct room or 400 | eris/PHP |
| P11: Bet Amount = Room Step | Generate random valid rooms and users, verify deducted amount = room | eris/PHP |
| P12: State Machine Transitions | Generate random backend event sequences, verify only valid transitions occur | fast-check/JS |
| P13: DRAWING Phase Duration | Generate random phase transitions, verify RESULT persists >= 7500ms | fast-check/JS |
| P14: ref_code Format | Generate random registrations, verify ref_code matches `/^[0-9A-F]{12}$/` | eris/PHP |
| P15: Referral TTL | Generate random timestamps and TTL values, verify expired entries are deleted | fast-check/JS |
| P16: Referral Eligibility | Generate random referrer states (banned, unverified, etc.), verify referred_by=NULL | eris/PHP |
| P17: referred_by Immutability | Generate any user, attempt update via any API endpoint, verify field unchanged | eris/PHP |
| P18: Rate Limiting | Generate IPs with > 3 attempts in last hour, verify HTTP 429 | eris/PHP |
| P19: Audit Trail | Generate random payouts and bets, verify correct user_transactions rows | eris/PHP |
| P20: Disposable Email | Generate emails with blocked domains, verify all rejected | eris/PHP |
| P21: Cleanup Retention | Generate rows with mixed ages, run cleanup, verify only old rows deleted | eris/PHP |

### Unit Tests

Unit tests focus on specific examples, edge cases, and integration points. Avoid duplicating what property tests already cover.

**Key unit test cases:**

- `computePayoutAmounts(0.51)` → commission=0.01, referral_bonus=0.01, winner_net=0.49 (minimum threshold edge case)
- `computePayoutAmounts(0.49)` → commission=0, referral_bonus=0, winner_net=0.49 (micro-pot)
- `computePayoutAmounts(100.00)` → commission=2.00, referral_bonus=1.00, winner_net=97.00
- Payout with banned referrer → unclaimed path taken, two system_transactions rows
- Registration with all 3 `ref_code` retries failing → HTTP 500
- Registration rate limit: exactly 3 attempts → allowed; 4th → HTTP 429
- Bot user registration → exempt from rate limiting, receives ref_code
- `anora_ref` localStorage: expired entry → deleted on read
- Room tab switch during DRAWING → previous room machine suspended
- Admin system balance page: unauthenticated request → redirect to login
- `finishGameSafe` with zero bets → no financial entries created
- Deadlock retry: mock 2 deadlocks then success → payout completes on 3rd attempt

### Integration Tests

- Full payout flow: place bets in room $10, trigger finish, verify winner balance, referrer balance, system_balance, all log rows, and game snapshot in one test
- Registration with referral: visit `/?ref=CODE`, register, verify `referred_by` set, `anora_ref` cleared from localStorage
- Multi-room isolation: concurrent bets in rooms $1 and $100, verify independent game states
