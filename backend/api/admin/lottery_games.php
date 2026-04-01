<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

// Check if game_rounds table has data (new system)
$hasGameRounds = false;
try {
    $check = $pdo->query("SELECT COUNT(*) FROM game_rounds");
    $hasGameRounds = (int)$check->fetchColumn() > 0;
} catch (PDOException $e) {
    // Table doesn't exist — use legacy only
}

$newGames = [];
if ($hasGameRounds) {
    $newGames = $pdo->query(
        "SELECT gr.id, gr.status, gr.room, gr.total_pot, gr.created_at, gr.finished_at,
                gr.commission, gr.referral_bonus, gr.winner_net, gr.payout_id,
                u.email AS winner_email,
                (SELECT COUNT(*) FROM game_bets WHERE round_id = gr.id) AS player_count
         FROM game_rounds gr
         LEFT JOIN users u ON u.id = gr.winner_id
         ORDER BY gr.id DESC LIMIT 100"
    )->fetchAll();
}

// Legacy lottery_games
$legacyGames = $pdo->query(
    "SELECT g.id, g.status, g.room, g.total_pot, g.created_at, g.finished_at,
            g.commission, g.referral_bonus, g.winner_net, g.payout_id,
            u.email AS winner_email,
            (SELECT COUNT(*) FROM lottery_bets WHERE game_id = g.id) AS player_count
     FROM lottery_games g
     LEFT JOIN users u ON u.id = g.winner_id
     ORDER BY g.id DESC LIMIT 100"
)->fetchAll();

echo json_encode([
    'games'        => $newGames,
    'legacy_games' => $legacyGames,
]);
