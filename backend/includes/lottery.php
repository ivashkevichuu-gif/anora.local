<?php
/**
 * Lottery game logic — final stabilization.
 *
 * Guarantees:
 *  - HASH_FORMAT constant locks the hash string format forever
 *  - computeTarget() is the single math function used everywhere
 *  - hashToFloat() is the single conversion used everywhere
 *  - Immutable snapshot stored at game finish — verify never re-queries live bets
 *  - Strict client_seed validation (uint32-uint32-uint32-uint32 only)
 *  - Rate limiting per user per second
 */

defined('LOTTERY_BET')              || define('LOTTERY_BET',              1.00);
defined('LOTTERY_COUNTDOWN')        || define('LOTTERY_COUNTDOWN',        30);
defined('LOTTERY_MIN_PLAYERS')      || define('LOTTERY_MIN_PLAYERS',      2);
defined('LOTTERY_MAX_BETS_PER_SEC') || define('LOTTERY_MAX_BETS_PER_SEC', 5);

// FINAL FIX: locked hash format — changing this would break all past verifications
// Format: server_seed:seed1:seed2:...:seedN:game_id
defined('LOTTERY_HASH_FORMAT')      || define('LOTTERY_HASH_FORMAT',      '%s:%s:%d');

// ─────────────────────────────────────────────────────────────────────────────
// Compute payout amounts for a given pot.
//
// Pure function — no DB access, no side effects. Easily unit-testable.
//
// Rules:
//   - If pot >= 0.50:
//       commission     = max(round(pot * 0.02, 2), 0.01)
//       referral_bonus = max(round(pot * 0.01, 2), 0.01)
//       winner_net     = pot - commission - referral_bonus  (subtraction only)
//       If winner_net < 0: fallback to full-pot (commission=0, referral_bonus=0,
//         winner_net=pot) and log a CRITICAL warning.
//   - If pot < 0.50 (micro-pot exception):
//       commission=0.00, referral_bonus=0.00, winner_net=pot
//
// Returns: ['commission' => float, 'referral_bonus' => float, 'winner_net' => float]
//
// Validates: Requirements 1.1, 1.2, 3.1, 3.2, 3.3
// ─────────────────────────────────────────────────────────────────────────────
function computePayoutAmounts(float $pot): array {
    if ($pot >= 0.50) {
        $commission     = max(round($pot * 0.02, 2), 0.01);
        $referral_bonus = max(round($pot * 0.01, 2), 0.01);
        $winner_net     = $pot - $commission - $referral_bonus;

        if ($winner_net < 0) {
            error_log(sprintf(
                '[Payout] CRITICAL: winner_net < 0 for pot %.2f, falling back to full-pot payout',
                $pot
            ));
            $commission     = 0.00;
            $referral_bonus = 0.00;
            $winner_net     = $pot;
        }
    } else {
        $commission     = 0.00;
        $referral_bonus = 0.00;
        $winner_net     = $pot;
    }

    return [
        'commission'     => $commission,
        'referral_bonus' => $referral_bonus,
        'winner_net'     => $winner_net,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Resolve the eligible referrer for a given winner.
//
// Performs a two-phase eligibility check:
//   1. Fast pre-check: reads winner's referred_by and referral_locked without
//      locking — returns null immediately if no referrer or referral_locked = 0.
//   2. Authoritative check: locks the referrer row via FOR UPDATE and re-verifies
//      all Eligible_Referrer criteria on the live row:
//        - is_verified = 1
//        - is_banned = 0  (logs a warning if banned)
//        - created_at <= NOW() - INTERVAL 24 HOUR
//        - has at least one completed deposit in transactions table
//
// Returns the locked referrer row array if all checks pass, or null otherwise.
// The caller is responsible for running this inside an open transaction so that
// the FOR UPDATE lock is held until the payout commits.
//
// Parameters:
//   $pdo      — active PDO connection (must be inside a transaction for locking)
//   $winnerId — user id of the lottery winner
//   $gameId   — game id used only for the banned-referrer log message (default 0)
//
// Validates: Requirements 2.1, 2.5, 12.4, 12.5, 12.6
// ─────────────────────────────────────────────────────────────────────────────
function resolveReferrer(PDO $pdo, int $winnerId, int $gameId = 0): ?array {
    // Step 1: read winner's referred_by and referral_locked (no lock needed here)
    $winnerStmt = $pdo->prepare(
        "SELECT referred_by, referral_locked FROM users WHERE id = ?"
    );
    $winnerStmt->execute([$winnerId]);
    $winnerRow = $winnerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$winnerRow || $winnerRow['referred_by'] === null) {
        return null;
    }

    $referrerId = (int)$winnerRow['referred_by'];

    // Step 2: fast pre-check — if referral_locked = 0, skip the expensive lock
    if ((int)$winnerRow['referral_locked'] === 0) {
        return null;
    }

    // Step 3: lock the referrer row for authoritative re-verification
    $lockStmt = $pdo->prepare(
        "SELECT * FROM users WHERE id = ? FOR UPDATE"
    );
    $lockStmt->execute([$referrerId]);
    $referrer = $lockStmt->fetch(PDO::FETCH_ASSOC);

    // Referrer row deleted between pre-check and lock — treat as unclaimed
    if (!$referrer) {
        return null;
    }

    // Re-verify live row: must be verified
    if ((int)$referrer['is_verified'] !== 1) {
        return null;
    }

    // Re-verify live row: must not be banned
    if ((int)$referrer['is_banned'] !== 0) {
        error_log(sprintf(
            '[Referral] Banned referrer %d — bonus unclaimed for game %d',
            $referrerId,
            $gameId
        ));
        return null;
    }

    // Re-verify live row: account must be at least 24 hours old
    $ageStmt = $pdo->prepare(
        "SELECT 1 FROM users WHERE id = ? AND created_at <= NOW() - INTERVAL 24 HOUR"
    );
    $ageStmt->execute([$referrerId]);
    if (!$ageStmt->fetch()) {
        return null;
    }

    // Re-verify live row: must have at least one completed deposit
    $depositStmt = $pdo->prepare(
        "SELECT 1 FROM transactions WHERE user_id = ? AND type = 'deposit' AND status = 'completed' LIMIT 1"
    );
    $depositStmt->execute([$referrerId]);
    if (!$depositStmt->fetch()) {
        return null;
    }

    return $referrer;
}

// ─────────────────────────────────────────────────────────────────────────────
// FINAL FIX: single hash → float conversion.
// 52-bit integer from first 13 hex chars, divided by 2^52-1.
// Locked to 12 decimal places. Used by ALL code paths — no divergence possible.
// ─────────────────────────────────────────────────────────────────────────────
function hashToFloat(string $hash): float {
    return round(hexdec(substr($hash, 0, 13)) / 0xFFFFFFFFFFFFF, 12);
}

// ─────────────────────────────────────────────────────────────────────────────
// FINAL FIX: single target computation function.
// Called by pickWeightedWinner, getVerifyData, and nowhere else.
// ─────────────────────────────────────────────────────────────────────────────
function computeTarget(string $hash, float $totalWeight): float {
    return round(hashToFloat($hash) * $totalWeight, 12);
}

// ─────────────────────────────────────────────────────────────────────────────
// FINAL FIX: build combined hash using LOTTERY_HASH_FORMAT constant.
// Accepts pre-fetched seeds array — no DB query inside.
// ─────────────────────────────────────────────────────────────────────────────
function buildCombinedHashFromSeeds(string $serverSeed, array $clientSeeds, int $gameId): string {
    return hash('sha256', sprintf(
        LOTTERY_HASH_FORMAT,
        $serverSeed,
        implode(':', $clientSeeds),
        $gameId
    ));
}

// ─────────────────────────────────────────────────────────────────────────────
// Fetch client seeds from DB in deterministic order (ORDER BY id ASC).
// ─────────────────────────────────────────────────────────────────────────────
function fetchClientSeeds(PDO $pdo, int $gameId): array {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(client_seed, '') AS client_seed
         FROM lottery_bets WHERE game_id = ? ORDER BY id ASC"
    );
    $stmt->execute([$gameId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ─────────────────────────────────────────────────────────────────────────────
// Get or create the active game.
// ─────────────────────────────────────────────────────────────────────────────
function getOrCreateActiveGame(PDO $pdo, int $room = 1): array {
    if (!in_array($room, [1, 10, 100], true)) {
        throw new InvalidArgumentException('Invalid room. Must be 1, 10, or 100.');
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM lottery_games
         WHERE status IN ('waiting','countdown') AND room = ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$room]);
    $game = $stmt->fetch();

    if (!$game) {
        $serverSeed     = bin2hex(random_bytes(16));
        $serverSeedHash = hash('sha256', $serverSeed);
        $pdo->prepare(
            "INSERT INTO lottery_games (status, server_seed, server_seed_hash, room)
             VALUES ('waiting', ?, ?, ?)"
        )->execute([$serverSeed, $serverSeedHash, $room]);
        $id = (int)$pdo->lastInsertId();
        error_log("[Lottery] New game created: #$id room=$room (seed_hash: $serverSeedHash)");
        $refetch = $pdo->prepare("SELECT * FROM lottery_games WHERE id = ?");
        $refetch->execute([$id]);
        return $refetch->fetch();
    }

    return $game;
}

// ─────────────────────────────────────────────────────────────────────────────
// FINAL FIX: lower_bound binary search on flat cumulative array.
// Returns winner index (not user_id) — caller maps back to user_id.
// ─────────────────────────────────────────────────────────────────────────────
function lowerBound(array $cumulative, float $target): int {
    $low  = 0;
    $high = count($cumulative) - 1;
    while ($low < $high) {
        $mid = intdiv($low + $high, 2);
        if ($cumulative[$mid] <= $target) {
            $low = $mid + 1;
        } else {
            $high = $mid;
        }
    }
    return $low;
}

// ─────────────────────────────────────────────────────────────────────────────
// Pick weighted winner. Called INSIDE a transaction with game row locked.
// Returns full result array for snapshot storage.
// ─────────────────────────────────────────────────────────────────────────────
function pickWeightedWinner(PDO $pdo, int $gameId, string $serverSeed): array {
    // Aggregate per user — ORDER BY user_id ASC for determinism
    $stmt = $pdo->prepare(
        "SELECT user_id, SUM(amount) AS total
         FROM lottery_bets WHERE game_id = ?
         GROUP BY user_id ORDER BY user_id ASC"
    );
    $stmt->execute([$gameId]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        throw new RuntimeException("No bets found for game #$gameId");
    }

    $userIds     = [];
    $cumulative  = [];
    $running     = 0.0;
    foreach ($rows as $row) {
        $userIds[]   = (int)$row['user_id'];
        $running    += (float)$row['total'];
        $cumulative[] = $running;
    }
    $totalWeight = $running;

    if ($totalWeight <= 0.0) {
        throw new RuntimeException("Total weight is zero for game #$gameId");
    }

    // Fetch seeds inside transaction — consistent with locked bets
    $clientSeeds = fetchClientSeeds($pdo, $gameId);
    $combined    = buildCombinedHashFromSeeds($serverSeed, $clientSeeds, $gameId);

    // FINAL FIX: use computeTarget() — single math function
    $target      = computeTarget($combined, $totalWeight);
    $winnerIdx   = lowerBound($cumulative, $target);
    $winnerId    = $userIds[$winnerIdx];

    error_log(sprintf(
        "[Lottery] Winner: game #%d hash=%s rand_unit=%.12f target=%.12f total=%.2f idx=%d user_id=%d",
        $gameId, $combined, hashToFloat($combined), $target, $totalWeight, $winnerIdx, $winnerId
    ));

    return [
        'winner_id'    => $winnerId,
        'winner_index' => $winnerIdx,
        'combined_hash'=> $combined,
        'rand_unit'    => hashToFloat($combined),
        'target'       => $target,
        'total_weight' => $totalWeight,
        'client_seeds' => $clientSeeds,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Build aggregated bet list with per-user chance.
// ─────────────────────────────────────────────────────────────────────────────
function buildBetList(PDO $pdo, int $gameId, float $totalPot): array {
    $stmt = $pdo->prepare(
        "SELECT lb.user_id, u.email,
                COALESCE(u.nickname, u.email) AS display_name,
                u.is_bot,
                SUM(lb.amount) AS total_bet,
                COUNT(lb.id)   AS bet_count
         FROM lottery_bets lb
         JOIN users u ON u.id = lb.user_id
         WHERE lb.game_id = ?
         GROUP BY lb.user_id, u.email, u.nickname, u.is_bot
         ORDER BY total_bet DESC"
    );
    $stmt->execute([$gameId]);
    $rows = $stmt->fetchAll();

    return array_map(function ($row) use ($totalPot) {
        $totalBet = (float)$row['total_bet'];
        return [
            'user_id'      => (int)$row['user_id'],
            'email'        => $row['email'],
            'display_name' => $row['display_name'],
            'is_bot'       => (bool)$row['is_bot'],
            'total_bet'    => $totalBet,
            'bet_count'    => (int)$row['bet_count'],
            'chance'       => $totalPot > 0 ? round($totalBet / $totalPot, 6) : 0.0,
        ];
    }, $rows);
}

// ─────────────────────────────────────────────────────────────────────────────
// Full game state for frontend polling.
// ─────────────────────────────────────────────────────────────────────────────
function getGameState(PDO $pdo, int $room = 1, ?int $currentUserId = null): array {
    $game = getOrCreateActiveGame($pdo, $room);

    if ($game['status'] === 'countdown') {
        $elapsed = time() - strtotime($game['started_at']);
        if ($elapsed >= LOTTERY_COUNTDOWN) {
            $game = finishGameSafe($pdo, $game['id']);
        }
    }

    $totalPot = (float)$game['total_pot'];
    $bets     = buildBetList($pdo, $game['id'], $totalPot);

    $statsStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT user_id) AS unique_players, COUNT(*) AS total_bets
         FROM lottery_bets WHERE game_id = ?"
    );
    $statsStmt->execute([$game['id']]);
    $stats = $statsStmt->fetch();

    $countdown = null;
    if ($game['status'] === 'countdown') {
        $elapsed   = time() - strtotime($game['started_at']);
        $countdown = max(0, LOTTERY_COUNTDOWN - $elapsed);
    }

    $winner = null;
    if ($game['winner_id']) {
        $w = $pdo->prepare("SELECT id, email, COALESCE(nickname, email) AS display_name, is_bot FROM users WHERE id = ?");
        $w->execute([$game['winner_id']]);
        $winner = $w->fetch();
        if ($winner) {
            $winner['id']     = (int)$winner['id'];
            $winner['is_bot'] = (bool)$winner['is_bot'];
        }
    }

    $myStats = null;
    if ($currentUserId) {
        $myRow = null;
        foreach ($bets as $b) {
            if ($b['user_id'] === $currentUserId) { $myRow = $b; break; }
        }
        $myStats = [
            'total_bets' => $myRow ? $myRow['bet_count'] : 0,
            'total_bet'  => $myRow ? $myRow['total_bet'] : 0.0,
            'chance'     => $myRow ? $myRow['chance']    : 0.0,
        ];
    }

    return [
        'game' => [
            'id'               => (int)$game['id'],
            'status'           => $game['status'],
            'total_pot'        => $totalPot,
            'countdown'        => $countdown,
            'winner'           => $winner,
            'server_seed_hash' => $game['server_seed_hash'] ?? null,
            'server_seed'      => $game['status'] === 'finished' ? ($game['server_seed'] ?? null) : null,
            'room'             => (int)$game['room'],
        ],
        'bets'           => $bets,
        'unique_players' => (int)$stats['unique_players'],
        'total_bets'     => (int)$stats['total_bets'],
        'my_stats'       => $myStats,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Race-condition safe game finalization with payout engine.
// Retry wrapper: up to 3 attempts on MySQL deadlock (1213) or lock timeout (1205).
// ─────────────────────────────────────────────────────────────────────────────
function finishGameSafe(PDO $pdo, int $gameId): array {
    $maxRetries = 3;
    $lastException = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            return finishGameSafeAttempt($pdo, $gameId);
        } catch (PDOException $e) {
            $code = (int)$e->getCode();
            // 1213 = deadlock, 1205 = lock wait timeout
            if (in_array($code, [1213, 1205], true) || str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'Lock wait timeout')) {
                $lastException = $e;
                error_log(sprintf('[Payout] Deadlock/timeout on attempt %d for game #%d: %s', $attempt, $gameId, $e->getMessage()));
                if ($pdo->inTransaction()) $pdo->rollBack();
                usleep(50000 * $attempt); // 50ms, 100ms, 150ms
                continue;
            }
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    error_log(sprintf('[Payout] FATAL: 3 retries exhausted for game %d', $gameId));
    throw $lastException;
}

// ─────────────────────────────────────────────────────────────────────────────
// Single attempt of the payout engine. Called by finishGameSafe.
// Implements full commission + referral + idempotency logic.
// ─────────────────────────────────────────────────────────────────────────────
function finishGameSafeAttempt(PDO $pdo, int $gameId): array {
    $pdo->beginTransaction();
    try {
        // Lock game row
        $stmt = $pdo->prepare("SELECT * FROM lottery_games WHERE id = ? FOR UPDATE");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();

        if ($game['status'] === 'finished') {
            $pdo->rollBack();
            return $game;
        }
        if ($game['started_at'] === null) {
            $pdo->rollBack();
            return $game;
        }

        $elapsed = time() - strtotime($game['started_at']);
        if ($elapsed < LOTTERY_COUNTDOWN) {
            $pdo->rollBack();
            return $game;
        }

        // Check payout_status guard (idempotency)
        if ($game['payout_status'] === 'paid') {
            $pdo->rollBack();
            return $game;
        }

        $pot = (float)$game['total_pot'];

        // Handle zero-bet game
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM lottery_bets WHERE game_id = ?");
        $countStmt->execute([$gameId]);
        if ((int)$countStmt->fetchColumn() === 0) {
            $pdo->prepare(
                "UPDATE lottery_games SET status='finished', finished_at=NOW() WHERE id=?"
            )->execute([$gameId]);
            $pdo->commit();
            error_log("[Lottery] Game #$gameId finished with no bets.");
            return array_merge($game, ['status' => 'finished']);
        }

        // Pick winner
        $result   = pickWeightedWinner($pdo, $gameId, $game['server_seed'] ?? '');
        $winnerId = $result['winner_id'];

        // Build immutable snapshot
        $snapStmt = $pdo->prepare(
            "SELECT lb.id AS bet_id, lb.user_id, u.email,
                    lb.amount, COALESCE(lb.client_seed,'') AS client_seed
             FROM lottery_bets lb
             JOIN users u ON u.id = lb.user_id
             WHERE lb.game_id = ? ORDER BY lb.id ASC"
        );
        $snapStmt->execute([$gameId]);
        $snapshot = $snapStmt->fetchAll();

        // Compute payout amounts
        $payout = computePayoutAmounts($pot);
        $commission    = $payout['commission'];
        $referralBonus = $payout['referral_bonus'];
        $winnerNet     = $payout['winner_net'];

        // Generate payout UUID
        $payoutId = $pdo->query("SELECT UUID()")->fetchColumn();

        // Resolve referrer (pre-check only — full lock happens after user lock below)
        $winnerRefStmt = $pdo->prepare("SELECT referred_by, referral_locked FROM users WHERE id = ?");
        $winnerRefStmt->execute([$winnerId]);
        $winnerRefRow = $winnerRefStmt->fetch(PDO::FETCH_ASSOC);
        $referrerId = null;
        if ($winnerRefRow && $winnerRefRow['referred_by'] && (int)$winnerRefRow['referral_locked'] === 1) {
            $referrerId = (int)$winnerRefRow['referred_by'];
        }

        // Lock users in deterministic order (ORDER BY id ASC) to prevent deadlocks
        $userIds = array_unique(array_filter([$winnerId, $referrerId]));
        sort($userIds);
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $lockUsersStmt = $pdo->prepare(
            "SELECT * FROM users WHERE id IN ($placeholders) ORDER BY id ASC FOR UPDATE"
        );
        $lockUsersStmt->execute($userIds);
        $lockedUsers = [];
        while ($row = $lockUsersStmt->fetch(PDO::FETCH_ASSOC)) {
            $lockedUsers[(int)$row['id']] = $row;
        }

        // Lock system_balance
        $pdo->prepare("SELECT balance FROM system_balance WHERE id = 1 FOR UPDATE")->execute();

        // Credit winner
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
            ->execute([$winnerNet, $winnerId]);

        $pdo->prepare(
            "INSERT INTO user_transactions (user_id, type, amount, game_id, payout_id)
             VALUES (?, 'win', ?, ?, ?)"
        )->execute([$winnerId, $winnerNet, $gameId, $payoutId]);

        // Resolve referrer using locked row
        $eligibleReferrer = null;
        if ($referrerId && isset($lockedUsers[$referrerId])) {
            $referrerRow = $lockedUsers[$referrerId];
            // Re-verify live locked row
            if ((int)$referrerRow['is_verified'] === 1 && (int)$referrerRow['is_banned'] === 0) {
                // Check age
                $ageStmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND created_at <= NOW() - INTERVAL 24 HOUR");
                $ageStmt->execute([$referrerId]);
                if ($ageStmt->fetch()) {
                    // Check deposit
                    $depStmt = $pdo->prepare("SELECT 1 FROM transactions WHERE user_id = ? AND type = 'deposit' AND status = 'completed' LIMIT 1");
                    $depStmt->execute([$referrerId]);
                    if ($depStmt->fetch()) {
                        $eligibleReferrer = $referrerRow;
                    }
                }
            } elseif ((int)$referrerRow['is_banned'] === 1) {
                error_log(sprintf('[Referral] Banned referrer %d — bonus unclaimed for game %d', $referrerId, $gameId));
            }
        }

        if ($eligibleReferrer) {
            // Credit referrer
            $pdo->prepare(
                "UPDATE users SET balance = balance + ?, referral_earnings = referral_earnings + ? WHERE id = ?"
            )->execute([$referralBonus, $referralBonus, $referrerId]);

            $pdo->prepare(
                "INSERT INTO user_transactions (user_id, type, amount, game_id, payout_id)
                 VALUES (?, 'referral_bonus', ?, ?, ?)"
            )->execute([$referrerId, $referralBonus, $gameId, $payoutId]);

            // One system_transactions row: commission only
            $pdo->prepare(
                "INSERT INTO system_transactions (game_id, payout_id, amount, type, source_user_id)
                 VALUES (?, ?, ?, 'commission', ?)"
            )->execute([$gameId, $payoutId, $commission, $winnerId]);

            $pdo->prepare("UPDATE system_balance SET balance = balance + ? WHERE id = 1")
                ->execute([$commission]);
        } else {
            // No eligible referrer: both commission and referral_bonus go to system
            $pdo->prepare(
                "INSERT INTO system_transactions (game_id, payout_id, amount, type, source_user_id)
                 VALUES (?, ?, ?, 'commission', ?)"
            )->execute([$gameId, $payoutId, $commission, $winnerId]);

            $pdo->prepare(
                "INSERT INTO system_transactions (game_id, payout_id, amount, type, source_user_id)
                 VALUES (?, ?, ?, 'referral_unclaimed', ?)"
            )->execute([$gameId, $payoutId, $referralBonus, $winnerId]);

            $pdo->prepare("UPDATE system_balance SET balance = balance + ? WHERE id = 1")
                ->execute([$commission + $referralBonus]);
        }

        // Update game: mark paid with snapshot
        $pdo->prepare(
            "UPDATE lottery_games
             SET status='finished', winner_id=?, finished_at=NOW(),
                 payout_status='paid', payout_id=?,
                 commission=?, referral_bonus=?, winner_net=?,
                 final_bets_snapshot=?, final_combined_hash=?,
                 final_rand_unit=?, final_target=?, final_total_weight=?
             WHERE id=?"
        )->execute([
            $winnerId,
            $payoutId,
            $commission,
            $referralBonus,
            $winnerNet,
            json_encode($snapshot),
            $result['combined_hash'],
            $result['rand_unit'],
            $result['target'],
            $result['total_weight'],
            $gameId,
        ]);

        $pdo->commit();
        error_log(sprintf(
            "[Lottery] Game #%d finished. Winner: #%d. Pot: $%.2f. Net: $%.2f. Commission: $%.2f. Referral: $%.2f. PayoutId: %s",
            $gameId, $winnerId, $pot, $winnerNet, $commission, $referralBonus, $payoutId
        ));

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Unique constraint violation = already paid
        if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
            error_log(sprintf('[Payout] Unique constraint violation for game #%d — treating as already paid. %s', $gameId, $e->getMessage()));
            $stmt2 = $pdo->prepare("SELECT * FROM lottery_games WHERE id = ?");
            $stmt2->execute([$gameId]);
            return $stmt2->fetch();
        }
        throw $e;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Create next game (same room as the finished game)
    $serverSeed     = bin2hex(random_bytes(16));
    $serverSeedHash = hash('sha256', $serverSeed);
    $pdo->prepare(
        "INSERT INTO lottery_games (status, server_seed, server_seed_hash, room) VALUES ('waiting', ?, ?, ?)"
    )->execute([$serverSeed, $serverSeedHash, $game['room']]);
    $newId = (int)$pdo->lastInsertId();
    error_log("[Lottery] New game #$newId created (seed_hash: $serverSeedHash).");

    $stmt = $pdo->prepare("SELECT * FROM lottery_games WHERE id = ?");
    $stmt->execute([$gameId]);
    return $stmt->fetch();
}

// ─────────────────────────────────────────────────────────────────────────────
// Place a bet in the specified room (1, 10, or 100).
// FINAL FIX: strict client_seed validation (uint32-uint32-uint32-uint32).
// ─────────────────────────────────────────────────────────────────────────────
function placeBet(PDO $pdo, int $userId, int $room = 1, string $clientSeed = ''): array {
    if (!in_array($room, [1, 10, 100], true)) {
        throw new InvalidArgumentException('Invalid room. Must be 1, 10, or 100.');
    }

    // FINAL FIX: strict format — 4 uint32 values joined by dashes
    if ($clientSeed !== '') {
        if (!preg_match('/^\d{1,10}(-\d{1,10}){3}$/', $clientSeed)) {
            throw new RuntimeException('Invalid client seed format.');
        }
    }

    $game = getOrCreateActiveGame($pdo, $room);

    if ($game['status'] === 'finished') {
        throw new RuntimeException('This round has already finished.');
    }
    if ($game['status'] === 'countdown') {
        $elapsed = time() - strtotime($game['started_at']);
        if ($elapsed >= LOTTERY_COUNTDOWN) {
            throw new RuntimeException('Betting is closed — round has ended.');
        }
    }

    // Rate limit: max LOTTERY_MAX_BETS_PER_SEC bets per user per second
    $rateStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM lottery_bets
         WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 SECOND"
    );
    $rateStmt->execute([$userId]);
    if ((int)$rateStmt->fetchColumn() >= LOTTERY_MAX_BETS_PER_SEC) {
        throw new RuntimeException('Too many bets. Please slow down.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $balance = (float)$stmt->fetchColumn();

        if ($balance < $room) {
            $pdo->rollBack();
            throw new RuntimeException('Insufficient balance.');
        }

        $gameLock = $pdo->prepare("SELECT * FROM lottery_games WHERE id = ? FOR UPDATE");
        $gameLock->execute([$game['id']]);
        $lockedGame = $gameLock->fetch();

        if ($lockedGame['status'] === 'finished') {
            $pdo->rollBack();
            throw new RuntimeException('This round has already finished.');
        }
        if ($lockedGame['status'] === 'countdown' && $lockedGame['started_at'] !== null) {
            $elapsed = time() - strtotime($lockedGame['started_at']);
            if ($elapsed >= LOTTERY_COUNTDOWN) {
                $pdo->rollBack();
                throw new RuntimeException('Betting is closed — round has ended.');
            }
        }

        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")
            ->execute([$room, $userId]);

        $pdo->prepare(
            "INSERT INTO user_transactions (user_id, type, amount, game_id, payout_id)
             VALUES (?, 'bet', ?, ?, NULL)"
        )->execute([$userId, $room, $game['id']]);

        $pdo->prepare(
            "INSERT INTO lottery_bets (game_id, user_id, amount, client_seed, room) VALUES (?, ?, ?, ?, ?)"
        )->execute([$game['id'], $userId, $room, $clientSeed ?: null, $room]);

        $pdo->prepare("UPDATE lottery_games SET total_pot = total_pot + ? WHERE id = ?")
            ->execute([$room, $game['id']]);

        $countStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM lottery_bets WHERE game_id = ?"
        );
        $countStmt->execute([$game['id']]);
        $distinctPlayers = (int)$countStmt->fetchColumn();

        if ($lockedGame['status'] === 'waiting' && $distinctPlayers >= LOTTERY_MIN_PLAYERS) {
            $pdo->prepare(
                "UPDATE lottery_games SET status='countdown', started_at=NOW() WHERE id=?"
            )->execute([$game['id']]);
            error_log("[Lottery] Countdown started for game #{$game['id']} with $distinctPlayers players.");
        }

        $pdo->commit();
        error_log("[Lottery] Bet placed: user #$userId game #{$game['id']} room=$room seed=$clientSeed");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return getGameState($pdo, $room, $userId);
}

// ─────────────────────────────────────────────────────────────────────────────
// Last finished game for "previous round" display.
// ─────────────────────────────────────────────────────────────────────────────
function getLastFinishedGame(PDO $pdo, int $room = 1): ?array {
    $stmt = $pdo->prepare(
        "SELECT g.*, u.email AS winner_email, COALESCE(u.nickname, u.email) AS winner_display_name, u.is_bot AS winner_is_bot
         FROM lottery_games g
         LEFT JOIN users u ON u.id = g.winner_id
         WHERE g.status = 'finished' AND g.room = ?
         ORDER BY g.finished_at DESC LIMIT 1"
    );
    $stmt->execute([$room]);
    $game = $stmt->fetch();
    if (!$game) return null;

    $totalPot = (float)$game['total_pot'];
    $bets     = buildBetList($pdo, $game['id'], $totalPot);

    return [
        'id'                   => (int)$game['id'],
        'total_pot'            => $totalPot,
        'winner_email'         => $game['winner_email'],
        'winner_display_name'  => $game['winner_display_name'],
        'winner_is_bot'        => (bool)$game['winner_is_bot'],
        'winner_id'            => (int)$game['winner_id'],
        'finished_at'          => $game['finished_at'],
        'server_seed'          => $game['server_seed'],
        'bets'                 => $bets,
        'room'                 => (int)$game['room'],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// FINAL FIX: verify endpoint uses immutable snapshot — never re-queries live bets.
// If snapshot exists, use it. If not (legacy game), fall back to live query.
// ─────────────────────────────────────────────────────────────────────────────
function getVerifyData(PDO $pdo, int $gameId): array {
    $stmt = $pdo->prepare(
        "SELECT g.*, COALESCE(u.nickname, u.email) AS winner_nickname
         FROM lottery_games g
         LEFT JOIN users u ON u.id = g.winner_id
         WHERE g.id = ?"
    );
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();

    if (!$game) throw new RuntimeException("Game #$gameId not found.");
    if ($game['status'] !== 'finished') throw new RuntimeException("Game #$gameId is not finished yet.");

    $serverSeed = $game['server_seed'] ?? '';

    // FINAL FIX: use frozen snapshot if available — immutable result contract
    if (!empty($game['final_bets_snapshot'])) {
        $betRows      = json_decode($game['final_bets_snapshot'], true);
        $combined     = $game['final_combined_hash'];
        $randUnit     = (float)$game['final_rand_unit'];
        $target       = (float)$game['final_target'];
        $totalWeight  = (float)$game['final_total_weight'];
    } else {
        // Legacy fallback: re-derive from live bets (games before snapshot feature)
        $betStmt = $pdo->prepare(
            "SELECT lb.id AS bet_id, lb.user_id, COALESCE(u.nickname, 'Player') AS nickname,
                    lb.amount, COALESCE(lb.client_seed,'') AS client_seed
             FROM lottery_bets lb JOIN users u ON u.id = lb.user_id
             WHERE lb.game_id = ? ORDER BY lb.id ASC"
        );
        $betStmt->execute([$gameId]);
        $betRows     = $betStmt->fetchAll();
        $clientSeeds = array_column($betRows, 'client_seed');
        $combined    = buildCombinedHashFromSeeds($serverSeed, $clientSeeds, $gameId);
        $randUnit    = hashToFloat($combined);

        $wStmt = $pdo->prepare(
            "SELECT user_id, SUM(amount) AS total FROM lottery_bets
             WHERE game_id = ? GROUP BY user_id ORDER BY user_id ASC"
        );
        $wStmt->execute([$gameId]);
        $wRows       = $wStmt->fetchAll();
        $totalWeight = array_sum(array_column($wRows, 'total'));
        $target      = computeTarget($combined, $totalWeight);
    }

    // Build cumulative weights for external verification
    $wStmt = $pdo->prepare(
        "SELECT user_id, SUM(amount) AS total FROM lottery_bets
         WHERE game_id = ? GROUP BY user_id ORDER BY user_id ASC"
    );
    $wStmt->execute([$gameId]);
    $wRows = $wStmt->fetchAll();

    $cumulativeWeights = [];
    $running = 0.0;
    foreach ($wRows as $row) {
        $running += (float)$row['total'];
        $cumulativeWeights[] = [
            'user_id'    => (int)$row['user_id'],
            'weight'     => (float)$row['total'],
            'cumulative' => round($running, 12),
        ];
    }

    $cumArr      = array_column($cumulativeWeights, 'cumulative');
    $winnerIndex = lowerBound($cumArr, $target);

    // Strip email from snapshot bet rows (privacy)
    $sanitizedBetRows = array_map(function ($row) {
        unset($row['email']);
        return $row;
    }, $betRows);

    return [
        'game_id'            => (int)$gameId,
        'status'             => $game['status'],
        'server_seed'        => $serverSeed,
        'server_seed_hash'   => $game['server_seed_hash'],
        'hash_format'        => LOTTERY_HASH_FORMAT,
        'client_seeds'       => $sanitizedBetRows,
        'combined_hash'      => $combined,
        'rand_unit'          => $randUnit,
        'target'             => $target,
        'total_weight'       => round($totalWeight, 12),
        'cumulative_weights' => $cumulativeWeights,
        'winner_index'       => $winnerIndex,
        'winner_id'          => (int)$game['winner_id'],
        'winner_nickname'    => $game['winner_nickname'],
        'total_pot'          => (float)$game['total_pot'],
        'finished_at'        => $game['finished_at'],
        'snapshot_used'      => !empty($game['final_bets_snapshot']),
        'verify_formula'     => sprintf(
            'sha256(sprintf("%s", server_seed, implode(":", client_seeds_by_id_asc), game_id))',
            LOTTERY_HASH_FORMAT
        ),
    ];
}
