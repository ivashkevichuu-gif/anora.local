<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$games = $pdo->query(
    "SELECT g.id, g.status, g.total_pot, g.created_at, g.finished_at,
            u.email as winner_email,
            (SELECT COUNT(*) FROM lottery_bets WHERE game_id = g.id) as player_count
     FROM lottery_games g
     LEFT JOIN users u ON u.id = g.winner_id
     ORDER BY g.id DESC LIMIT 100"
)->fetchAll();

echo json_encode(['games' => $games]);
