<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');

if (!$token) {
    echo json_encode(['error' => 'No token provided.']); exit;
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE verify_token = ? AND is_verified = 0');
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['error' => 'Invalid or already used verification link.']); exit;
}

$pdo->prepare('UPDATE users SET is_verified = 1, verify_token = NULL WHERE id = ?')
    ->execute([$user['id']]);

echo json_encode(['message' => 'Email verified successfully. You can now log in.']);
