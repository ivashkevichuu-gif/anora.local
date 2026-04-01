<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$period = $_GET['period'] ?? 'all';

// Build interval expression
$interval = match($period) {
    'day'   => 'NOW() - INTERVAL 1 DAY',
    'month' => 'NOW() - INTERVAL 30 DAY',
    'year'  => 'NOW() - INTERVAL 365 DAY',
    default => null,
};

// Date grouping format
$groupFormat = match($period) {
    'day'   => '%Y-%m-%d %H:00',
    'month' => '%Y-%m-%d',
    'year'  => '%Y-%m',
    default => '%Y-%m',
};

// Helper: build "AND col >= interval" or empty string
function dateWhere(string $col, ?string $interval): string {
    return $interval ? "AND $col >= $interval" : '';
}

$gbDate = dateWhere('gb.created_at', $interval);
$leDate = dateWhere('le.created_at', $interval);

// 1. Games played per room (chart)
$gamesStmt = $pdo->prepare(
    "SELECT gr.room,
            DATE_FORMAT(gb.created_at, ?) AS period_key,
            COUNT(DISTINCT gb.round_id) AS games
     FROM game_bets gb
     JOIN game_rounds gr ON gr.id = gb.round_id
     WHERE gb.user_id = ? $gbDate
     GROUP BY gr.room, period_key
     ORDER BY period_key ASC"
);
$gamesStmt->execute([$groupFormat, $userId]);
$gamesData = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Bets total per period (chart)
$betsStmt = $pdo->prepare(
    "SELECT DATE_FORMAT(le.created_at, ?) AS period_key,
            SUM(le.amount) AS total_bets
     FROM ledger_entries le
     WHERE le.user_id = ? AND le.type = 'bet' $leDate
     GROUP BY period_key
     ORDER BY period_key ASC"
);
$betsStmt->execute([$groupFormat, $userId]);
$betsData = $betsStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Wins per period (chart)
$winsStmt = $pdo->prepare(
    "SELECT DATE_FORMAT(le.created_at, ?) AS period_key,
            SUM(le.amount) AS total_wins
     FROM ledger_entries le
     WHERE le.user_id = ? AND le.type = 'win' $leDate
     GROUP BY period_key
     ORDER BY period_key ASC"
);
$winsStmt->execute([$groupFormat, $userId]);
$winsData = $winsStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Summary totals — each subquery uses its own alias
$periodGamesWhere = $interval ? "AND gb.created_at >= $interval" : '';
$periodBetsWhere  = $interval ? "AND le.created_at >= $interval" : '';

$summaryStmt = $pdo->prepare(
    "SELECT
        (SELECT COUNT(DISTINCT gb.round_id) FROM game_bets gb WHERE gb.user_id = ? $periodGamesWhere) AS period_games,
        (SELECT COALESCE(SUM(le.amount), 0) FROM ledger_entries le WHERE le.user_id = ? AND le.type = 'bet' $periodBetsWhere) AS total_bet_amount,
        (SELECT COALESCE(SUM(le.amount), 0) FROM ledger_entries le WHERE le.user_id = ? AND le.type = 'win' $periodBetsWhere) AS total_win_amount,
        (SELECT COUNT(*) FROM ledger_entries le WHERE le.user_id = ? AND le.type = 'win' $periodBetsWhere) AS total_wins"
);
$summaryStmt->execute([$userId, $userId, $userId, $userId]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$totalBet = (float)$summary['total_bet_amount'];
$totalWin = (float)$summary['total_win_amount'];
$periodGames = (int)$summary['period_games'];

// 5. Per-room summary
$roomStmt = $pdo->prepare(
    "SELECT gr.room,
            COUNT(DISTINCT gb.round_id) AS games,
            COALESCE(SUM(gb.amount), 0) AS total_staked
     FROM game_bets gb
     JOIN game_rounds gr ON gr.id = gb.round_id
     WHERE gb.user_id = ? $gbDate
     GROUP BY gr.room
     ORDER BY gr.room ASC"
);
$roomStmt->execute([$userId]);
$roomData = $roomStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'summary' => [
        'total_games'  => $periodGames,
        'total_bets'   => $totalBet,
        'total_wins'   => $totalWin,
        'net_profit'   => round($totalWin - $totalBet, 2),
        'win_count'    => (int)$summary['total_wins'],
        'win_rate'     => $periodGames > 0
            ? round((int)$summary['total_wins'] / $periodGames * 100, 1)
            : 0,
    ],
    'rooms'       => $roomData,
    'chart_games' => $gamesData,
    'chart_bets'  => $betsData,
    'chart_wins'  => $winsData,
    'period'      => $period,
]);
