<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/lottery.php';

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

$room = (int)($_GET['room'] ?? 1);
if (!in_array($room, [1, 10, 100], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid room. Must be 1, 10, or 100.']);
    exit;
}

// UPDATED: pass userId so getGameState can compute my_stats
$state    = getGameState($pdo, $room, $userId);
$previous = getLastFinishedGame($pdo, $room);

// UPDATED: return current user balance for real-time sidebar display
$balance = null;
if ($userId) {
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $balance = (float)$stmt->fetchColumn();
}

echo json_encode([
    'current'  => $state,
    'previous' => $previous,
    'user_id'  => $userId,
    'balance'  => $balance,   // real-time balance
]);
