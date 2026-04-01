<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/nickname.php';
requireLogin();

$input    = json_decode(file_get_contents('php://input'), true) ?? [];
$nickname = trim($input['nickname'] ?? '');
$userId   = (int)$_SESSION['user_id'];

// Validate format
$validationError = validateNickname($nickname);
if ($validationError) {
    http_response_code(400);
    echo json_encode(['error' => $validationError]);
    exit;
}

// Check 24-hour cooldown
$cooldownStmt = $pdo->prepare(
    'SELECT nickname_changed_at FROM users WHERE id = ?'
);
$cooldownStmt->execute([$userId]);
$row = $cooldownStmt->fetch();

if ($row && $row['nickname_changed_at']) {
    $lastChange = strtotime($row['nickname_changed_at']);
    $secondsSince = time() - $lastChange;
    if ($secondsSince < 86400) {
        $hoursLeft = ceil((86400 - $secondsSince) / 3600);
        http_response_code(429);
        echo json_encode([
            'error' => "You can change your nickname again in $hoursLeft hour(s).",
        ]);
        exit;
    }
}

// Check uniqueness (case-insensitive)
$uniqueStmt = $pdo->prepare(
    'SELECT id FROM users WHERE LOWER(nickname) = LOWER(?) AND id != ?'
);
$uniqueStmt->execute([$nickname, $userId]);
if ($uniqueStmt->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'This nickname is already taken. Please choose another.']);
    exit;
}

// Update
$pdo->prepare(
    'UPDATE users SET nickname = ?, nickname_changed_at = NOW() WHERE id = ?'
)->execute([$nickname, $userId]);

echo json_encode([
    'message'  => 'Nickname updated successfully.',
    'nickname' => $nickname,
]);
