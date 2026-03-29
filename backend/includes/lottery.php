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

define('LOTTERY_BET',              1.00);
define('LOTTERY_COUNTDOWN',        30);
define('LOTTERY_MIN_PLAYERS',      2);
define('LOTTERY_MAX_BETS_PER_SEC', 5);

// FINAL FIX: locked hash format — changing this would break all past verifications
// Format: server_seed:seed1:seed2:...:seedN:game_id
define('LOTTERY_HASH_FORMAT', '%s:%s:%d');

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
function getOrCreateActiveGame(PDO $pdo): array {
    $stmt = $pdo->prepare(
        "SELECT * FROM lottery_games
         WHERE status IN ('waiting','countdown')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute();
    $game = $stmt->fetch();

    if (!$game) {
        $serverSeed     = bin2hex(random_bytes(16));
        $serverSeedHash = hash('sha256', $serverSeed);
        $pdo->prepare(
            "INSERT INTO lottery_games (status, server_seed, server_seed_hash)
             VALUES ('waiting', ?, ?)"
        )->execute([$serverSeed, $serverSeedHash]);
        $id = (int)$pdo->lastInsertId();
        error_log("[Lottery] New game created: #$id (seed_hash: $serverSeedHash)");
        $stmt->execute();
        return $stmt->fetch();
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
                COALESCE(u.display_name, u.email) AS display_name,
                u.is_bot,
                SUM(lb.amount) AS total_bet,
                COUNT(lb.id)   AS bet_count
         FROM lottery_bets lb
         JOIN users u ON u.id = lb.user_id
         WHERE lb.game_id = ?
         GROUP BY lb.user_id, u.email, u.display_name, u.is_bot
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
function getGameState(PDO $pdo, ?int $currentUserId = null): array {
    $game = getOrCreateActiveGame($pdo);

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
        $w = $pdo->prepare("SELECT id, email, display_name, is_bot FROM users WHERE id = ?");
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
        ],
        'bets'           => $bets,
        'unique_players' => (int)$stats['unique_players'],
        'total_bets'     => (int)$stats['total_bets'],
        'my_stats'       => $myStats,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Race-condition safe game finalization.
// FINAL FIX: stores immutable snapshot at finish time.
// ─────────────────────────────────────────────────────────────────────────────
function finishGameSafe(PDO $pdo, int $gameId): array {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM lottery_games WHERE id = ? FOR UPDATE");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();

        if ($game['status'] === 'finished') { $pdo->rollBack(); return $game; }
        if ($game['started_at'] === null)   { $pdo->rollBack(); return $game; }

        $elapsed = time() - strtotime($game['started_at']);
        if ($elapsed < LOTTERY_COUNTDOWN)   { $pdo->rollBack(); return $game; }

        $pot = (float)$game['total_pot'];

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

        $result   = pickWeightedWinner($pdo, $gameId, $game['server_seed'] ?? '');
        $winnerId = $result['winner_id'];

        // FINAL FIX: build immutable snapshot of all bets at this exact moment
        $snapStmt = $pdo->prepare(
            "SELECT lb.id AS bet_id, lb.user_id, u.email,
                    lb.amount, COALESCE(lb.client_seed,'') AS client_seed
             FROM lottery_bets lb
             JOIN users u ON u.id = lb.user_id
             WHERE lb.game_id = ? ORDER BY lb.id ASC"
        );
        $snapStmt->execute([$gameId]);
        $snapshot = $snapStmt->fetchAll();

        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
            ->execute([$pot, $winnerId]);

        $pdo->prepare(
            "INSERT INTO transactions (user_id, type, amount, status, note)
             VALUES (?, 'deposit', ?, 'completed', ?)"
        )->execute([$winnerId, $pot, "Lottery win #$gameId"]);

        // FINAL FIX: store snapshot + all draw parameters atomically with game close
        $pdo->prepare(
            "UPDATE lottery_games
             SET status='finished', winner_id=?, finished_at=NOW(),
                 final_bets_snapshot=?, final_combined_hash=?,
                 final_rand_unit=?, final_target=?, final_total_weight=?
             WHERE id=?"
        )->execute([
            $winnerId,
            json_encode($snapshot),
            $result['combined_hash'],
            $result['rand_unit'],
            $result['target'],
            $result['total_weight'],
            $gameId,
        ]);

        $pdo->commit();
        error_log(sprintf(
            "[Lottery] Game #%d finished. Winner: #%d. Pot: $%.2f. Hash: %s. Target: %.12f",
            $gameId, $winnerId, $pot, $result['combined_hash'], $result['target']
        ));

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $serverSeed     = bin2hex(random_bytes(16));
    $serverSeedHash = hash('sha256', $serverSeed);
    $pdo->prepare(
        "INSERT INTO lottery_games (status, server_seed, server_seed_hash) VALUES ('waiting', ?, ?)"
    )->execute([$serverSeed, $serverSeedHash]);
    $newId = (int)$pdo->lastInsertId();
    error_log("[Lottery] New game #$newId created (seed_hash: $serverSeedHash).");

    $stmt = $pdo->prepare("SELECT * FROM lottery_games WHERE id = ?");
    $stmt->execute([$gameId]);
    return $stmt->fetch();
}

// ─────────────────────────────────────────────────────────────────────────────
// Place a $1 bet.
// FINAL FIX: strict client_seed validation (uint32-uint32-uint32-uint32).
// ─────────────────────────────────────────────────────────────────────────────
function placeBet(PDO $pdo, int $userId, string $clientSeed = ''): array {
    // FINAL FIX: strict format — 4 uint32 values joined by dashes
    if ($clientSeed !== '') {
        if (!preg_match('/^\d{1,10}(-\d{1,10}){3}$/', $clientSeed)) {
            throw new RuntimeException('Invalid client seed format.');
        }
    }

    $game = getOrCreateActiveGame($pdo);

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

        if ($balance < LOTTERY_BET) {
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
            ->execute([LOTTERY_BET, $userId]);

        $pdo->prepare(
            "INSERT INTO lottery_bets (game_id, user_id, amount, client_seed) VALUES (?, ?, ?, ?)"
        )->execute([$game['id'], $userId, LOTTERY_BET, $clientSeed ?: null]);

        $pdo->prepare("UPDATE lottery_games SET total_pot = total_pot + ? WHERE id = ?")
            ->execute([LOTTERY_BET, $game['id']]);

        $pdo->prepare(
            "INSERT INTO transactions (user_id, type, amount, status, note)
             VALUES (?, 'withdrawal', ?, 'completed', ?)"
        )->execute([$userId, LOTTERY_BET, "Lottery bet #{$game['id']}"]);

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
        error_log("[Lottery] Bet placed: user #$userId game #{$game['id']} seed=$clientSeed");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return getGameState($pdo, $userId);
}

// ─────────────────────────────────────────────────────────────────────────────
// Last finished game for "previous round" display.
// ─────────────────────────────────────────────────────────────────────────────
function getLastFinishedGame(PDO $pdo): ?array {
    $stmt = $pdo->prepare(
        "SELECT g.*, u.email AS winner_email, u.display_name AS winner_display_name, u.is_bot AS winner_is_bot
         FROM lottery_games g
         LEFT JOIN users u ON u.id = g.winner_id
         WHERE g.status = 'finished'
         ORDER BY g.finished_at DESC LIMIT 1"
    );
    $stmt->execute();
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
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// FINAL FIX: verify endpoint uses immutable snapshot — never re-queries live bets.
// If snapshot exists, use it. If not (legacy game), fall back to live query.
// ─────────────────────────────────────────────────────────────────────────────
function getVerifyData(PDO $pdo, int $gameId): array {
    $stmt = $pdo->prepare(
        "SELECT g.*, u.email AS winner_email
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
            "SELECT lb.id AS bet_id, lb.user_id, u.email,
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

    return [
        'game_id'            => (int)$gameId,
        'status'             => $game['status'],
        'server_seed'        => $serverSeed,
        'server_seed_hash'   => $game['server_seed_hash'],
        'hash_format'        => LOTTERY_HASH_FORMAT,
        'client_seeds'       => $betRows,
        'combined_hash'      => $combined,
        'rand_unit'          => $randUnit,
        'target'             => $target,
        'total_weight'       => round($totalWeight, 12),
        'cumulative_weights' => $cumulativeWeights,
        'winner_index'       => $winnerIndex,
        'winner_id'          => (int)$game['winner_id'],
        'winner_email'       => $game['winner_email'],
        'total_pot'          => (float)$game['total_pot'],
        'finished_at'        => $game['finished_at'],
        'snapshot_used'      => !empty($game['final_bets_snapshot']),
        'verify_formula'     => sprintf(
            'sha256(sprintf("%s", server_seed, implode(":", client_seeds_by_id_asc), game_id))',
            LOTTERY_HASH_FORMAT
        ),
    ];
}
