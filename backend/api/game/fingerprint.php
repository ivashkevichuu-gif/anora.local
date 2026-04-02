<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$input = json_decode(file_get_contents('php://input'), true);
$canvasHash = isset($input['canvas_hash']) && is_string($input['canvas_hash'])
    ? substr(trim($input['canvas_hash']), 0, 64)
    : null;

$stmt = $pdo->prepare(
    "INSERT INTO device_fingerprints (user_id, session_id, ip_address, user_agent, canvas_hash)
     VALUES (:user_id, :session_id, :ip_address, :user_agent, :canvas_hash)"
);
$stmt->execute([
    ':user_id'     => $_SESSION['user_id'],
    ':session_id'  => session_id(),
    ':ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
    ':user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ':canvas_hash' => $canvasHash,
]);

echo json_encode(['ok' => true]);
