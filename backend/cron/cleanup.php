<?php
require_once __DIR__ . '/../config/db.php';

$stmt = $pdo->prepare("DELETE FROM registration_attempts WHERE created_at < NOW() - INTERVAL 7 DAY");
$stmt->execute();
$deleted = $stmt->rowCount();

echo '[Cleanup] Deleted ' . $deleted . ' registration_attempts older than 7 days' . PHP_EOL;
