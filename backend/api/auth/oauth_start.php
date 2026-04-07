<?php
/**
 * GET /api/auth/oauth_start.php?provider={google|apple}
 *
 * Initiates OAuth flow: generates state + nonce, saves to session,
 * redirects to provider's authorization URL.
 *
 * Feature: oauth-social-login
 * Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5, 8.8, 9.5
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/structured_logger.php';
require_once __DIR__ . '/../../includes/oauth_service.php';

$logger = StructuredLogger::getInstance();
$provider = $_GET['provider'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

// Validate provider
if (!in_array($provider, ['google'], true)) {
    // Apple Sign-In — commented for future use
    // if (!in_array($provider, ['google', 'apple'], true)) {
    $logger->warning('OAuth start: unsupported provider', [
        'provider' => $provider,
        'ip'       => $ip,
    ]);
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported provider']);
    exit;
}

// Load OAuth config
$oauthConfig = require __DIR__ . '/../../config/oauth.php';

try {
    $oauthService = new OAuthService($oauthConfig);

    // Generate state and nonce (32 bytes each = 64 hex chars)
    $state = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(32));

    // Save to session
    $_SESSION['oauth_state']    = $state;
    $_SESSION['oauth_nonce']    = $nonce;
    $_SESSION['oauth_provider'] = $provider;

    $authUrl = $oauthService->getAuthorizationUrl($provider, $state, $nonce);

    $logger->info('OAuth start: redirecting to provider', [
        'provider' => $provider,
        'ip'       => $ip,
    ]);

    // Redirect to provider
    header('Location: ' . $authUrl, true, 302);
    exit;
} catch (RuntimeException $e) {
    $logger->error('OAuth start failed', [
        'provider' => $provider,
        'error'    => $e->getMessage(),
        'ip'       => $ip,
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'OAuth provider not configured']);
    exit;
}
