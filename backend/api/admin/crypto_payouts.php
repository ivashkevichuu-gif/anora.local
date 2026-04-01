<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input    = json_decode(file_get_contents('php://input'), true);
    $action   = trim($input['action'] ?? '');
    $payoutId = (int)($input['payout_id'] ?? 0);

    if (!in_array($action, ['approve', 'reject'], true) || $payoutId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action or payout_id.']);
        exit;
    }

    require_once __DIR__ . '/../../includes/payout_service.php';

    $npConfig = require __DIR__ . '/../../config/nowpayments.php';
    $client   = new NowPaymentsClient($npConfig);
    $service  = new PayoutService($pdo, $client, $npConfig);

    try {
        if ($action === 'approve') {
            $result = $service->approvePayout($payoutId);
        } else {
            $result = $service->rejectPayout($payoutId);
        }
        echo json_encode($result);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// GET: paginated list with optional status filter
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;
$status  = trim($_GET['status'] ?? '');

$where  = '';
$params = [];

if ($status !== '') {
    $where  = 'WHERE cp.status = ?';
    $params[] = $status;
}

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM crypto_payouts cp $where");
$cntStmt->execute($params);
$totalCount = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$sql = "SELECT cp.id, u.email, cp.amount_usd, cp.wallet_address, cp.currency,
               cp.status, cp.nowpayments_payout_id, cp.created_at
        FROM crypto_payouts cp
        JOIN users u ON u.id = cp.user_id
        $where
        ORDER BY cp.created_at DESC LIMIT ? OFFSET ?";

$stmt = $pdo->prepare($sql);
$i = 1;
foreach ($params as $p) {
    $stmt->bindValue($i++, $p);
}
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i,   $offset,  PDO::PARAM_INT);
$stmt->execute();

echo json_encode([
    'payouts'     => $stmt->fetchAll(),
    'page'        => $page,
    'total_pages' => $totalPages,
]);
