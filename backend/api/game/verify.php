<?php
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/lottery.php';

$gameId = (int)($_GET['game_id'] ?? 0);
if ($gameId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid game_id']); exit;
}

// Try game_rounds first (new system)
$stmt = $pdo->prepare("SELECT * FROM game_rounds WHERE id = ? AND status = 'finished'");
$stmt->execute([$gameId]);
$round = $stmt->fetch(PDO::FETCH_ASSOC);

if ($round) {
    // New system — use stored snapshot
    $snapshot = json_decode($round['final_bets_snapshot'] ?? '[]', true);
    $bets = [];
    $cumulative = [];
    $running = 0.0;
    foreach ($snapshot as $b) {
        $running += (float)$b['amount'];
        $cumulative[] = $running;
        $bets[] = [
            'user_id' => (int)$b['user_id'],
            'email' => $b['email'] ?? '',
            'amount' => (float)$b['amount'],
            'client_seed' => $b['client_seed'] ?? '',
        ];
    }

    echo json_encode([
        'game_id' => $gameId,
        'server_seed' => $round['server_seed'],
        'server_seed_hash' => $round['server_seed_hash'],
        'combined_hash' => $round['final_combined_hash'],
        'rand_unit' => (float)$round['final_rand_unit'],
        'target' => (float)$round['final_target'],
        'total_weight' => (float)$round['final_total_weight'],
        'winner_id' => (int)$round['winner_id'],
        'winner_index' => lowerBound($cumulative, (float)$round['final_target']),
        'bets' => $bets,
        'cumulative_weights' => $cumulative,
        'hash_format' => LOTTERY_HASH_FORMAT,
        'system' => 'game_rounds',
    ]);
    exit;
}

// Fall back to legacy lottery_games
$data = getVerifyData($pdo, $gameId);
echo json_encode($data);
