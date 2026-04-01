<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/lottery.php';
require_once __DIR__ . '/includes/ledger_service.php';
require_once __DIR__ . '/includes/game_engine.php';

// ── Config ────────────────────────────────────────────────────────────────────
const BOT_FORCE_JOIN_BELOW  = 2;      // force a bot in if fewer unique players
const BOT_MAX_BETS_PER_GAME = 15;     // hard cap per bot per round
const BOT_MIN_BALANCE       = 50.0;
const BOT_TOPUP_AMOUNT      = 1000.0;
const BOT_MAX_BALANCE       = 50000;
const BOT_ROOMS             = [1];

// Strategy thresholds
const BOT_TARGET_WIN_CHANCE  = 0.35;  // bots aim for ~35% win chance per round
const BOT_MAX_POT_EXPOSURE   = 0.15;  // never risk more than 15% of balance in one round
const BOT_RETREAT_CHANCE     = 0.60;  // stop adding bets if already above 60% chance
const BOT_HUMAN_CHASE_FACTOR = 0.7;   // match up to 70% of opponent total
const BOT_LOW_BALANCE_GUARD  = 20.0;  // play conservatively below this balance
const BOT_REBUY_CHANCE       = 45;    // % chance per tick to add more bets in active round

$ledger = new LedgerService($pdo);
$engine = new GameEngine($pdo, $ledger);

// ── Helpers ───────────────────────────────────────────────────────────────────

function generateBotSeed(): string {
    return implode('-', [
        random_int(0, 4294967295),
        random_int(0, 4294967295),
        random_int(0, 4294967295),
        random_int(0, 4294967295),
    ]);
}

