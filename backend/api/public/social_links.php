<?php
/**
 * Public API — Social Links (no auth required).
 * Used by frontend footer to display social media links.
 *
 * GET — fetch social links
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $stmt = $pdo->query("SELECT telegram_url, instagram_url FROM social_links WHERE id = 1");
    $links = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($links ?: ['telegram_url' => '', 'instagram_url' => '']);
} catch (Throwable $e) {
    echo json_encode(['telegram_url' => '', 'instagram_url' => '']);
}
