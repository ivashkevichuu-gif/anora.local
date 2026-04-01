<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$flags = [];

// 1. Win streaks: users with >= 10 wins in 24h (non-bot)
$winStreaks = $pdo->query(
    "SELECT gr.winner_id AS user_id, u.email, COUNT(*) AS win_count,
            MAX(gr.finished_at) AS timestamp
     FROM game_rounds gr
     JOIN users u ON u.id = gr.winner_id
     WHERE gr.status = 'finished'
       AND gr.finished_at >= NOW() - INTERVAL 24 HOUR
       AND u.is_bot = 0
     GROUP BY gr.winner_id, u.email
     HAVING win_count >= 10"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($winStreaks as $row) {
    $flags[] = [
        'user_id'   => (int)$row['user_id'],
        'email'     => $row['email'],
        'flag_type' => 'win_streak',
        'details'   => $row['win_count'] . ' wins in last 24 hours',
        'timestamp' => $row['timestamp'],
    ];
}

// 2. High velocity: users with >= 50 bets in last 5 minutes (non-bot)
$highVelocity = $pdo->query(
    "SELECT gb.user_id, u.email, COUNT(*) AS bet_count,
            MAX(gb.created_at) AS timestamp
     FROM game_bets gb
     JOIN users u ON u.id = gb.user_id
     WHERE gb.created_at >= NOW() - INTERVAL 5 MINUTE
       AND u.is_bot = 0
     GROUP BY gb.user_id, u.email
     HAVING bet_count >= 50"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($highVelocity as $row) {
    $flags[] = [
        'user_id'   => (int)$row['user_id'],
        'email'     => $row['email'],
        'flag_type' => 'high_velocity',
        'details'   => $row['bet_count'] . ' bets in last 5 minutes',
        'timestamp' => $row['timestamp'],
    ];
}

// 3. IP correlation: 2+ non-bot users sharing registration_ip
$ipCorrelation = $pdo->query(
    "SELECT registration_ip, GROUP_CONCAT(id) AS user_ids,
            GROUP_CONCAT(email) AS emails, COUNT(*) AS user_count
     FROM users
     WHERE is_bot = 0 AND registration_ip IS NOT NULL AND registration_ip != ''
     GROUP BY registration_ip
     HAVING user_count >= 2"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($ipCorrelation as $row) {
    $userIds = explode(',', $row['user_ids']);
    $emails  = explode(',', $row['emails']);
    foreach ($userIds as $i => $uid) {
        $flags[] = [
            'user_id'   => (int)$uid,
            'email'     => $emails[$i] ?? '',
            'flag_type' => 'ip_correlation',
            'details'   => $row['user_count'] . ' users sharing IP ' . $row['registration_ip'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }
}

// 4. Large withdrawals: > 1000 in last 7 days
$largeWithdrawals = $pdo->query(
    "SELECT le.user_id, u.email, le.amount, le.created_at AS timestamp
     FROM ledger_entries le
     JOIN users u ON u.id = le.user_id
     WHERE le.type = 'withdrawal' AND le.amount > 1000.00
       AND le.created_at >= NOW() - INTERVAL 7 DAY
     ORDER BY le.amount DESC"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($largeWithdrawals as $row) {
    $flags[] = [
        'user_id'   => (int)$row['user_id'],
        'email'     => $row['email'],
        'flag_type' => 'large_withdrawal',
        'details'   => 'Withdrawal of $' . number_format((float)$row['amount'], 2),
        'timestamp' => $row['timestamp'],
    ];
}

echo json_encode(['flags' => $flags]);
