<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/lottery.php';

// ── Config ────────────────────────────────────────────────────────────────────
const BOT_BET_CHANCE_PCT    = 60;   // ↑ было 40 → больше давления
const BOT_FORCE_JOIN_BELOW  = 2;
const BOT_MAX_BETS_PER_GAME = 12;   // ↑ было 8
const BOT_MIN_BALANCE       = 50.0;
const BOT_TOPUP_AMOUNT      = 1000.0;

// 🔥 НОВОЕ
const BOT_MAX_MULTI_BETS    = 3;    // сколько ставок за тик может сделать бот
const BOT_SPIKE_CHANCE      = 10;   // % шанс волны активности

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
function getActiveGameForBot(PDO $pdo): ?array {
    $stmt = $pdo->prepare(
        "SELECT * FROM lottery_games
         WHERE status IN ('waiting','countdown')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute();
    return $stmt->fetch() ?: null;
}

function countUniquePlayers(PDO $pdo, int $gameId): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT user_id) FROM lottery_bets WHERE game_id = ?"
    );
    $stmt->execute([$gameId]);
    return (int)$stmt->fetchColumn();
}

function botBetCountInGame(PDO $pdo, int $botId, int $gameId): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM lottery_bets WHERE user_id = ? AND game_id = ?"
    );
    $stmt->execute([$botId, $gameId]);
    return (int)$stmt->fetchColumn();
}

// ── Pick bot ──────────────────────────────────────────────────────────────────
function pickAvailableBot(PDO $pdo, int $gameId): ?array {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.balance FROM users u
         WHERE u.is_bot = 1
           AND u.balance >= ?
           AND (
               SELECT COUNT(*) FROM lottery_bets lb
               WHERE lb.user_id = u.id AND lb.game_id = ?
           ) < ?
         ORDER BY RAND()
         LIMIT 1"
    );
    $stmt->execute([LOTTERY_BET, $gameId, BOT_MAX_BETS_PER_GAME]);
    return $stmt->fetch() ?: null;
}

// ── Top up ────────────────────────────────────────────────────────────────────
function topUpBots(PDO $pdo): void {
    $pdo->prepare(
        "UPDATE users SET balance = balance + ?
         WHERE is_bot = 1 AND balance < ?"
    )->execute([BOT_TOPUP_AMOUNT, BOT_MIN_BALANCE]);
}

// ── Тип бота ──────────────────────────────────────────────────────────────────
function getBotType(): string {
    $roll = random_int(1, 100);

    if ($roll <= 55) return 'normal';
    if ($roll <= 90) return 'aggressive';
    return 'whale';
}

// ── Сколько ставок сделает бот за тик ─────────────────────────────────────────
function getBotMultiBetCount(string $type): int {
    switch ($type) {
        case 'normal':
            return random_int(1, 1);

        case 'aggressive':
            return random_int(1, 2);

        case 'whale':
            return random_int(2, BOT_MAX_MULTI_BETS);
    }
}

// ── Основной тик ──────────────────────────────────────────────────────────────
function runBotTick(PDO $pdo): void {
    $game = getActiveGameForBot($pdo);

    if (!$game) {
        getOrCreateActiveGame($pdo);
        return;
    }

    if ($game['status'] === 'finished') return;

    $gameId = (int)$game['id'];

    // ⏱ усиление перед концом
    $timeBoost = 1;
    if ($game['status'] === 'countdown') {
        $elapsed = time() - strtotime($game['started_at']);
        $remaining = LOTTERY_COUNTDOWN - $elapsed;

        if ($remaining <= 5) {
            $timeBoost = 2; // 🔥 активность ×2 перед концом
        }

        if ($remaining <= 0) return;
    }

    $uniquePlayers = countUniquePlayers($pdo, $gameId);
    $forceBet = $uniquePlayers < BOT_FORCE_JOIN_BELOW;

    if (!$forceBet && random_int(1, 100) > BOT_BET_CHANCE_PCT) {
        return;
    }

    // 🔥 СПАЙК АКТИВНОСТИ
    $spikeMultiplier = 1;
    if (random_int(1, 100) <= BOT_SPIKE_CHANCE) {
        $spikeMultiplier = random_int(2, 4);
        error_log("[Bot] Activity spike x$spikeMultiplier");
    }

    $iterations = $timeBoost * $spikeMultiplier;

    for ($i = 0; $i < $iterations; $i++) {

        usleep(random_int(50000, 300000)); // быстрее

        $bot = pickAvailableBot($pdo, $gameId);
        if (!$bot) continue;

        $botId = (int)$bot['id'];
        $type  = getBotType();
        $multi = getBotMultiBetCount($type);

        for ($j = 0; $j < $multi; $j++) {

            // проверка лимита
            if (botBetCountInGame($pdo, $botId, $gameId) >= BOT_MAX_BETS_PER_GAME) {
                break;
            }

            $seed = generateBotSeed();

            try {
                placeBet($pdo, $botId, $seed);
                error_log("[Bot][$type] Bot #$botId bet ($j/$multi) in game #$gameId");

            } catch (RuntimeException $e) {
                error_log("[Bot] Bot #$botId failed: " . $e->getMessage());
                break;
            }

            // микро задержка между ставками
            usleep(random_int(30000, 120000));
        }
    }

    // пополнение баланса
    if (random_int(1, 15) === 1) {
        topUpBots($pdo);
    }
}

// ── Entry ─────────────────────────────────────────────────────────────────────
try {
    runBotTick($pdo);
} catch (Throwable $e) {
    error_log('[Bot] Fatal: ' . $e->getMessage());
    exit(1);
}