<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$users = $pdo->query(
    'SELECT id, email, balance, bank_details, is_verified, is_banned, fraud_flagged, created_at FROM users ORDER BY created_at DESC'
)->fetchAll();

echo json_encode(['users' => $users]);