function getActiveGameForBot(PDO $pdo, int $room): ?array {
    $stmt = $pdo->prepare(
        "SELECT * FROM game_rounds
         WHERE status IN ('waiting','active') AND room = ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$room]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Analyze the current round: who bet how much, what's the pot, what are the odds.
 * Returns structured intel for strategic decision-making.
 */
function analyzeRound(PDO $pdo, int $roundId, int $botId): array {
    $stmt = $pdo->prepare(
        "SELECT gb.user_id, u.is_bot, SUM(gb.amount) AS total_bet, COUNT(*) AS bet_count
         FROM game_bets gb
         JOIN users u ON u.id = gb.user_id
         WHERE gb.round_id = ?
         GROUP BY gb.user_id, u.is_bot"
    );
    $stmt->execute([$roundId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pot         = 0.0;
    $humanTotal  = 0.0;
    $botTotal    = 0.0;
    $myTotal     = 0.0;
    $myBetCount  = 0;
    $humanCount  = 0;
    $botCount    = 0;
    $biggestHuman = 0.0;

    foreach ($rows as $r) {
        $amt = (float)$r['total_bet'];
        $pot += $amt;

        if ((int)$r['user_id'] === $botId) {
            $myTotal    = $amt;
            $myBetCount = (int)$r['bet_count'];
        }

        if ((int)$r['is_bot']) {
            $botTotal += $amt;
            $botCount++;
        } else {
            $humanTotal += $amt;
            $humanCount++;
            $biggestHuman = max($biggestHuman, $amt);
        }
    }

    $myChance = $pot > 0 ? $myTotal / $pot : 0.0;

    return [
        'pot'            => $pot,
        'human_total'    => $humanTotal,
        'bot_total'      => $botTotal,
        'my_total'       => $myTotal,
        'my_bet_count'   => $myBetCount,
        'my_chance'      => $myChance,
        'human_count'    => $humanCount,
        'bot_count'      => $botCount,
        'biggest_human'  => $biggestHuman,
        'unique_players' => $humanCount + $botCount,
    ];
}

/**
 * Decide how many additional bets this bot should place in this round.
 *
 * Strategy:
 *  - Compete against ALL opponents (bots + humans), not just humans
 *  - If already above retreat threshold, stop
 *  - If low balance, play conservatively
 *  - Chase opponent bets to reach target win chance
 *  - Never exceed max pot exposure relative to balance
 *  - Add randomness so bots don't all behave identically
 */
function decideBetCount(array $intel, float $botBalance, int $room): int {
    $myChance   = $intel['my_chance'];
    $myBetCount = $intel['my_bet_count'];
    $pot        = $intel['pot'];

    // Total opponent bets = pot minus my own bets
    $opponentTotal = $pot - $intel['my_total'];

    // Hard cap
    if ($myBetCount >= BOT_MAX_BETS_PER_GAME) {
        return 0;
    }

    $remaining = BOT_MAX_BETS_PER_GAME - $myBetCount;

    // Already dominating the round — retreat
    if ($myChance >= BOT_RETREAT_CHANCE && $myBetCount > 0) {
        return 0;
    }

    // Max exposure guard: don't risk more than X% of balance in one round
    $maxExposure = $botBalance * BOT_MAX_POT_EXPOSURE;
    if ($intel['my_total'] >= $maxExposure) {
        return 0;
    }
    $exposureBudget = (int)floor(($maxExposure - $intel['my_total']) / $room);
    $remaining = min($remaining, max(0, $exposureBudget));

    if ($remaining <= 0) {
        return 0;
    }

    // Low balance — play very conservatively
    if ($botBalance < BOT_LOW_BALANCE_GUARD) {
        if ($myBetCount === 0 && $intel['unique_players'] > 0) {
            return 1;
        }
        return 0;
    }

    // No opponents yet — seed with 1 bet
    if ($opponentTotal <= 0) {
        return $myBetCount === 0 ? 1 : 0;
    }

    // ── Strategic chase logic ──
    // Goal: reach target win chance by matching a fraction of opponent bets
    $targetMyTotal = $opponentTotal * BOT_HUMAN_CHASE_FACTOR;
    $deficit = $targetMyTotal - $intel['my_total'];

    if ($deficit <= 0) {
        // Already at or above chase target — maybe 1 more with low probability
        return (random_int(1, 100) <= 20) ? 1 : 0;
    }

    $wantedBets = (int)ceil($deficit / $room);
    $wantedBets = min($wantedBets, $remaining);

    // Spread bets over ticks: 1-3 per tick
    $maxPerTick = min($wantedBets, random_int(1, 3));

    // Chance-based dampening near target
    if ($myChance > BOT_TARGET_WIN_CHANCE) {
        return (random_int(1, 100) <= 35) ? 1 : 0;
    }

    return $maxPerTick;
}

/**
 * Pick a bot for this round. Includes bots already in the round (for re-buys)
 * and new bots (for joining). Prefers bots with lower win chance.
 */
function pickStrategicBot(PDO $pdo, int $roundId, int $room): ?array {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.balance,
                COALESCE(SUM(gb.amount), 0) AS round_total,
                COUNT(gb.id) AS round_bets
         FROM users u
         LEFT JOIN game_bets gb ON gb.user_id = u.id AND gb.round_id = :roundId
         WHERE u.is_bot = 1
           AND u.id != 1
           AND u.balance >= :betAmount
         GROUP BY u.id, u.balance
         HAVING round_bets < :maxBets
         ORDER BY RAND()
         LIMIT 5"
    );
    $stmt->execute([
        ':roundId'   => $roundId,
        ':betAmount' => $room,
        ':maxBets'   => BOT_MAX_BETS_PER_GAME,
    ]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($candidates)) {
        return null;
    }

    // Pick randomly from candidates
    return $candidates[array_rand($candidates)];
}

// ── Top up ────────────────────────────────────────────────────────────────────
function topUpBots(PDO $pdo, LedgerService $ledger): void {
    $stmt = $pdo->prepare(
        "SELECT id, balance FROM users WHERE is_bot = 1 AND id != 1 AND balance < ?"
    );
    $stmt->execute([BOT_MIN_BALANCE]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bot) {
        $botId   = (int)$bot['id'];
        $balance = (float)$bot['balance'];
        if ($balance >= BOT_MAX_BALANCE) continue;

        try {
            $pdo->beginTransaction();
            $ledger->addEntry(
                $botId, 'deposit', BOT_TOPUP_AMOUNT, 'credit',
                'bot_topup:' . time() . ':' . $botId, 'bot_topup',
                ['source' => 'bot_runner', 'is_bot' => true]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("[Bot] Top-up failed for bot #$botId: " . $e->getMessage());
        }
    }
}

// ── Main tick for a single room ───────────────────────────────────────────────
function runBotTickForRoom(PDO $pdo, GameEngine $engine, LedgerService $ledger, int $room): void {
    $game = getActiveGameForBot($pdo, $room);

    if (!$game) {
        $engine->getOrCreateRound($room);
        return;
    }

    $roundId = (int)$game['id'];

    // Don't act if countdown expired — let the worker finish the round
    if ($game['status'] === 'active') {
        $elapsed   = time() - strtotime($game['started_at']);
        $remaining = LOTTERY_COUNTDOWN - $elapsed;
        if ($remaining <= 0) return;
    }

    // ── Force join: ensure at least 2 players for the round to start ──
    $uniquePlayers = 0;
    $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM game_bets WHERE round_id = ?");
    $countStmt->execute([$roundId]);
    $uniquePlayers = (int)$countStmt->fetchColumn();

    if ($uniquePlayers < BOT_FORCE_JOIN_BELOW) {
        $bot = pickStrategicBot($pdo, $roundId, $room);
        if ($bot) {
            $botId = (int)$bot['id'];
            $myStmt = $pdo->prepare("SELECT COUNT(*) FROM game_bets WHERE user_id = ? AND round_id = ?");
            $myStmt->execute([$botId, $roundId]);
            $myBets = (int)$myStmt->fetchColumn();

            if ($myBets === 0 || $uniquePlayers === 0) {
                try {
                    usleep(random_int(100000, 500000));
                    $engine->placeBet($botId, $room, generateBotSeed());
                    error_log("[Bot][seed] Bot #$botId seeded room $room round #$roundId");
                } catch (\Throwable $e) {
                    error_log("[Bot] Seed bet failed: " . $e->getMessage());
                }
            }
        }
        return; // wait for 2nd player before strategic play
    }

    // ── Let additional bots join active rounds (30% chance per tick) ──
    if ($game['status'] === 'active' && random_int(1, 100) <= 30) {
        $joiner = pickStrategicBot($pdo, $roundId, $room);
        if ($joiner) {
            $joinerId = (int)$joiner['id'];
            $joinStmt = $pdo->prepare("SELECT COUNT(*) FROM game_bets WHERE user_id = ? AND round_id = ?");
            $joinStmt->execute([$joinerId, $roundId]);
            if ((int)$joinStmt->fetchColumn() === 0) {
                try {
                    usleep(random_int(200000, 800000));
                    $engine->placeBet($joinerId, $room, generateBotSeed());
                    error_log("[Bot][join] Bot #$joinerId joined room $room round #$roundId");
                } catch (\Throwable $e) {
                    error_log("[Bot] Join bet failed: " . $e->getMessage());
                }
            }
        }
    }

    // ── Strategic betting: existing bots add bets to increase win chance ──

    // Base activity chance
    $activityChance = 45;

    // Boost near end of countdown — last-second drama
    if ($game['status'] === 'active') {
        $elapsed   = time() - strtotime($game['started_at']);
        $remaining = LOTTERY_COUNTDOWN - $elapsed;
        if ($remaining <= 10) $activityChance += 20;
        if ($remaining <= 5)  $activityChance += 20;
    }

    if (random_int(1, 100) > $activityChance) {
        return;
    }

    // Pick a bot already in the round OR a new one — prefer bots with low chance
    $bot = pickStrategicBot($pdo, $roundId, $room);
    if (!$bot) return;

    $botId      = (int)$bot['id'];
    $botBalance = (float)$bot['balance'];
    $intel      = analyzeRound($pdo, $roundId, $botId);

    // Let the strategy engine decide how many bets
    $betCount = decideBetCount($intel, $botBalance, $room);

    if ($betCount <= 0) {
        return;
    }

    error_log(sprintf(
        "[Bot][strategy] Bot #%d room %d: pot=$%.2f myChance=%.1f%% opponents=$%.2f → placing %d bet(s)",
        $botId, $room, $intel['pot'], $intel['my_chance'] * 100,
        $intel['pot'] - $intel['my_total'], $betCount
    ));

    for ($i = 0; $i < $betCount; $i++) {
        try {
            usleep(random_int(80000, 400000));
            $engine->placeBet($botId, $room, generateBotSeed());
        } catch (RuntimeException | InvalidArgumentException $e) {
            error_log("[Bot] Bot #$botId room $room bet failed: " . $e->getMessage());
            break;
        }
    }

    // Periodic top-up check
    if (random_int(1, 20) === 1) {
        topUpBots($pdo, $ledger);
    }
}

// ── Entry — run a tick for each room ─────────────────────────────────────────
try {
    foreach (BOT_ROOMS as $room) {
        runBotTickForRoom($pdo, $engine, $ledger, $room);
    }
} catch (Throwable $e) {
    error_log('[Bot] Fatal: ' . $e->getMessage());
    exit(1);
}
