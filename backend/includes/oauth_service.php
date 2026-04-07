<?php
/**
 * OAuthService — OAuth 2.0 / OpenID Connect service for ANORA platform.
 *
 * Handles Google OAuth (active) and Apple Sign-In (commented for future use).
 * Provides: authorization URL generation, code exchange, ID token verification
 * via JWKS, and user find-or-create logic.
 *
 * Feature: oauth-social-login
 * Validates: Requirements 2.1–2.5, 3.1–3.11, 4.1–4.6, 5.1–5.5, 6.1–6.4, 7.1–7.3, 8.8, 9.1, 9.2, 9.5
 */

declare(strict_types=1);

require_once __DIR__ . '/structured_logger.php';
require_once __DIR__ . '/nickname.php';
require_once __DIR__ . '/jwt_service.php';

class OAuthService
{
    private array $config;
    private StructuredLogger $logger;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logger = StructuredLogger::getInstance();
    }

    /**
     * Generate the Authorization URL for the given provider.
     *
     * @param string $provider 'google' or 'apple'
     * @param string $state    CSRF state parameter
     * @param string $nonce    Replay-protection nonce
     * @return string Full authorization URL
     */
    public function getAuthorizationUrl(string $provider, string $state, string $nonce): string
    {
        $this->validateProviderConfig($provider);
        $providerConfig = $this->config[$provider];

        $params = [
            'response_type' => 'code',
            'client_id'     => $providerConfig['client_id'],
            'redirect_uri'  => $this->config['redirect_uri'],
            'scope'         => $providerConfig['scope'],
            'state'         => $state,
            'nonce'         => $nonce,
        ];

        if ($provider === 'google') {
            $params['access_type'] = 'offline';
            $params['prompt'] = 'consent';
        }

        // Apple Sign-In — commented for future use
        // if ($provider === 'apple') {
        //     $params['response_type'] = 'code id_token';
        //     $params['response_mode'] = 'form_post';
        // }

        return $providerConfig['auth_url'] . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for tokens via server-to-server POST.
     *
     * @param string $provider 'google' or 'apple'
     * @param string $code     Authorization code from callback
     * @return array {access_token, id_token, ...}
     */
    public function exchangeCode(string $provider, string $code): array
    {
        $this->validateProviderConfig($provider);
        $providerConfig = $this->config[$provider];

        $postData = [
            'code'          => $code,
            'client_id'     => $providerConfig['client_id'],
            'redirect_uri'  => $this->config['redirect_uri'],
            'grant_type'    => 'authorization_code',
        ];

        if ($provider === 'google') {
            $postData['client_secret'] = $providerConfig['client_secret'];
        }

        // Apple Sign-In — commented for future use
        // if ($provider === 'apple') {
        //     $postData['client_secret'] = $this->generateAppleClientSecret();
        // }

        $ch = curl_init($providerConfig['token_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $this->logger->error('OAuth token exchange failed', [
                'provider'  => $provider,
                'http_code' => $httpCode,
                'error'     => $curlError,
                'response'  => substr((string)$response, 0, 500),
            ]);
            throw new RuntimeException('Token exchange failed');
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['id_token'])) {
            $this->logger->error('OAuth token exchange returned invalid data', [
                'provider' => $provider,
                'response' => substr((string)$response, 0, 500),
            ]);
            throw new RuntimeException('Invalid token response');
        }

        return $data;
    }

    /**
     * Verify an ID Token's signature via JWKS and validate claims.
     *
     * @param string $provider      'google' or 'apple'
     * @param string $idToken       The raw JWT id_token
     * @param string $expectedNonce The nonce saved in session
     * @return array {sub, email, name}
     */
    public function verifyIdToken(string $provider, string $idToken, string $expectedNonce): array
    {
        $this->validateProviderConfig($provider);
        $providerConfig = $this->config[$provider];

        // Split JWT
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid ID token format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode($this->base64urlDecode($headerB64), true);
        $payload = json_decode($this->base64urlDecode($payloadB64), true);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Invalid ID token structure');
        }

        // Verify signature via JWKS
        $kid = $header['kid'] ?? '';
        $jwks = $this->fetchJwks($provider);
        $publicKey = $this->findKeyByKid($jwks, $kid);

        if ($publicKey === null) {
            throw new RuntimeException('No matching key found in JWKS for kid: ' . $kid);
        }

        $signingInput = $headerB64 . '.' . $payloadB64;
        $signature = $this->base64urlDecode($signatureB64);

        $result = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new RuntimeException('ID token signature verification failed');
        }

        // Validate claims
        if (($payload['nonce'] ?? '') !== $expectedNonce) {
            throw new RuntimeException('Nonce mismatch');
        }

        $expectedAud = $providerConfig['client_id'];
        if (($payload['aud'] ?? '') !== $expectedAud) {
            throw new RuntimeException('Audience mismatch');
        }

        $expectedIss = $providerConfig['issuer'];
        if (($payload['iss'] ?? '') !== $expectedIss) {
            throw new RuntimeException('Issuer mismatch');
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new RuntimeException('ID token expired');
        }

        return [
            'sub'   => $payload['sub'] ?? '',
            'email' => $payload['email'] ?? '',
            'name'  => $payload['name'] ?? '',
        ];
    }

    /**
     * Fetch JWKS (JSON Web Key Set) from provider with file-based caching (24h TTL).
     *
     * @param string $provider 'google' or 'apple'
     * @return array The JWKS keys array
     */
    public function fetchJwks(string $provider): array
    {
        $providerConfig = $this->config[$provider];
        $jwksUrl = $providerConfig['jwks_url'];
        $cacheFile = '/tmp/jwks_' . $provider . '.json';
        $cacheTtl = 86400; // 24 hours

        // Try cache first
        if (file_exists($cacheFile)) {
            $cacheAge = time() - filemtime($cacheFile);
            if ($cacheAge < $cacheTtl) {
                $cached = json_decode(file_get_contents($cacheFile), true);
                if (is_array($cached) && !empty($cached['keys'])) {
                    return $cached['keys'];
                }
            }
        }

        // Fetch from provider
        $ch = curl_init($jwksUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            // Fallback to expired cache if available
            if (file_exists($cacheFile)) {
                $cached = json_decode(file_get_contents($cacheFile), true);
                if (is_array($cached) && !empty($cached['keys'])) {
                    $this->logger->warning('JWKS fetch failed, using expired cache', [
                        'provider' => $provider,
                    ]);
                    return $cached['keys'];
                }
            }
            throw new RuntimeException('Failed to fetch JWKS from ' . $provider);
        }

        $jwks = json_decode($response, true);
        if (!is_array($jwks) || empty($jwks['keys'])) {
            throw new RuntimeException('Invalid JWKS response from ' . $provider);
        }

        // Cache to file
        file_put_contents($cacheFile, $response);

        return $jwks['keys'];
    }

    // Apple Sign-In — commented for future use
    // /**
    //  * Generate Apple client_secret as a JWT signed with ES256.
    //  *
    //  * @return string The signed JWT client_secret
    //  */
    // public function generateAppleClientSecret(): string
    // {
    //     $appleConfig = $this->config['apple'];
    //     $privateKeyPath = $appleConfig['private_key_path'];
    //
    //     if (!file_exists($privateKeyPath)) {
    //         throw new RuntimeException('Apple private key file not found: ' . $privateKeyPath);
    //     }
    //
    //     $privateKey = file_get_contents($privateKeyPath);
    //     $keyResource = openssl_pkey_get_private($privateKey);
    //     if ($keyResource === false) {
    //         throw new RuntimeException('Failed to load Apple private key');
    //     }
    //
    //     $header = [
    //         'alg' => 'ES256',
    //         'kid' => $appleConfig['key_id'],
    //     ];
    //
    //     $now = time();
    //     $payload = [
    //         'iss' => $appleConfig['team_id'],
    //         'iat' => $now,
    //         'exp' => $now + 15777000, // ~6 months
    //         'aud' => 'https://appleid.apple.com',
    //         'sub' => $appleConfig['client_id'],
    //     ];
    //
    //     $headerB64 = $this->base64urlEncode(json_encode($header));
    //     $payloadB64 = $this->base64urlEncode(json_encode($payload));
    //     $signingInput = $headerB64 . '.' . $payloadB64;
    //
    //     $signature = '';
    //     $success = openssl_sign($signingInput, $signature, $keyResource, OPENSSL_ALGO_SHA256);
    //     if (!$success) {
    //         throw new RuntimeException('Failed to sign Apple client secret');
    //     }
    //
    //     // Convert DER signature to raw R+S format for ES256
    //     $signature = $this->derToRaw($signature);
    //
    //     return $signingInput . '.' . $this->base64urlEncode($signature);
    // }
    //
    // /**
    //  * Convert DER-encoded ECDSA signature to raw R+S (64 bytes).
    //  */
    // private function derToRaw(string $der): string
    // {
    //     $pos = 0;
    //     $pos++; // skip SEQUENCE tag
    //     $pos++; // skip SEQUENCE length
    //
    //     $pos++; // skip INTEGER tag for R
    //     $rLen = ord($der[$pos++]);
    //     $r = substr($der, $pos, $rLen);
    //     $pos += $rLen;
    //
    //     $pos++; // skip INTEGER tag for S
    //     $sLen = ord($der[$pos++]);
    //     $s = substr($der, $pos, $sLen);
    //
    //     // Pad/trim to 32 bytes each
    //     $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    //     $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    //
    //     return $r . $s;
    // }

    /**
     * Find or create a user based on OAuth claims.
     *
     * Logic:
     * 1. Search oauth_accounts by (provider, provider_user_id) → login existing
     * 2. Search users by email → auto-link OAuth to existing account
     * 3. Neither found → create new user
     *
     * @param PDO    $pdo
     * @param string $provider 'google' or 'apple'
     * @param array  $claims   {sub, email, name}
     * @param string $ip       Client IP address
     * @return array {user: array, is_new: bool}
     */
    public function findOrCreateUser(PDO $pdo, string $provider, array $claims, string $ip): array
    {
        $providerUserId = $claims['sub'];
        $email = $claims['email'];

        // 1. Search by provider + provider_user_id
        $stmt = $pdo->prepare(
            'SELECT u.* FROM oauth_accounts oa
             JOIN users u ON u.id = oa.user_id
             WHERE oa.provider = ? AND oa.provider_user_id = ?'
        );
        $stmt->execute([$provider, $providerUserId]);
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            $this->logger->info('OAuth login: existing OAuth user found', [
                'provider' => $provider,
                'user_id'  => $existingUser['id'],
            ]);
            return ['user' => $existingUser, 'is_new' => false];
        }

        // 2. Search users by email → auto-link
        if (!empty($email)) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $userByEmail = $stmt->fetch();

            if ($userByEmail) {
                // Link OAuth to existing account
                $pdo->prepare(
                    'INSERT INTO oauth_accounts (user_id, provider, provider_user_id, provider_email)
                     VALUES (?, ?, ?, ?)'
                )->execute([(int)$userByEmail['id'], $provider, $providerUserId, $email]);

                // Auto-verify if not verified
                if ((int)$userByEmail['is_verified'] === 0) {
                    $pdo->prepare('UPDATE users SET is_verified = 1 WHERE id = ?')
                        ->execute([(int)$userByEmail['id']]);
                    $userByEmail['is_verified'] = 1;
                }

                $this->logger->info('OAuth login: linked to existing email account', [
                    'provider' => $provider,
                    'user_id'  => $userByEmail['id'],
                    'email'    => $email,
                ]);
                return ['user' => $userByEmail, 'is_new' => false];
            }
        }

        // 3. Create new user
        $pdo->prepare(
            'INSERT INTO users (email, password, is_verified, registration_ip) VALUES (?, ?, 1, ?)'
        )->execute([$email, '', $ip]);

        $userId = (int)$pdo->lastInsertId();

        // Generate ref_code with retry
        $refCodeSet = false;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $refCode = strtoupper(bin2hex(random_bytes(6)));
            try {
                $pdo->prepare('UPDATE users SET ref_code = ? WHERE id = ?')
                    ->execute([$refCode, $userId]);
                $refCodeSet = true;
                break;
            } catch (PDOException $e) {
                if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                    continue;
                }
                throw $e;
            }
        }

        if (!$refCodeSet) {
            $this->logger->error('Failed to generate unique ref_code for OAuth user', [
                'user_id' => $userId,
            ]);
        }

        // Generate unique nickname
        $nickname = generateUniqueNickname($pdo);
        $pdo->prepare('UPDATE users SET nickname = ? WHERE id = ?')
            ->execute([$nickname, $userId]);

        // Create oauth_accounts record
        $pdo->prepare(
            'INSERT INTO oauth_accounts (user_id, provider, provider_user_id, provider_email)
             VALUES (?, ?, ?, ?)'
        )->execute([$userId, $provider, $providerUserId, $email]);

        // Fetch the created user
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $newUser = $stmt->fetch();

        $this->logger->info('OAuth login: new user created', [
            'provider' => $provider,
            'user_id'  => $userId,
            'email'    => $email,
        ]);

        return ['user' => $newUser, 'is_new' => true];
    }

    // ── Internal helpers ────────────────────────────────────────────────

    /**
     * Validate that required config values exist for the given provider.
     */
    private function validateProviderConfig(string $provider): void
    {
        if (!in_array($provider, ['google', 'apple'], true)) {
            throw new InvalidArgumentException('Unsupported OAuth provider: ' . $provider);
        }

        if (empty($this->config[$provider])) {
            throw new RuntimeException('OAuth provider not configured: ' . $provider);
        }

        if ($provider === 'google') {
            if (empty($this->config[$provider]['client_id']) || empty($this->config[$provider]['client_secret'])) {
                $this->logger->error('Missing Google OAuth env vars', [
                    'has_client_id'     => !empty($this->config[$provider]['client_id']),
                    'has_client_secret' => !empty($this->config[$provider]['client_secret']),
                ]);
                throw new RuntimeException('Google OAuth not configured');
            }
        }

        // Apple Sign-In — commented for future use
        // if ($provider === 'apple') {
        //     if (empty($this->config[$provider]['client_id']) || empty($this->config[$provider]['team_id'])
        //         || empty($this->config[$provider]['key_id']) || empty($this->config[$provider]['private_key_path'])) {
        //         $this->logger->error('Missing Apple OAuth env vars');
        //         throw new RuntimeException('Apple OAuth not configured');
        //     }
        // }

        if (empty($this->config['redirect_uri'])) {
            throw new RuntimeException('OAuth redirect_uri not configured');
        }
    }

    /**
     * Find a public key in JWKS by kid, and return an OpenSSL key resource.
     */
    private function findKeyByKid(array $keys, string $kid): mixed
    {
        foreach ($keys as $key) {
            if (($key['kid'] ?? '') === $kid && ($key['kty'] ?? '') === 'RSA') {
                // Build PEM from n and e
                $n = $this->base64urlDecode($key['n']);
                $e = $this->base64urlDecode($key['e']);
                $pem = $this->rsaPublicKeyToPem($n, $e);
                $keyResource = openssl_pkey_get_public($pem);
                if ($keyResource !== false) {
                    return $keyResource;
                }
            }
        }
        return null;
    }

    /**
     * Convert RSA modulus (n) and exponent (e) to PEM format.
     */
    private function rsaPublicKeyToPem(string $n, string $e): string
    {
        // ASN.1 DER encoding of RSA public key
        $modulus = "\x00" . $n; // prepend 0x00 to ensure positive integer
        $exponent = $e;

        $modulus = "\x02" . $this->asn1Length(strlen($modulus)) . $modulus;
        $exponent = "\x02" . $this->asn1Length(strlen($exponent)) . $exponent;

        $rsaPublicKey = "\x30" . $this->asn1Length(strlen($modulus . $exponent)) . $modulus . $exponent;

        // BitString wrapper
        $rsaPublicKey = "\x00" . $rsaPublicKey;
        $rsaPublicKey = "\x03" . $this->asn1Length(strlen($rsaPublicKey)) . $rsaPublicKey;

        // Algorithm identifier for RSA
        $algorithmIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        $publicKeyInfo = "\x30" . $this->asn1Length(
            strlen($algorithmIdentifier . $rsaPublicKey)
        ) . $algorithmIdentifier . $rsaPublicKey;

        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($publicKeyInfo), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    /**
     * ASN.1 DER length encoding.
     */
    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $temp = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($temp)) . $temp;
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}
