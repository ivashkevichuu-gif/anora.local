<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$input = json_decode(file_get_contents('php://input'), true);
$bank  = trim($input['bank_details'] ?? '');

$pdo->prepare('UPDATE users SET bank_details = ? WHERE id = ?')
    ->execute([$bank, $_SESSION['user_id']]);

echo json_encode(['message' => 'Bank details saved.']);
