<?php
/**
 * Admin API — Media Settings (Instagram, Telegram, Social Links).
 *
 * GET  — fetch all media settings
 * POST — update settings by section (instagram | telegram | social_links | media)
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

requireAdmin();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Fetch all settings ─────────────────────────────────────────────────
if ($method === 'GET') {
    $section = $_GET['section'] ?? 'all';

    $result = [];

    if ($section === 'all' || $section === 'media') {
        $stmt = $pdo->query("SELECT * FROM media_settings WHERE id = 1");
        $result['media'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'instagram_enabled' => 0,
            'telegram_enabled' => 0,
        ];
    }

    if ($section === 'all' || $section === 'instagram') {
        $stmt = $pdo->query("SELECT * FROM instagram_settings WHERE id = 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['allowed_rooms'] = json_decode($row['allowed_rooms'], true) ?: [];
            $row['enabled'] = (bool)$row['enabled'];
            $row['manual_mode'] = (bool)($row['manual_mode'] ?? 0);
        }
        $result['instagram'] = $row ?: [
            'enabled' => false,
            'manual_mode' => false,
            'allowed_rooms' => [],
            'min_win_amount' => 0,
            'max_posts_per_day' => 10,
            'posts_today' => 0,
        ];
    }

    if ($section === 'all' || $section === 'telegram') {
        $stmt = $pdo->query("SELECT * FROM telegram_settings WHERE id = 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['enabled'] = (bool)$row['enabled'];
            $row['post_new_rooms'] = (bool)$row['post_new_rooms'];
            $row['post_finished_rooms'] = (bool)$row['post_finished_rooms'];
        }
        $result['telegram'] = $row ?: [
            'enabled' => false,
            'post_new_rooms' => true,
            'post_finished_rooms' => true,
        ];
    }

    if ($section === 'all' || $section === 'social_links') {
        $stmt = $pdo->query("SELECT * FROM social_links WHERE id = 1");
        $result['social_links'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'telegram_url' => '',
            'instagram_url' => '',
        ];
    }

    // Recent posts log (paginated)
    if ($section === 'all' || $section === 'posts') {
        $postsPage = max(1, (int)($_GET['posts_page'] ?? 1));
        $postsLimit = 15;
        $postsOffset = ($postsPage - 1) * $postsLimit;

        $countStmt = $pdo->query("SELECT COUNT(*) FROM media_posts");
        $postsTotal = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT * FROM media_posts ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$postsLimit, $postsOffset]);
        $result['recent_posts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result['posts_total'] = $postsTotal;
        $result['posts_page'] = $postsPage;
        $result['posts_pages'] = max(1, (int)ceil($postsTotal / $postsLimit));
    }

    echo json_encode($result);
    exit;
}

// ── POST: Update settings ───────────────────────────────────────────────────
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['section'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing section parameter']);
        exit;
    }

    $section = $input['section'];

    switch ($section) {
        case 'media':
            $stmt = $pdo->prepare(
                "UPDATE media_settings SET instagram_enabled = ?, telegram_enabled = ? WHERE id = 1"
            );
            $stmt->execute([
                (int)($input['instagram_enabled'] ?? 0),
                (int)($input['telegram_enabled'] ?? 0),
            ]);
            break;

        case 'instagram':
            $allowedRooms = json_encode($input['allowed_rooms'] ?? []);
            $stmt = $pdo->prepare(
                "UPDATE instagram_settings SET
                    enabled = ?,
                    manual_mode = ?,
                    allowed_rooms = ?,
                    min_win_amount = ?,
                    max_posts_per_day = ?
                 WHERE id = 1"
            );
            $stmt->execute([
                (int)($input['enabled'] ?? 0),
                (int)($input['manual_mode'] ?? 0),
                $allowedRooms,
                (float)($input['min_win_amount'] ?? 0),
                (int)($input['max_posts_per_day'] ?? 10),
            ]);
            break;

        case 'telegram':
            $stmt = $pdo->prepare(
                "UPDATE telegram_settings SET
                    enabled = ?,
                    post_new_rooms = ?,
                    post_finished_rooms = ?
                 WHERE id = 1"
            );
            $stmt->execute([
                (int)($input['enabled'] ?? 0),
                (int)($input['post_new_rooms'] ?? 0),
                (int)($input['post_finished_rooms'] ?? 0),
            ]);
            break;

        case 'social_links':
            $stmt = $pdo->prepare(
                "UPDATE social_links SET telegram_url = ?, instagram_url = ? WHERE id = 1"
            );
            $stmt->execute([
                trim($input['telegram_url'] ?? ''),
                trim($input['instagram_url'] ?? ''),
            ]);
            break;

        case 'reset_instagram_counter':
            $pdo->exec("UPDATE instagram_settings SET posts_today = 0, last_reset_at = NOW() WHERE id = 1");
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown section: ' . $section]);
            exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
