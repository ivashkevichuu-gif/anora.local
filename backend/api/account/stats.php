<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$period = $_GET['period'] ?? 'all'; // day, month, year, all

// Build date filter
$dateFilter = '';
switch ($period) {
    case 'day':   $dateFilter = "AND le.created_at >= NOW() - INTERVAL 1 DAY"; break;
    case 'month': $dateFilter = "AND le.created_at >= NOW() - INTERVAL 30 DAY"; break;
    case 'year':  $dateFilter = "AND le.created_at >= NOW() - INTERVAL 365 DAY"; break;
    default:      $dateFilter = ''; break;
}

// Date grouping format
$groupFormat = match($period) {
    'day'   => '%Y-%m-%d %H:00',
    'month' => '%Y-%m-%d',
    'year'  => '%Y-%m',
    default => '%Y-%m',
};

// 1. Games played per room
$gamesStmt = $pdo->prepare(
    "SELECT gr.room,
            DATE_FORMAT(gb.created_at, ?) AS period_key,
            COUNT(DISTINCT gb.round_id) AS games
     FROM game_bets gb
     JOIN game_rounds gr ON gr.id = gb.round_id
     WHERE gb.user_id = ? $dateFilter
     GROUP BY gr.room, period_key
     ORDER BY period_key ASC"
);
// Replace le.created_at with gb.created_at in dateFilter for this query
$gamesDateFilter = str_replace('le.created_at', 'gb.created_at', $dateFilter);
$gamesStmt = $pdo->prepare(
    "SELECT gr.room,
            DATE_FORMAT(gb.created_at, ?) AS period_key,
            COUNT(DISTINCT gb.round_id) AS games
     FROM game_bets gb
     JOIN game_rounds gr ON gr.id = gb.round_id
     WHERE gb.user_id = ? $gamesDateFilter
     GROUP BY gr.room, period_key
     ORDER BY period_key ASC"
);
$gamesStmt->execute([$groupFormat, $userId]);
$gamesData = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Bets total per period
$betsDateFilter = str_replace('le.created_at', 'le.created_at', $dateFilter);
$betsStmt = $pdo->prepare(
    "SELECT DATE_FORMAT(le.created_at, ?) AS period_key,
            SUM(le.amount) AS total_bets
     FROM ledger_entries le
     WHERE le.user_id = ? AND le.type = 'bet' $betsDateFilter
     GROUP BY period_key
     ORDER BY period_key ASC"
);
$betsStmt->execute([$groupFormat, $userId]);
$betsData = $betsStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Wins per period
$winsStmt = $pdo->prepare(
    "SELECT DATE_FORMAT(le.created_at, ?) AS period_key,
            SUM(le.amount) AS total_wins
     FROM ledger_entries le
     WHERE le.user_id = ? AND le.type = 'win' $dateFilter
     GROUP BY period_key
     ORDER BY period_key ASC"
);
$winsStmt->execute([$groupFormat, $userId]);
$winsData = $winsStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Summary totals
$summaryStmt = $pdo->prepare(
    "SELECT
        (SELECT COUNT(DISTINCT gb2.round_id) FROM game_bets gb2 WHERE gb2.user_id = ?) AS total_games,
        (SELECT COALESCE(SUM(le2.amount), 0) FROM ledger_entries le2 WHERE le2.user_id = ? AND le2.type = 'bet' $dateFilter) AS total_bet_amount,
        (SELECT COALESCE(SUM(le3.amount), 0) FROM ledger_entries le3 WHERE le3.user_id = ? AND le3.type = 'win' $dateFilter) AS total_win_amount,
        (SELECT COUNT(*) FROM ledger_entries le4 WHERE le4.user_id = ? AND le4.type = 'win' $dateFilter) AS total_wins,
        (SELECT COUNT(DISTINCT gb3.round_id) FROM game_bets gb3 WHERE gb3.user_id = ? " . str_replace('le.created_at', 'gb3.created_at', $dateFilter) . ") AS period_games"
);
$summaryStmt->execute([$userId, $userId, $userId, $userId, $userId]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$totalBet = (float)$summary['total_bet_amount'];
$totalWin = (float)$summary['total_win_amount'];

// 5. Per-room summary
$roomStmt = $pdo->prepare(
    "SELECT gr.room,
            COUNT(DISTINCT gb.round_id) AS games,
            COALESCE(SUM(gb.amount), 0) AS total_staked
     FROM game_bets gb
     JOIN game_rounds gr ON gr.id = gb.round_id
     WHERE gb.user_id = ? " . str_replace('le.created_at', 'gb.created_at', $dateFilter) . "
     GROUP BY gr.room
     ORDER BY gr.room ASC"
);
$roomStmt->execute([$userId]);
$roomData = $roomStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'summary' => [
        'total_games'  => (int)$summary['period_games'],
        'total_bets'   => $totalBet,
        'total_wins'   => $totalWin,
        'net_profit'   => round($totalWin - $totalBet, 2),
        'win_count'    => (int)$summary['total_wins'],
        'win_rate'     => (int)$summary['period_games'] > 0
            ? round((int)$summary['total_wins'] / (int)$summary['period_games'] * 100, 1)
            : 0,
    ],
    'rooms'      => $roomData,
    'chart_games' => $gamesData,
    'chart_bets'  => $betsData,
    'chart_wins'  => $winsData,
    'period'      => $period,
]);
