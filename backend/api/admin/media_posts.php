<?php
/**
 * Admin API — Media Posts log.
 *
 * GET  — fetch media posts with pagination and filters
 * POST — retry a failed post
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 25;
    $offset = ($page - 1) * $limit;
    $platform = $_GET['platform'] ?? '';
    $status = $_GET['status'] ?? '';

    $where = [];
    $params = [];

    if ($platform && in_array($platform, ['instagram', 'telegram'])) {
        $where[] = 'platform = ?';
        $params[] = $platform;
    }

    if ($status && in_array($status, ['queued', 'rendering', 'publishing', 'published', 'failed'])) {
        $where[] = 'status = ?';
        $params[] = $status;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM media_posts $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Fetch
    $stmt = $pdo->prepare(
        "SELECT mp.*, gr.room, gr.winner_id
         FROM media_posts mp
         LEFT JOIN game_rounds gr ON gr.id = mp.round_id
         $whereClause
         ORDER BY mp.created_at DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'posts' => $posts,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit),
    ]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'retry') {
        $postId = (int)($input['post_id'] ?? 0);
        if (!$postId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing post_id']);
            exit;
        }

        $stmt = $pdo->prepare(
            "UPDATE media_posts SET status = 'queued', error_message = NULL, attempts = 0 WHERE id = ? AND status = 'failed'"
        );
        $stmt->execute([$postId]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Post not found or not in failed state']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
