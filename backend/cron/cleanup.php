<?php
require_once __DIR__ . '/../config/db.php';

$stmt = $pdo->prepare("DELETE FROM registration_attempts WHERE created_at < NOW() - INTERVAL 7 DAY");
$stmt->execute();
$deleted = $stmt->rowCount();

echo '[Cleanup] Deleted ' . $deleted . ' registration_attempts older than 7 days' . PHP_EOL;

// Expire stale crypto invoices (pending > 24 hours)
$cryptoStmt = $pdo->prepare(
    "UPDATE crypto_invoices SET status = 'expired' WHERE status = 'pending' AND created_at < NOW() - INTERVAL 24 HOUR"
);
$cryptoStmt->execute();
$expired = $cryptoStmt->rowCount();

echo '[Cleanup] Expired ' . $expired . ' stale crypto invoices' . PHP_EOL;
