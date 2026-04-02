<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

global $pdo_read;

$users = $pdo_read->query(
    'SELECT id, email, balance, bank_details, is_verified, is_banned, fraud_flagged, nickname, is_bot, created_at
     FROM users WHERE id != 1 ORDER BY created_at DESC'
)->fetchAll();

echo json_encode(['users' => $users]);
