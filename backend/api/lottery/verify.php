<?php
/**
 * GET /backend/api/lottery/verify.php?game_id=123
 *
 * Returns all data needed to independently verify the winner of a finished game.
 * No authentication required — public auditability.
 */
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/lottery.php';

$gameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;

if ($gameId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'game_id is required.']);
    exit;
}

try {
    $data = getVerifyData($pdo, $gameId);
    echo json_encode($data, JSON_PRETTY_PRINT);
} catch (RuntimeException $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
}
