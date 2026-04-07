<?php
/**
 * GET /api/auth/oauth_callback.php?code={code}&state={state}
 *
 * Handles OAuth callback: validates state, exchanges code for tokens,
 * verifies ID token, finds/creates user, issues JWT, redirects to frontend.
 *
 * Feature: oauth-social-login
 * Validates: Requirements 3.1–3.11, 4.1–4.6, 5.1–5.5, 6.1–6.4, 9.1, 9.3, 9.4, 9.5, 9.6
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/structured_logger.php';
require_once __DIR__ . '/../../includes/oauth_service.php';
require_once __DIR__ . '/../../includes/jwt_service.php';

$logger = StructuredLogger::getInstance();
$oauthConfig = require __DIR__ . '/../../config/oauth.php';
$frontendUrl = $oauthConfig['frontend_url'] ?: 'https://anora.bet';

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

/**
 * Redirect to frontend with error.
 */
function redirectError(string $frontendUrl, string $error, string $message = ''): void
{
    $params = ['error' => $error];
    if ($message) {
        $params['message'] = $message;
    }
    header('Location: ' . $frontendUrl . '/auth/callback?' . http_build_query($params), true, 302);
    exit;
}

// ── Rate limiting: 10 requests/minute per IP ────────────────────────────────
$rateLimitKey = 'oauth_callback_' . md5($ip);
$rateLimitFile = '/tmp/' . $rateLimitKey . '.json';
$rateLimitWindow = 60; // 1 minute
$rateLimitMax = 10;

$rateLimitData = [];
if (file_exists($rateLimitFile)) {
    $rateLimitData = json_decode(file_get_contents($rateLimitFile), true) ?: [];
}

// Clean old entries
$now = time();
$rateLimitData = array_filter($rateLimitData, fn($ts) => ($now - $ts) < $rateLimitWindow);
$rateLimitData[] = $now;

file_put_contents($rateLimitFile, json_encode(array_values($rateLimitData)));

if (count($rateLimitData) > $rateLimitMax) {
    $logger->warning('OAuth callback rate limit exceeded', ['ip' => $ip]);
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Too many requests']);
    exit;
}

// ── Get parameters ──────────────────────────────────────────────────────────
// Google uses GET, Apple uses POST (form_post)
$code  = $_GET['code']  ?? $_POST['code']  ?? '';
$state = $_GET['state'] ?? $_POST['state'] ?? '';

// ── Validate state ──────────────────────────────────────────────────────────
$sessionState    = $_SESSION['oauth_state']    ?? '';
$sessionNonce    = $_SESSION['oauth_nonce']    ?? '';
$sessionProvider = $_SESSION['oauth_provider'] ?? '';

if (empty($sessionState) || empty($state)) {
    $logger->warning('OAuth callback: missing state', ['ip' => $ip]);
    redirectError($frontendUrl, 'session_expired', 'Session expired');
}

if (!hash_equals($sessionState, $state)) {
    $logger->warning('OAuth callback: state mismatch', ['ip' => $ip]);
    // Clean session
    unset($_SESSION['oauth_state'], $_SESSION['oauth_nonce'], $_SESSION['oauth_provider']);
    redirectError($frontendUrl, 'state_mismatch', 'Security validation failed');
}

if (empty($code)) {
    // User may have denied access
    $errorParam = $_GET['error'] ?? $_POST['error'] ?? 'unknown';
    $logger->info('OAuth callback: no code received', [
        'provider' => $sessionProvider,
        'error'    => $errorParam,
        'ip'       => $ip,
    ]);
    unset($_SESSION['oauth_state'], $_SESSION['oauth_nonce'], $_SESSION['oauth_provider']);
    redirectError($frontendUrl, 'token_exchange_failed', 'Authorization was denied or failed');
}

// ── Clean session state immediately (prevent replay) ────────────────────────
$provider = $sessionProvider;
$nonce    = $sessionNonce;
unset($_SESSION['oauth_state'], $_SESSION['oauth_nonce'], $_SESSION['oauth_provider']);

$logger->info('OAuth callback: processing', [
    'provider'   => $provider,
    'ip'         => $ip,
    'user_agent' => $userAgent,
]);

try {
    $oauthService = new OAuthService($oauthConfig);

    // Exchange code for tokens
    $tokens = $oauthService->exchangeCode($provider, $code);

    // Verify ID token
    $claims = $oauthService->verifyIdToken($provider, $tokens['id_token'], $nonce);

    // Find or create user
    $result = $oauthService->findOrCreateUser($pdo, $provider, $claims, $ip);
    $user   = $result['user'];
    $isNew  = $result['is_new'];

    // Check is_banned
    if ((int)($user['is_banned'] ?? 0) === 1) {
        $logger->audit('oauth_login', 'failure', (int)$user['id'], $ip, $userAgent, [
            'reason'   => 'banned',
            'provider' => $provider,
        ]);
        redirectError($frontendUrl, 'account_banned', 'Account is banned');
    }

    // Check is_bot
    if ((int)($user['is_bot'] ?? 0) === 1) {
        $logger->audit('oauth_login', 'failure', (int)$user['id'], $ip, $userAgent, [
            'reason'   => 'bot',
            'provider' => $provider,
        ]);
        redirectError($frontendUrl, 'account_forbidden', 'Account cannot log in');
    }

    // Issue JWT tokens
    $jwtService = new JwtService();
    $accessToken = $jwtService->encode((int)$user['id'], 'user');
    $refreshData = $jwtService->createRefreshToken($pdo, (int)$user['id']);

    // Create PHP session for backward compatibility
    $_SESSION['user_id'] = (int)$user['id'];

    $logger->audit('oauth_login', 'success', (int)$user['id'], $ip, $userAgent, [
        'provider' => $provider,
        'is_new'   => $isNew,
    ]);

    // Redirect to frontend with tokens
    $params = [
        'access_token'  => $accessToken,
        'refresh_token' => $refreshData['token'],
        'is_new'        => $isNew ? '1' : '0',
    ];

    header('Location: ' . $frontendUrl . '/auth/callback?' . http_build_query($params), true, 302);
    exit;

} catch (RuntimeException $e) {
    $logger->error('OAuth callback failed', [
        'provider' => $provider,
        'error'    => $e->getMessage(),
        'ip'       => $ip,
    ]);

    $errorCode = 'internal_error';
    $msg = $e->getMessage();
    if (str_contains($msg, 'Token exchange')) {
        $errorCode = 'token_exchange_failed';
    } elseif (str_contains($msg, 'signature') || str_contains($msg, 'JWKS')) {
        $errorCode = 'invalid_token';
    } elseif (str_contains($msg, 'mismatch') || str_contains($msg, 'expired')) {
        $errorCode = 'invalid_claims';
    } elseif (str_contains($msg, 'not configured')) {
        $errorCode = 'provider_unavailable';
    }

    redirectError($frontendUrl, $errorCode, $e->getMessage());
}
