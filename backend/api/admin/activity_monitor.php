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

// 5. Multi-account IP: 3+ distinct non-bot users sharing an IP in device_fingerprints within last 7 days
$multiAccountIp = $pdo->query(
    "SELECT df.ip_address, GROUP_CONCAT(DISTINCT df.user_id) AS user_ids,
            GROUP_CONCAT(DISTINCT u.email) AS emails,
            COUNT(DISTINCT df.user_id) AS user_count,
            MAX(df.created_at) AS timestamp
     FROM device_fingerprints df
     JOIN users u ON u.id = df.user_id
     WHERE df.created_at >= NOW() - INTERVAL 7 DAY
       AND u.is_bot = 0
     GROUP BY df.ip_address
     HAVING user_count >= 3"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($multiAccountIp as $row) {
    $userIds = explode(',', $row['user_ids']);
    $emails  = explode(',', $row['emails']);
    foreach ($userIds as $i => $uid) {
        $flags[] = [
            'user_id'   => (int)$uid,
            'email'     => $emails[$i] ?? '',
            'flag_type' => 'multi_account_ip',
            'details'   => $row['user_count'] . ' users sharing IP ' . $row['ip_address'] . ' in last 7 days',
            'timestamp' => $row['timestamp'],
        ];
    }
}

// 6. Canvas correlation: 2+ distinct non-bot users sharing the same non-null canvas_hash
$canvasCorrelation = $pdo->query(
    "SELECT df.canvas_hash, GROUP_CONCAT(DISTINCT df.user_id) AS user_ids,
            GROUP_CONCAT(DISTINCT u.email) AS emails,
            COUNT(DISTINCT df.user_id) AS user_count,
            MAX(df.created_at) AS timestamp
     FROM device_fingerprints df
     JOIN users u ON u.id = df.user_id
     WHERE df.canvas_hash IS NOT NULL
       AND u.is_bot = 0
     GROUP BY df.canvas_hash
     HAVING user_count >= 2"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($canvasCorrelation as $row) {
    $userIds = explode(',', $row['user_ids']);
    $emails  = explode(',', $row['emails']);
    foreach ($userIds as $i => $uid) {
        $flags[] = [
            'user_id'   => (int)$uid,
            'email'     => $emails[$i] ?? '',
            'flag_type' => 'canvas_correlation',
            'details'   => $row['user_count'] . ' users sharing canvas hash ' . $row['canvas_hash'],
            'timestamp' => $row['timestamp'],
        ];
    }
}

// 7. Anomalous win rate: non-bot users with win_count/rounds > 40% over last 100 rounds
$anomalousWinRate = $pdo->query(
    "SELECT sub.user_id, u.email, sub.win_count, sub.rounds_participated,
            ROUND(sub.win_count / sub.rounds_participated * 100, 2) AS win_rate
     FROM (
         SELECT gb_inner.user_id,
                COUNT(DISTINCT gb_inner.round_id) AS rounds_participated,
                SUM(CASE WHEN gr_inner.winner_id = gb_inner.user_id THEN 1 ELSE 0 END) AS win_count
         FROM game_bets gb_inner
         JOIN game_rounds gr_inner ON gr_inner.id = gb_inner.round_id
         WHERE gr_inner.status = 'finished'
           AND gr_inner.id >= (SELECT COALESCE(MAX(id), 0) - 99 FROM game_rounds WHERE status = 'finished')
         GROUP BY gb_inner.user_id
     ) sub
     JOIN users u ON u.id = sub.user_id
     WHERE u.is_bot = 0
       AND sub.rounds_participated > 0
       AND (sub.win_count / sub.rounds_participated) > 0.40"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($anomalousWinRate as $row) {
    $flags[] = [
        'user_id'   => (int)$row['user_id'],
        'email'     => $row['email'],
        'flag_type' => 'anomalous_win_rate',
        'details'   => 'Win rate ' . $row['win_rate'] . '% (' . $row['win_count'] . ' wins in ' . $row['rounds_participated'] . ' rounds over last 100 rounds)',
        'timestamp' => date('Y-m-d H:i:s'),
    ];
}

// 8. Rapid bet speed: non-bot users with 10+ bets in any 10-second window
$rapidBetSpeed = $pdo->query(
    "SELECT gb1.user_id, u.email,
            COUNT(*) AS bet_count,
            gb1.created_at AS window_start,
            MAX(gb2.created_at) AS timestamp
     FROM game_bets gb1
     JOIN game_bets gb2 ON gb2.user_id = gb1.user_id
       AND gb2.created_at >= gb1.created_at
       AND gb2.created_at <= DATE_ADD(gb1.created_at, INTERVAL 10 SECOND)
     JOIN users u ON u.id = gb1.user_id
     WHERE u.is_bot = 0
     GROUP BY gb1.user_id, u.email, gb1.created_at
     HAVING bet_count >= 10
     ORDER BY gb1.user_id, gb1.created_at"
)->fetchAll(PDO::FETCH_ASSOC);

// Deduplicate: only keep the first flagged window per user
$rapidBetSeen = [];
foreach ($rapidBetSpeed as $row) {
    $uid = (int)$row['user_id'];
    if (isset($rapidBetSeen[$uid])) {
        continue;
    }
    $rapidBetSeen[$uid] = true;
    $flags[] = [
        'user_id'   => $uid,
        'email'     => $row['email'],
        'flag_type' => 'rapid_bet_speed',
        'details'   => $row['bet_count'] . ' bets within 10 seconds starting at ' . $row['window_start'],
        'timestamp' => $row['timestamp'],
    ];
}

echo json_encode(['flags' => $flags]);
