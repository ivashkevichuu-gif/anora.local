<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

global $pdo_read;

if (!empty($_GET['round_id'])) {
    $roundId = (int) $_GET['round_id'];

    $stmt = $pdo_read->prepare(
        "SELECT gr.id, gr.room, gr.total_pot, gr.winner_id,
                COALESCE(u.nickname, u.email) AS winner_name,
                gr.winner_net, gr.commission, gr.referral_bonus,
                gr.finished_at, gr.server_seed, gr.final_combined_hash
         FROM game_rounds gr
         LEFT JOIN users u ON u.id = gr.winner_id
         WHERE gr.id = ? AND gr.status = 'finished'"
    );
    $stmt->execute([$roundId]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$round) {
        http_response_code(404);
        echo json_encode(['error' => 'Round not found']);
        exit;
    }

    // Cast numeric fields
    $round['id']         = (int) $round['id'];
    $round['room']       = (int) $round['room'];
    $round['total_pot']  = (float) $round['total_pot'];
    $round['winner_id']  = $round['winner_id'] !== null ? (int) $round['winner_id'] : null;
    $round['winner_net'] = (float) $round['winner_net'];
    $round['commission'] = (float) $round['commission'];
    $round['referral_bonus'] = (float) $round['referral_bonus'];

    // Fetch all bets for this round
    $betsStmt = $pdo_read->prepare(
        "SELECT gb.user_id, COALESCE(u.nickname, u.email) AS display_name,
                gb.amount, gb.client_seed
         FROM game_bets gb
         JOIN users u ON u.id = gb.user_id
         WHERE gb.round_id = ?
         ORDER BY gb.id ASC"
    );
    $betsStmt->execute([$roundId]);
    $bets = $betsStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalPot = $round['total_pot'];
    $bets = array_map(function ($bet) use ($totalPot) {
        return [
            'user_id'      => (int) $bet['user_id'],
            'display_name' => $bet['display_name'],
            'amount'       => (float) $bet['amount'],
            'chance'       => $totalPot > 0 ? round((float) $bet['amount'] / $totalPot, 6) : 0.0,
            'client_seed'  => $bet['client_seed'],
        ];
    }, $bets);

    echo json_encode(['round' => $round, 'bets' => $bets]);
    exit;
}

// ── List mode: paginated finished rounds with RTP calculations ──────────────
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset  = ($page - 1) * $perPage;

$where  = ["gr.status = 'finished'"];
$params = [];

// Room filter — only accept valid rooms
if (isset($_GET['room']) && in_array((int) $_GET['room'], [1, 10, 100], true)) {
    $where[]  = 'gr.room = ?';
    $params[] = (int) $_GET['room'];
}

// Date filters
if (!empty($_GET['date_from'])) {
    $df = $_GET['date_from'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) {
        $where[]  = 'gr.finished_at >= ?';
        $params[] = $df . ' 00:00:00';
    }
}
if (!empty($_GET['date_to'])) {
    $dt = $_GET['date_to'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
        $where[]  = 'gr.finished_at <= ?';
        $params[] = $dt . ' 23:59:59';
    }
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// ── Total count ─────────────────────────────────────────────────────────────
$countStmt = $pdo_read->prepare("SELECT COUNT(*) FROM game_rounds gr $whereSQL");
$countStmt->execute($params);
$totalRounds = (int) $countStmt->fetchColumn();
$totalPages  = (int) ceil($totalRounds / max(1, $perPage));

// ── Aggregate sums for RTP ──────────────────────────────────────────────────
$aggStmt = $pdo_read->prepare(
    "SELECT COALESCE(SUM(gr.total_pot), 0) AS total_pot_sum,
            COALESCE(SUM(gr.winner_net), 0) AS total_payout_sum
     FROM game_rounds gr $whereSQL"
);
$aggStmt->execute($params);
$agg = $aggStmt->fetch(PDO::FETCH_ASSOC);
$totalPotSum    = (float) $agg['total_pot_sum'];
$totalPayoutSum = (float) $agg['total_payout_sum'];

$globalRtp = $totalPotSum > 0 ? round($totalPayoutSum / $totalPotSum * 100, 2) : 0.0;

// ── RTP by room ─────────────────────────────────────────────────────────────
$rtpByRoom = [];
$rtpStmt = $pdo_read->prepare(
    "SELECT gr.room,
            COALESCE(SUM(gr.total_pot), 0) AS pot,
            COALESCE(SUM(gr.winner_net), 0) AS payout
     FROM game_rounds gr
     $whereSQL
     GROUP BY gr.room"
);
$rtpStmt->execute($params);
while ($row = $rtpStmt->fetch(PDO::FETCH_ASSOC)) {
    $pot = (float) $row['pot'];
    $payout = (float) $row['payout'];
    $rtpByRoom[(string) $row['room']] = $pot > 0 ? round($payout / $pot * 100, 2) : 0.0;
}

// ── Paginated rounds ────────────────────────────────────────────────────────
$dataStmt = $pdo_read->prepare(
    "SELECT gr.id, gr.room, gr.total_pot, gr.winner_id,
            COALESCE(u.nickname, u.email) AS winner_name,
            gr.winner_net, gr.commission, gr.referral_bonus, gr.finished_at,
            (SELECT COUNT(DISTINCT gb.user_id) FROM game_bets gb WHERE gb.round_id = gr.id) AS player_count
     FROM game_rounds gr
     LEFT JOIN users u ON u.id = gr.winner_id
     $whereSQL
     ORDER BY gr.finished_at DESC
     LIMIT $perPage OFFSET $offset"
);
$dataStmt->execute($params);
$rounds = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// Cast types
$rounds = array_map(function ($r) {
    return [
        'id'             => (int) $r['id'],
        'room'           => (int) $r['room'],
        'total_pot'      => (float) $r['total_pot'],
        'winner_id'      => $r['winner_id'] !== null ? (int) $r['winner_id'] : null,
        'winner_name'    => $r['winner_name'],
        'winner_net'     => (float) $r['winner_net'],
        'commission'     => (float) $r['commission'],
        'referral_bonus' => (float) $r['referral_bonus'],
        'finished_at'    => $r['finished_at'],
        'player_count'   => (int) $r['player_count'],
    ];
}, $rounds);

echo json_encode([
    'rounds'           => $rounds,
    'global_rtp'       => $globalRtp,
    'rtp_by_room'      => (object) $rtpByRoom,
    'total_rounds'     => $totalRounds,
    'total_pot_sum'    => $totalPotSum,
    'total_payout_sum' => $totalPayoutSum,
    'page'             => $page,
    'per_page'         => $perPage,
    'total_pages'      => $totalPages,
]);
