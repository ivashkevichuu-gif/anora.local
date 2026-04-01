<?php
/**
 * Cron-friendly game worker wrapper.
 * Runs the game loop for ~55 seconds then exits cleanly.
 * Set up a cron job to run this every minute:
 *   * * * * * /usr/local/bin/php /home/USERNAME/domains/anora.bet/public_html/backend/game_worker_cron.php >> /home/USERNAME/game_worker.log 2>&1
 *
 * This ensures the worker restarts every minute via cron,
 * with no overlap (the lock file prevents double-execution).
 */
declare(strict_types=1);

// Prevent double-execution via lock file
$lockFile = __DIR__ . '/game_worker.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    // Another instance is already running
    exit(0);
}

// Write PID for monitoring
fwrite($fp, (string)getmypid());

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ledger_service.php';
require_once __DIR__ . '/includes/game_engine.php';

$ledger = new LedgerService($pdo);
$engine = new GameEngine($pdo, $ledger);

$startTime = time();
$maxRuntime = 55; // seconds — exit before next cron tick

while ((time() - $startTime) < $maxRuntime) {
    try {
        foreach ([1, 10, 100] as $room) {
            $engine->getOrCreateRound($room);

            // Process active rounds with expired countdown → spinning
            $stmt = $pdo->prepare(
                "SELECT id FROM game_rounds
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
                        $pdo->prepare("UPDATE game_rounds SET status = 'spinning', spinning_at = NOW() WHERE id = ?")->execute([$locked['id']]);
                    }
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log("[GameWorkerCron] Transition error: " . $e->getMessage());
                }
            }

            // Process spinning rounds → finish
            $stmt2 = $pdo->prepare("SELECT id FROM game_rounds WHERE room = ? AND status = 'spinning'");
            $stmt2->execute([$room]);
            while ($round = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                try {
                    $engine->finishRound((int)$round['id']);
                } catch (\Throwable $e) {
                    error_log("[GameWorkerCron] Finish error: " . $e->getMessage());
                }
            }
        }
    } catch (\Throwable $e) {
        error_log("[GameWorkerCron] Loop error: " . $e->getMessage());
    }

    sleep(1);
}

// Release lock
flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);
