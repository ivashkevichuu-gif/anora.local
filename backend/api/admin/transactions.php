<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

global $pdo_read;

$source = $_GET['source'] ?? 'legacy';

if ($source === 'ledger') {
    // Read from ledger_entries table (read replica)
    $txs = $pdo_read->query(
        'SELECT le.id, le.type, le.amount, le.direction, le.balance_after, le.reference_id,
                le.reference_type, le.metadata, le.created_at, u.email
         FROM ledger_entries le
         JOIN users u ON u.id = le.user_id
         ORDER BY le.created_at DESC
         LIMIT 500'
    )->fetchAll();

    echo json_encode(['transactions' => $txs, 'source' => 'ledger']);
} else {
    // Legacy: read from transactions table (read replica)
    $txs = $pdo_read->query(
        'SELECT t.id, t.type, t.amount, t.status, t.note, t.created_at, u.email
         FROM transactions t JOIN users u ON u.id = t.user_id
         ORDER BY t.created_at DESC'
    )->fetchAll();

    echo json_encode(['transactions' => $txs, 'source' => 'legacy']);
}
