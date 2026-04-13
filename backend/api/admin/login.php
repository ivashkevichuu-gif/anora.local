<?php
/**
 * Admin Login — credentials from environment variables.
 *
 * Password is verified against a bcrypt hash stored in ADMIN_PASSWORD_HASH.
 * If ADMIN_PASSWORD_HASH is not set, falls back to plain comparison with ADMIN_PASSWORD.
 *
 * Environment:
 *   ADMIN_USERNAME      — admin username (default: none, login disabled)
 *   ADMIN_PASSWORD_HASH — bcrypt hash of admin password (preferred)
 *   ADMIN_PASSWORD      — plain password fallback (for initial setup only)
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../includes/cors.php';

$input = json_decode(file_get_contents('php://input'), true);
$inputUser = trim($input['username'] ?? '');
$inputPass = $input['password'] ?? '';

$adminUser = getenv('ADMIN_USERNAME') ?: '';
$adminHash = getenv('ADMIN_PASSWORD_HASH') ?: '';
$adminPass = getenv('ADMIN_PASSWORD') ?: '';

// No credentials configured — reject all
if ($adminUser === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Admin access not configured.']);
    exit;
}

// Verify username
if (!hash_equals($adminUser, $inputUser)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials.']);
    exit;
}

// Verify password: prefer bcrypt hash, fallback to plain
$passwordValid = false;
if ($adminHash !== '') {
    $passwordValid = password_verify($inputPass, $adminHash);
} elseif ($adminPass !== '') {
    $passwordValid = hash_equals($adminPass, $inputPass);
}

if ($passwordValid) {
    $_SESSION['admin'] = true;
    echo json_encode(['message' => 'Admin logged in.']);
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials.']);
}
