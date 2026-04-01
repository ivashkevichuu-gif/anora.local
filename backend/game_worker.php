<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ledger_service.php';
require_once __DIR__ . '/includes/game_engine.php';

$ledger = new LedgerService($pdo);
$engine = new GameEngine($pdo, $ledger);

echo "[GameWorker] Started.\n";

while (true) {
    try {
        processRooms($pdo, $engine);
    } catch (\Throwable $e) {
        error_log("[GameWorker] Error: " . $e->getMessage());
    }
    sleep(1);
}

function processRooms(PDO $pdo, GameEngine $engine): void {
    foreach ([1, 10, 100] as $room) {
        // Ensure a round exists
        $engine->getOrCreateRound($room);

        // Process active rounds with expired countdown → spinning
        processActiveRounds($pdo, $engine, $room);

        // Process spinning rounds → finish + create new
        processSpinningRounds($pdo, $engine, $room);
    }
}

function processActiveRounds(PDO $pdo, GameEngine $engine, int $room): void {
    $stmt = $pdo->prepare(
        "SELECT id, started_at FROM game_rounds
         WHERE room = ? AND status = 'active'
         AND started_at <= NOW() - INTERVAL 30 SECOND"
    );
    $stmt->execute([$room]);
    while ($round = $stmt->fetch(PDO::FETCH_ASSOC)) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT * FROM game_rounds WHERE id = ? AND status = 'active' FOR UPDATE");
            $lock->execute([$round['id']]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if ($locked && $locked['status'] === 'active') {
                $elapsed = time() - strtotime($locked['started_at']);
                if ($elapsed >= 30) {
                    $pdo->prepare("UPDATE game_rounds SET status = 'spinning', spinning_at = NOW() WHERE id = ?")->execute([$locked['id']]);
                    error_log("[GameWorker] Round #{$locked['id']} → spinning (room $room)");
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("[GameWorker] Error transitioning round #{$round['id']}: " . $e->getMessage());
        }
    }
}

function processSpinningRounds(PDO $pdo, GameEngine $engine, int $room): void {
    $stmt = $pdo->prepare(
        "SELECT id FROM game_rounds WHERE room = ? AND status = 'spinning'"
    );
    $stmt->execute([$room]);
    while ($round = $stmt->fetch(PDO::FETCH_ASSOC)) {
        try {
            $engine->finishRound((int)$round['id']);
            error_log("[GameWorker] Round #{$round['id']} finished (room $room)");
        } catch (\Throwable $e) {
            error_log("[GameWorker] Error finishing round #{$round['id']}: " . $e->getMessage());
        }
    }
}
