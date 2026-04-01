<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/lottery.php';
require_once __DIR__ . '/includes/ledger_service.php';
require_once __DIR__ . '/includes/game_engine.php';

// ── Config ────────────────────────────────────────────────────────────────────
const BOT_BET_CHANCE_PCT    = 60;
const BOT_FORCE_JOIN_BELOW  = 2;
const BOT_MAX_BETS_PER_GAME = 12;
const BOT_MIN_BALANCE       = 50.0;
const BOT_TOPUP_AMOUNT      = 1000.0;
const BOT_MAX_MULTI_BETS    = 3;
const BOT_SPIKE_CHANCE      = 10;
const BOT_MAX_BALANCE       = 50000;

// Rooms the bot participates in
const BOT_ROOMS = [1, 10, 100];

$ledger = new LedgerService($pdo);
$engine = new GameEngine($pdo, $ledger);

// ── Seed ──────────────────────────────────────────────────────────────────────
function generateBotSeed(): string {
    return implode('-', [
        random_int(0, 4294967295),
        random_int(0, 4294967295),
        random_int(0, 4294967295),
        random_int(0, 4294967295),
    ]);
}

// ── Game ──────────────────────────────────────────────────────────────────────
function getActiveGameForBot(PDO $pdo, int $room): ?array {
    $stmt = $pdo->prepare(
        "SELECT * FROM game_rounds
         WHERE status IN ('waiting','active') AND room = ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$room]);
    return $stmt->fetch() ?: null;
}

function countUniquePlayers(PDO $pdo, int $roundId): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT user_id) FROM game_bets WHERE round_id = ?"
    );
    $stmt->execute([$roundId]);
    return (int)$stmt->fetchColumn();
}

function botBetCountInGame(PDO $pdo, int $botId, int $roundId): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM game_bets WHERE user_id = ? AND round_id = ?"
    );
    $stmt->execute([$botId, $roundId]);
    return (int)$stmt->fetchColumn();
}

// ── Pick bot — balance must cover the room's bet amount ───────────────────────
function pickAvailableBot(PDO $pdo, int $roundId, int $room): ?array {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.balance FROM users u
         WHERE u.is_bot = 1
           AND u.balance >= :betAmount
           AND (
               SELECT COUNT(*) FROM game_bets gb
               WHERE gb.user_id = u.id AND gb.round_id = :roundId
           ) < :maxBets
         ORDER BY RAND()
         LIMIT 1"
    );
    $stmt->execute([
        ':betAmount' => $room,
        ':roundId'   => $roundId,
        ':maxBets'   => BOT_MAX_BETS_PER_GAME,
    ]);
    return $stmt->fetch() ?: null;
}

// ── Top up ────────────────────────────────────────────────────────────────────
function topUpBots(PDO $pdo, LedgerService $ledger): void {
    $stmt = $pdo->prepare(
        "SELECT id, balance FROM users WHERE is_bot = 1 AND balance < ?"
    );
    $stmt->execute([BOT_MIN_BALANCE]);
    $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bots as $bot) {
        $botId = (int)$bot['id'];
        $balance = (float)$bot['balance'];

        // Skip top-up if balance exceeds cap
        if ($balance >= BOT_MAX_BALANCE) {
            continue;
        }

        try {
            $pdo->beginTransaction();
            $ledger->addEntry(
                $botId,
                'deposit',
                BOT_TOPUP_AMOUNT,
                'credit',
                'bot_topup:' . time() . ':' . $botId,
                'bot_topup',
                ['source' => 'bot_runner', 'is_bot' => true]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("[Bot] Top-up failed for bot #$botId: " . $e->getMessage());
        }
    }
}

// ── Bot type ──────────────────────────────────────────────────────────────────
function getBotType(): string {
    $roll = random_int(1, 100);
    if ($roll <= 55) return 'normal';
    if ($roll <= 90) return 'aggressive';
    return 'whale';
}

function getBotMultiBetCount(string $type): int {
    switch ($type) {
        case 'aggressive': return random_int(1, 2);
        case 'whale':      return random_int(2, BOT_MAX_MULTI_BETS);
        default:           return 1;
    }
}

// ── Tick for a single room ────────────────────────────────────────────────────
function runBotTickForRoom(PDO $pdo, GameEngine $engine, LedgerService $ledger, int $room): void {
    $game = getActiveGameForBot($pdo, $room);

    if (!$game) {
        $engine->getOrCreateRound($room);
        return;
    }

    $roundId = (int)$game['id'];

    // Boost activity near end of countdown
    $timeBoost = 1;
    if ($game['status'] === 'active') {
        $elapsed   = time() - strtotime($game['started_at']);
        $remaining = LOTTERY_COUNTDOWN - $elapsed;
        if ($remaining <= 5)  $timeBoost = 2;
        if ($remaining <= 0)  return;
    }

    $uniquePlayers = countUniquePlayers($pdo, $roundId);
    $forceBet      = $uniquePlayers < BOT_FORCE_JOIN_BELOW;

    if (!$forceBet && random_int(1, 100) > BOT_BET_CHANCE_PCT) {
        return;
    }

    // Activity spike
    $spikeMultiplier = 1;
    if (random_int(1, 100) <= BOT_SPIKE_CHANCE) {
        $spikeMultiplier = random_int(2, 4);
        error_log("[Bot] Room $room activity spike x$spikeMultiplier");
    }

    $iterations = $timeBoost * $spikeMultiplier;

    for ($i = 0; $i < $iterations; $i++) {
        usleep(random_int(50000, 300000));

        $bot = pickAvailableBot($pdo, $roundId, $room);
        if (!$bot) continue;

        $botId = (int)$bot['id'];
        $type  = getBotType();
        $multi = getBotMultiBetCount($type);

        for ($j = 0; $j < $multi; $j++) {
            if (botBetCountInGame($pdo, $botId, $roundId) >= BOT_MAX_BETS_PER_GAME) {
                break;
            }

            $seed = generateBotSeed();

            try {
                $engine->placeBet($botId, $room, $seed);
                error_log("[Bot][$type] Bot #$botId bet ($j/$multi) in room $room round #$roundId");
            } catch (RuntimeException | InvalidArgumentException $e) {
                error_log("[Bot] Bot #$botId room $room failed: " . $e->getMessage());
                break;
            }

            usleep(random_int(30000, 120000));
        }
    }

    if (random_int(1, 15) === 1) {
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
