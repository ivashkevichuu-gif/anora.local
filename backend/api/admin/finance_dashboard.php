<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ledger_service.php';
requireAdmin();

// total_deposits: SUM(amount) from ledger_entries WHERE type IN ('deposit','crypto_deposit')
// AND direction='credit' AND user is_bot=0
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(le.amount), 0) AS total
     FROM ledger_entries le
     JOIN users u ON u.id = le.user_id
     WHERE le.type IN ('deposit', 'crypto_deposit')
       AND le.direction = 'credit'
       AND u.is_bot = 0"
);
$stmt->execute();
$totalDeposits = (float) $stmt->fetchColumn();

// total_withdrawals: SUM(amount) from ledger_entries WHERE type IN ('withdrawal','crypto_withdrawal')
// AND direction='debit' AND user is_bot=0
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(le.amount), 0) AS total
     FROM ledger_entries le
     JOIN users u ON u.id = le.user_id
     WHERE le.type IN ('withdrawal', 'crypto_withdrawal')
       AND le.direction = 'debit'
       AND u.is_bot = 0"
);
$stmt->execute();
$totalWithdrawals = (float) $stmt->fetchColumn();

// system_profit: balance from user_balances WHERE user_id = SYSTEM_USER_ID
$stmt = $pdo->prepare("SELECT COALESCE(balance, 0) FROM user_balances WHERE user_id = ?");
$stmt->execute([SYSTEM_USER_ID]);
$systemProfit = (float) $stmt->fetchColumn();

// net_platform_position: total_deposits - total_withdrawals
$netPlatformPosition = $totalDeposits - $totalWithdrawals;

// total_bets_volume: SUM(amount) from ledger_entries WHERE type='bet' AND direction='debit' AND is_bot=0
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(le.amount), 0) AS total
     FROM ledger_entries le
     JOIN users u ON u.id = le.user_id
     WHERE le.type = 'bet'
       AND le.direction = 'debit'
       AND u.is_bot = 0"
);
$stmt->execute();
$totalBetsVolume = (float) $stmt->fetchColumn();

// total_payouts_volume: SUM(amount) from ledger_entries WHERE type='win' AND direction='credit' AND is_bot=0
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(le.amount), 0) AS total
     FROM ledger_entries le
     JOIN users u ON u.id = le.user_id
     WHERE le.type = 'win'
       AND le.direction = 'credit'
       AND u.is_bot = 0"
);
$stmt->execute();
$totalPayoutsVolume = (float) $stmt->fetchColumn();

echo json_encode([
    'total_deposits'        => $totalDeposits,
    'total_withdrawals'     => $totalWithdrawals,
    'system_profit'         => $systemProfit,
    'net_platform_position' => $netPlatformPosition,
    'total_bets_volume'     => $totalBetsVolume,
    'total_payouts_volume'  => $totalPayoutsVolume,
]);
