<?php
declare(strict_types=1);

require_once __DIR__ . '/lottery.php';
require_once __DIR__ . '/ledger_service.php';

class GameEngine
{
    private PDO $pdo;
    private LedgerService $ledger;

    /** Valid state transitions: from → [to, ...] */
    private const TRANSITIONS = [
        'waiting'  => ['active'],
        'active'   => ['spinning'],
        'spinning' => ['finished'],
    ];

    public function __construct(PDO $pdo, LedgerService $ledger)
    {
        $this->pdo    = $pdo;
        $this->ledger = $ledger;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Get current non-finished round for room, or create new one in 'waiting'.
    // ─────────────────────────────────────────────────────────────────────────
    public function getOrCreateRound(int $room): array
    {
        if (!in_array($room, [1, 10, 100], true)) {
            throw new InvalidArgumentException('Invalid room. Must be 1, 10, or 100.');
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM game_rounds
             WHERE status IN ('waiting','active','spinning') AND room = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$room]);
        $round = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($round) {
            return $round;
        }

        $serverSeed     = bin2hex(random_bytes(32));
        $serverSeedHash = hash('sha256', $serverSeed);

        $this->pdo->prepare(
            "INSERT INTO game_rounds (room, status, server_seed, server_seed_hash)
             VALUES (?, 'waiting', ?, ?)"
        )->execute([$room, $serverSeed, $serverSeedHash]);

        $id = (int) $this->pdo->lastInsertId();

        error_log("[GameEngine] New round created: #$id room=$room (seed_hash: $serverSeedHash)");

        $refetch = $this->pdo->prepare("SELECT * FROM game_rounds WHERE id = ?");
        $refetch->execute([$id]);
        return $refetch->fetch(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validate state transition: only waiting→active→spinning→finished allowed.
    // ─────────────────────────────────────────────────────────────────────────
    private function validateTransition(string $from, string $to): void
    {
        $allowed = self::TRANSITIONS[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new RuntimeException(
                "Invalid state transition: $from → $to"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Place a bet in the specified room.
    // ─────────────────────────────────────────────────────────────────────────
    public function placeBet(int $userId, int $room, string $clientSeed = ''): array
    {
        if (!in_array($room, [1, 10, 100], true)) {
            throw new InvalidArgumentException('Invalid room. Must be 1, 10, or 100.');
        }

        $amount = (float) $room;

        // Anti-fraud: max 60 bets per minute per user
        $minuteStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM game_bets WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 MINUTE"
        );
        $minuteStmt->execute([$userId]);
        if ((int) $minuteStmt->fetchColumn() >= 60) {
            throw new RuntimeException('Rate limit exceeded');
        }

        // Rate limit: max LOTTERY_MAX_BETS_PER_SEC bets per user per second
        $rateStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM game_bets
             WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 SECOND"
        );
        $rateStmt->execute([$userId]);
        if ((int) $rateStmt->fetchColumn() >= LOTTERY_MAX_BETS_PER_SEC) {
            throw new RuntimeException('Too many bets. Please slow down.');
        }

        $this->pdo->beginTransaction();
        try {
            // Get or create round (outside lock — just to get the ID)
            $round = $this->getOrCreateRound($room);
            $roundId = (int) $round['id'];

            // Lock game_rounds row FOR UPDATE
            $lockStmt = $this->pdo->prepare(
                "SELECT * FROM game_rounds WHERE id = ? FOR UPDATE"
            );
            $lockStmt->execute([$roundId]);
            $lockedRound = $lockStmt->fetch(PDO::FETCH_ASSOC);

            // Validate: status must be 'waiting' or 'active'
            if (!in_array($lockedRound['status'], ['waiting', 'active'], true)) {
                $this->pdo->rollBack();
                throw new RuntimeException('Betting is closed');
            }

            // Debit user balance via LedgerService
            $ledgerEntry = $this->ledger->addEntry(
                $userId,
                'bet',
                $amount,
                'debit',
                (string) $roundId,
                'game_bet',
                ['source' => 'game_engine']
            );

            $ledgerEntryId = (int) $ledgerEntry['id'];

            // Insert game_bets row
            $this->pdo->prepare(
                "INSERT INTO game_bets (round_id, user_id, amount, client_seed, ledger_entry_id)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([
                $roundId,
                $userId,
                $amount,
                $clientSeed !== '' ? $clientSeed : null,
                $ledgerEntryId,
            ]);

            // Update total_pot
            $this->pdo->prepare(
                "UPDATE game_rounds SET total_pot = total_pot + ? WHERE id = ?"
            )->execute([$amount, $roundId]);

            // Check distinct player count for waiting → active transition
            if ($lockedRound['status'] === 'waiting') {
                $countStmt = $this->pdo->prepare(
                    "SELECT COUNT(DISTINCT user_id) FROM game_bets WHERE round_id = ?"
                );
                $countStmt->execute([$roundId]);
                $distinctPlayers = (int) $countStmt->fetchColumn();

                if ($distinctPlayers >= LOTTERY_MIN_PLAYERS) {
                    $this->validateTransition('waiting', 'active');
                    $this->pdo->prepare(
                        "UPDATE game_rounds SET status = 'active', started_at = NOW() WHERE id = ?"
                    )->execute([$roundId]);
                    error_log("[GameEngine] Round #$roundId transitioned to active with $distinctPlayers players.");
                }
            }

            $this->pdo->commit();
            error_log("[GameEngine] Bet placed: user #$userId round #$roundId room=$room");

        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getGameState($room, $userId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fetch client seeds from game_bets in deterministic order.
    // (Similar to fetchClientSeeds in lottery.php but queries game_bets.)
    // ─────────────────────────────────────────────────────────────────────────
    private function fetchGameClientSeeds(int $roundId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(client_seed, '') AS client_seed
             FROM game_bets WHERE round_id = ? ORDER BY id ASC"
        );
        $stmt->execute([$roundId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Finish a round: winner selection + payout distribution.
    // Retry wrapper: up to 3 attempts on deadlock/lock timeout.
    // ─────────────────────────────────────────────────────────────────────────
    public function finishRound(int $roundId): array
    {
        $maxRetries = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->finishRoundAttempt($roundId);
            } catch (\PDOException $e) {
                $code = (int) $e->getCode();
                if (in_array($code, [1213, 1205], true)
                    || str_contains($e->getMessage(), 'Deadlock')
                    || str_contains($e->getMessage(), 'Lock wait timeout')
                ) {
                    $lastException = $e;
                    error_log(sprintf(
                        '[GameEngine] Deadlock/timeout on attempt %d for round #%d: %s',
                        $attempt, $roundId, $e->getMessage()
                    ));
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    usleep(50000 * $attempt); // 50ms, 100ms, 150ms
                    continue;
                }
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }

        error_log(sprintf('[GameEngine] FATAL: 3 retries exhausted for round #%d', $roundId));
        throw $lastException;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Single attempt of the payout engine.
    // ─────────────────────────────────────────────────────────────────────────
    private function finishRoundAttempt(int $roundId): array
    {
        $this->pdo->beginTransaction();
        try {
            // 1. Lock game_rounds row
            $stmt = $this->pdo->prepare("SELECT * FROM game_rounds WHERE id = ? FOR UPDATE");
            $stmt->execute([$roundId]);
            $round = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$round) {
                $this->pdo->rollBack();
                throw new RuntimeException("Round #$roundId not found.");
            }

            // Double-payout protection
            if ($round['payout_status'] === 'paid') {
                $this->pdo->rollBack();
                return $round;
            }

            // Must be in 'spinning' status
            if ($round['status'] !== 'spinning') {
                $this->pdo->rollBack();
                throw new RuntimeException("Round #$roundId is not in spinning status (current: {$round['status']}).");
            }

            $pot = (float) $round['total_pot'];
            $serverSeed = $round['server_seed'] ?? '';

            // Fetch all bets for this round
            $betsStmt = $this->pdo->prepare(
                "SELECT user_id, SUM(amount) AS total
                 FROM game_bets WHERE round_id = ?
                 GROUP BY user_id ORDER BY user_id ASC"
            );
            $betsStmt->execute([$roundId]);
            $betRows = $betsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($betRows)) {
                // No bets — just finish
                $this->pdo->prepare(
                    "UPDATE game_rounds SET status = 'finished', finished_at = NOW() WHERE id = ?"
                )->execute([$roundId]);
                $this->pdo->commit();
                error_log("[GameEngine] Round #$roundId finished with no bets.");
                return array_merge($round, ['status' => 'finished']);
            }

            // Build cumulative weights
            $userIds    = [];
            $cumulative = [];
            $running    = 0.0;
            foreach ($betRows as $row) {
                $userIds[]    = (int) $row['user_id'];
                $running     += (float) $row['total'];
                $cumulative[] = $running;
            }
            $totalWeight = $running;

            if ($totalWeight <= 0.0) {
                $this->pdo->rollBack();
                throw new RuntimeException("Total weight is zero for round #$roundId");
            }

            // Fetch client seeds and compute combined hash
            $clientSeeds = $this->fetchGameClientSeeds($roundId);
            $combined    = buildCombinedHashFromSeeds($serverSeed, $clientSeeds, $roundId);
            $target      = computeTarget($combined, $totalWeight);
            $winnerIdx   = lowerBound($cumulative, $target);
            $winnerId    = $userIds[$winnerIdx];
            $randUnit    = hashToFloat($combined);

            error_log(sprintf(
                "[GameEngine] Winner: round #%d hash=%s rand_unit=%.12f target=%.12f total=%.2f idx=%d user_id=%d",
                $roundId, $combined, $randUnit, $target, $totalWeight, $winnerIdx, $winnerId
            ));

            // Compute payout amounts
            $payout        = computePayoutAmounts($pot);
            $commission    = $payout['commission'];
            $referralBonus = $payout['referral_bonus'];
            $winnerNet     = $payout['winner_net'];

            // Generate payout UUID
            $payoutId = $this->pdo->query("SELECT UUID()")->fetchColumn();

            // Resolve referrer
            $referrer   = resolveReferrer($this->pdo, $winnerId, $roundId);
            $referrerId = $referrer ? (int) $referrer['id'] : null;

            // ── STRICT LOCK ORDERING ──
            // 1. game_rounds already locked above
            // 2. Lock user_balances for all involved users sorted by user_id ASC
            $involvedUserIds = array_unique(array_filter([
                $winnerId,
                $referrerId,
                SYSTEM_USER_ID,
            ]));
            sort($involvedUserIds);

            foreach ($involvedUserIds as $uid) {
                $lockBal = $this->pdo->prepare(
                    "SELECT balance FROM user_balances WHERE user_id = ? FOR UPDATE"
                );
                $lockBal->execute([$uid]);
                // If no row exists, that's fine — addEntry will create it
            }

            // ── LEDGER ENTRIES ──
            // Winner credit
            $this->ledger->addEntry(
                $winnerId,
                'win',
                $winnerNet,
                'credit',
                (string) $roundId,
                'game_payout',
                ['source' => 'game_engine']
            );

            // Anti-fraud: win streak detection
            $streakStmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM game_rounds 
                 WHERE winner_id = ? AND status = 'finished' 
                 AND finished_at >= NOW() - INTERVAL 24 HOUR"
            );
            $streakStmt->execute([$winnerId]);
            $winCount = (int) $streakStmt->fetchColumn();
            if ($winCount >= 10) {
                error_log(sprintf('[AntifraudHook] Suspicious win streak: user #%d has %d wins in 24h', $winnerId, $winCount));
            }

            // System fee
            $this->ledger->addEntry(
                SYSTEM_USER_ID,
                'system_fee',
                $commission,
                'credit',
                (string) $roundId,
                'game_payout',
                ['source' => 'game_engine']
            );

            // Referral bonus
            if ($referrer) {
                $this->ledger->addEntry(
                    $referrerId,
                    'referral_bonus',
                    $referralBonus,
                    'credit',
                    (string) $roundId,
                    'game_payout',
                    ['source' => 'game_engine']
                );

                // Update users.referral_earnings
                $this->pdo->prepare(
                    "UPDATE users SET referral_earnings = referral_earnings + ? WHERE id = ?"
                )->execute([$referralBonus, $referrerId]);
            } else {
                // No eligible referrer — unclaimed bonus goes to system
                $this->ledger->addEntry(
                    SYSTEM_USER_ID,
                    'referral_bonus',
                    $referralBonus,
                    'credit',
                    (string) $roundId,
                    'referral_unclaimed',
                    ['source' => 'game_engine']
                );
            }

            // Build immutable snapshot
            $snapStmt = $this->pdo->prepare(
                "SELECT gb.id AS bet_id, gb.user_id, u.email,
                        gb.amount, COALESCE(gb.client_seed, '') AS client_seed
                 FROM game_bets gb
                 JOIN users u ON u.id = gb.user_id
                 WHERE gb.round_id = ? ORDER BY gb.id ASC"
            );
            $snapStmt->execute([$roundId]);
            $snapshot = $snapStmt->fetchAll(PDO::FETCH_ASSOC);

            // Update game_rounds: mark paid with snapshot
            $this->pdo->prepare(
                "UPDATE game_rounds
                 SET status = 'finished', winner_id = ?, finished_at = NOW(),
                     payout_status = 'paid', payout_id = ?,
                     commission = ?, referral_bonus = ?, winner_net = ?,
                     final_bets_snapshot = ?, final_combined_hash = ?,
                     final_rand_unit = ?, final_target = ?, final_total_weight = ?
                 WHERE id = ?"
            )->execute([
                $winnerId,
                $payoutId,
                $commission,
                $referralBonus,
                $winnerNet,
                json_encode($snapshot),
                $combined,
                $randUnit,
                $target,
                $totalWeight,
                $roundId,
            ]);

            $this->pdo->commit();

            error_log(sprintf(
                "[GameEngine] Round #%d finished. Winner: #%d. Pot: $%.2f. Net: $%.2f. Commission: $%.2f. Referral: $%.2f. PayoutId: %s",
                $roundId, $winnerId, $pot, $winnerNet, $commission, $referralBonus, $payoutId
            ));

        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Unique constraint violation = already paid
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                error_log(sprintf(
                    '[GameEngine] Unique constraint violation for round #%d — treating as already paid. %s',
                    $roundId, $e->getMessage()
                ));
                $stmt2 = $this->pdo->prepare("SELECT * FROM game_rounds WHERE id = ?");
                $stmt2->execute([$roundId]);
                return $stmt2->fetch(PDO::FETCH_ASSOC);
            }
            throw $e;
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Create new round in 'waiting' for same room
        $newServerSeed     = bin2hex(random_bytes(32));
        $newServerSeedHash = hash('sha256', $newServerSeed);
        $this->pdo->prepare(
            "INSERT INTO game_rounds (room, status, server_seed, server_seed_hash)
             VALUES (?, 'waiting', ?, ?)"
        )->execute([(int) $round['room'], $newServerSeed, $newServerSeedHash]);
        $newId = (int) $this->pdo->lastInsertId();
        error_log("[GameEngine] New round #$newId created for room {$round['room']} (seed_hash: $newServerSeedHash).");

        // Return the finished round
        $finalStmt = $this->pdo->prepare("SELECT * FROM game_rounds WHERE id = ?");
        $finalStmt->execute([$roundId]);
        return $finalStmt->fetch(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Get current game state for a room (read-only — NO state transitions).
    // ─────────────────────────────────────────────────────────────────────────
    public function getGameState(int $room, ?int $userId = null): array
    {
        if (!in_array($room, [1, 10, 100], true)) {
            throw new InvalidArgumentException('Invalid room. Must be 1, 10, or 100.');
        }

        // Get current non-finished round, or latest round
        $stmt = $this->pdo->prepare(
            "SELECT * FROM game_rounds
             WHERE room = ? AND status IN ('waiting','active','spinning')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$room]);
        $round = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$round) {
            // No active round — get latest finished or create new
            $round = $this->getOrCreateRound($room);
        }

        $roundId  = (int) $round['id'];
        $totalPot = (float) $round['total_pot'];

        // Build aggregated bets list
        $betsStmt = $this->pdo->prepare(
            "SELECT gb.user_id,
                    COALESCE(u.nickname, u.email) AS display_name,
                    u.is_bot,
                    SUM(gb.amount) AS total_bet,
                    COUNT(gb.id)   AS bet_count
             FROM game_bets gb
             JOIN users u ON u.id = gb.user_id
             WHERE gb.round_id = ?
             GROUP BY gb.user_id, u.nickname, u.email, u.is_bot
             ORDER BY total_bet DESC"
        );
        $betsStmt->execute([$roundId]);
        $betRows = $betsStmt->fetchAll(PDO::FETCH_ASSOC);

        $bets = array_map(function ($row) use ($totalPot) {
            $totalBet = (float) $row['total_bet'];
            return [
                'user_id'      => (int) $row['user_id'],
                'display_name' => $row['display_name'],
                'is_bot'       => (bool) $row['is_bot'],
                'total_bet'    => $totalBet,
                'bet_count'    => (int) $row['bet_count'],
                'chance'       => $totalPot > 0 ? round($totalBet / $totalPot, 6) : 0.0,
            ];
        }, $betRows);

        // Stats
        $statsStmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT user_id) AS unique_players, COUNT(*) AS total_bets
             FROM game_bets WHERE round_id = ?"
        );
        $statsStmt->execute([$roundId]);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

        // Countdown (only when active)
        $countdown = null;
        if ($round['status'] === 'active' && $round['started_at'] !== null) {
            $elapsed   = time() - strtotime($round['started_at']);
            $countdown = max(0, LOTTERY_COUNTDOWN - $elapsed);
        }

        // Winner info (if finished)
        $winner = null;
        if ($round['winner_id']) {
            $w = $this->pdo->prepare(
                "SELECT id, email, COALESCE(nickname, email) AS display_name, is_bot
                 FROM users WHERE id = ?"
            );
            $w->execute([$round['winner_id']]);
            $winner = $w->fetch(PDO::FETCH_ASSOC);
            if ($winner) {
                $winner['id']     = (int) $winner['id'];
                $winner['is_bot'] = (bool) $winner['is_bot'];
            }
        }

        // My stats (if userId provided)
        $myStats = null;
        if ($userId !== null) {
            $myRow = null;
            foreach ($bets as $b) {
                if ($b['user_id'] === $userId) {
                    $myRow = $b;
                    break;
                }
            }
            $myStats = [
                'total_bets' => $myRow ? $myRow['bet_count'] : 0,
                'total_bet'  => $myRow ? $myRow['total_bet'] : 0.0,
                'chance'     => $myRow ? $myRow['chance']     : 0.0,
            ];
        }

        // Previous finished round
        $previous = $this->getLastFinishedRound($room);

        return [
            'round' => [
                'round_id'         => $roundId,
                'status'           => $round['status'],
                'total_pot'        => $totalPot,
                'countdown'        => $countdown,
                'winner'           => $winner,
                'server_seed_hash' => $round['server_seed_hash'] ?? null,
                'server_seed'      => $round['status'] === 'finished'
                    ? ($round['server_seed'] ?? null)
                    : null,
                'room'             => (int) $round['room'],
            ],
            'bets'           => $bets,
            'unique_players' => (int) $stats['unique_players'],
            'total_bets'     => (int) $stats['total_bets'],
            'my_stats'       => $myStats,
            'previous'       => $previous,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Last finished round for "previous round" display.
    // ─────────────────────────────────────────────────────────────────────────
    private function getLastFinishedRound(int $room): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT gr.*,
                    u.email AS winner_email,
                    COALESCE(u.nickname, u.email) AS winner_display_name,
                    u.is_bot AS winner_is_bot
             FROM game_rounds gr
             LEFT JOIN users u ON u.id = gr.winner_id
             WHERE gr.status = 'finished' AND gr.room = ?
             ORDER BY gr.finished_at DESC LIMIT 1"
        );
        $stmt->execute([$room]);
        $round = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$round) {
            return null;
        }

        $totalPot = (float) $round['total_pot'];

        // Build bet list for the finished round
        $betsStmt = $this->pdo->prepare(
            "SELECT gb.user_id,
                    COALESCE(u.nickname, u.email) AS display_name,
                    u.is_bot,
                    SUM(gb.amount) AS total_bet,
                    COUNT(gb.id)   AS bet_count
             FROM game_bets gb
             JOIN users u ON u.id = gb.user_id
             WHERE gb.round_id = ?
             GROUP BY gb.user_id, u.nickname, u.email, u.is_bot
             ORDER BY total_bet DESC"
        );
        $betsStmt->execute([(int) $round['id']]);
        $betRows = $betsStmt->fetchAll(PDO::FETCH_ASSOC);

        $bets = array_map(function ($row) use ($totalPot) {
            $totalBet = (float) $row['total_bet'];
            return [
                'user_id'      => (int) $row['user_id'],
                'display_name' => $row['display_name'],
                'is_bot'       => (bool) $row['is_bot'],
                'total_bet'    => $totalBet,
                'bet_count'    => (int) $row['bet_count'],
                'chance'       => $totalPot > 0 ? round($totalBet / $totalPot, 6) : 0.0,
            ];
        }, $betRows);

        return [
            'round_id'             => (int) $round['id'],
            'total_pot'            => $totalPot,
            'winner_email'         => $round['winner_email'],
            'winner_display_name'  => $round['winner_display_name'],
            'winner_is_bot'        => (bool) $round['winner_is_bot'],
            'winner_id'            => (int) $round['winner_id'],
            'finished_at'          => $round['finished_at'],
            'server_seed'          => $round['server_seed'],
            'bets'                 => $bets,
            'room'                 => (int) $round['room'],
        ];
    }
}
