<?php
/**
 * OAuth provider configuration — reads all credentials from environment variables.
 *
 * Feature: oauth-social-login
 * Validates: Requirements 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7
 */

declare(strict_types=1);

return [
    'google' => [
        'client_id'     => getenv('GOOGLE_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
        'auth_url'      => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url'     => 'https://oauth2.googleapis.com/token',
        'jwks_url'      => 'https://www.googleapis.com/oauth2/v3/certs',
        'issuer'        => 'https://accounts.google.com',
        'scope'         => 'openid email profile',
    ],
    'apple' => [
        'client_id'        => getenv('APPLE_CLIENT_ID') ?: '',
        'team_id'          => getenv('APPLE_TEAM_ID') ?: '',
        'key_id'           => getenv('APPLE_KEY_ID') ?: '',
        'private_key_path' => getenv('APPLE_PRIVATE_KEY_PATH') ?: '',
        'auth_url'         => 'https://appleid.apple.com/auth/authorize',
        'token_url'        => 'https://appleid.apple.com/auth/token',
        'jwks_url'         => 'https://appleid.apple.com/auth/keys',
        'issuer'           => 'https://appleid.apple.com',
        'scope'            => 'name email',
    ],
    'redirect_uri' => getenv('OAUTH_REDIRECT_URI') ?: '',
    'frontend_url' => getenv('FRONTEND_URL') ?: '',
];
