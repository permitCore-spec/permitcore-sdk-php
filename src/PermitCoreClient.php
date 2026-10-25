<?php

declare(strict_types=1);

namespace PermitCore;

/** @internal Authoritative HTTP response; never a reason to use offline success. */
final class HttpRejection extends \RuntimeException
{
    public function __construct(public int $status, public string $body)
    {
        parent::__construct("HTTP {$status}.");
    }
}

final class InvalidResponse extends \RuntimeException {}

/**
 * PermitCore PHP SDK — Official client for PermitCore license management.
 *
 * Quick start:
 *   $client = new PermitCore\PermitCoreClient('https://your-instance.com');
 *   $result = $client->validate('PERMIT-XXXX-XXXX-XXXX-XXXX');
 *   if ($result->isValid) {
 *       echo 'Valid! Product: ' . $result->productName;
 *   }
 *
 * Requirements: PHP 8.0+, ext-curl, ext-json, ext-openssl (present on effectively every
 * standard PHP installation — needed for offline license token verification)
 */
class PermitCoreClient
{
    public const VERSION = '2.1.1';
    private string $baseUrl;
    private int    $timeout;
    private int $maxRetries;
    private bool   $enableOfflineCache;
    private array $trustedPublicKeys;
    private string $cacheDirectory;
    private ?string $deviceId;

    public function __construct(
        string $baseUrl,
        bool   $enableOfflineCache = true,
        int    $timeout = 10,
        array $trustedPublicKeys = [],
        ?string $cacheDirectory = null,
        ?string $deviceId = null,
        int $maxRetries = 2
    ) {
        if ($timeout <= 0 || $maxRetries < 0 || $maxRetries > 5) throw new \InvalidArgumentException('Timeout must be positive; maxRetries must be between 0 and 5.');
        $this->maxRetries = $maxRetries;
        $this->baseUrl            = rtrim($baseUrl, '/');
        $this->enableOfflineCache = $enableOfflineCache;
        $this->timeout            = $timeout;
        $this->trustedPublicKeys = array_values($trustedPublicKeys);
        $this->cacheDirectory = $cacheDirectory ?? sys_get_temp_dir();
        $this->deviceId = $deviceId;
    }

    // ── Validate ──────────────────────────────────────────────────────────────

    /**
     * Validates a license key. Does NOT consume an activation slot.
     * Falls back to local cache when the server is unreachable.
     *
     * @param string|null $expectedProductId Optional — when set, the server rejects
     *     (`LicenseResult::$errorCode === 'WrongProduct'`) unless this key belongs to that exact
     *     product; without it, any active key for the caller's tenant validates successfully
     *     regardless of which product it was issued for.
     */
    public function validate(string $licenseKey, ?string $version = null, ?string $expectedProductId = null): LicenseResult
    {
        try {
            $payload = ['licenseKey' => $licenseKey];
            if ($this->enableOfflineCache && $this->trustedPublicKeys) $payload['deviceId'] = $this->effectiveDeviceId();
            if ($version !== null) {
                $payload['version'] = $version;
            }
            if ($expectedProductId !== null) {
                $payload['expectedProductId'] = $expectedProductId;
            }
            $data   = $this->post('api/v1/validate', $payload);
            $result = self::licenseResponse($data);
            if ($result->isValid) {
                $this->saveCache($licenseKey, $result, $this->effectiveDeviceId(), $expectedProductId);
            }
            return $result;
        } catch (InvalidResponse) {
            return new LicenseResult(isValid: false, errorCode: 'InvalidResponse', message: 'Invalid server response.');
        } catch (HttpRejection $error) {
            return self::rejectedLicense($error);
        } catch (\Throwable) {
            $cached = $this->loadCache($licenseKey, $this->effectiveDeviceId(), $expectedProductId);
            if ($cached !== null) {
                return $cached;
            }
            return new LicenseResult(isValid: false, message: 'Cannot reach license server.', isOffline: true, errorCode: 'RequestFailed');
        }
    }

    // ── Activate ──────────────────────────────────────────────────────────────

    /**
     * Validates AND activates the key on this device. Call only once per installation.
     *
     * @param string|null $deviceId          Custom hardware ID — auto-generated HWID used when null.
     * @param string|null $deviceName        Human-readable device label shown in the admin panel.
     * @param string|null $version           Client app version, included in the request when set.
     * @param string|null $expectedProductId Optional, same meaning as validate()'s.
     */
    public function activate(
        string  $licenseKey,
        ?string $deviceId          = null,
        ?string $deviceName        = null,
        ?string $version           = null,
        ?string $expectedProductId = null
    ): LicenseResult {
        $hwid = $this->effectiveDeviceId($deviceId);
        try {
            // [S-Nonce] Fetch a single-use nonce first (replay-attack protection)
            $nonceData = $this->get('api/v1/nonce');
            if (!isset($nonceData['nonce']) || !is_string($nonceData['nonce']) || trim($nonceData['nonce']) === '') throw new InvalidResponse();
            $payload   = ['licenseKey' => $licenseKey, 'deviceId' => $hwid, 'nonce' => $nonceData['nonce']];
            if ($deviceName !== null) {
                $payload['deviceName'] = $deviceName;
            }
            if ($version !== null) {
                $payload['version'] = $version;
            }
            if ($expectedProductId !== null) {
                $payload['expectedProductId'] = $expectedProductId;
            }
            $data   = $this->post('api/v1/activate', $payload);
            $result = self::licenseResponse($data);
            if ($result->isValid) {
                $this->saveCache($licenseKey, $result, $hwid, $expectedProductId, true);
            }
            return $result;
        } catch (InvalidResponse) {
            return new LicenseResult(isValid: false, errorCode: 'InvalidResponse', message: 'Invalid server response.');
        } catch (HttpRejection $error) {
            return self::rejectedLicense($error);
        } catch (\Throwable) {
            // [S-Continuity] If this device already activated successfully before (e.g. an app
            // that re-runs activate() on every launch, or a reinstall that kept the cache file),
            // fall back to that cached result instead of failing outright.
            $cached = $this->loadCache($licenseKey, $hwid, $expectedProductId, true);
            if ($cached !== null) {
                return $cached;
            }
            return new LicenseResult(isValid: false, message: 'Cannot reach license server.', isOffline: true, errorCode: 'RequestFailed');
        }
    }

    // ── Meter ─────────────────────────────────────────────────────────────────

    /**
     * Records a usage event for metered billing.
     *
     * @param string $licenseKey  The customer's license key.
     * @param string $eventName   Event name, e.g. "api_call", "export", "report_generated".
     * @param int    $quantity    Units consumed (default 1).
     * @param array  $meta        Optional key-value metadata stored with the event.
     * @return bool               True if the event was recorded on the server.
     */
    public function meter(
        string $licenseKey,
        string $eventName,
        int    $quantity = 1,
        array  $meta     = []
    ): bool {
        $payload = ['licenseKey' => $licenseKey, 'eventName' => $eventName, 'quantity' => max(1, $quantity)];
        if (func_num_args() >= 4) {
            $payload['meta'] = (object) $meta;
        }
        try {
            $data = $this->post('api/v1/meter', $payload);
            return ($data['recorded'] ?? null) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Floating licenses ─────────────────────────────────────────────────────

    /**
     * Checks out a concurrent seat for a floating license.
     */
    /** Confirm removal; OTP/account policy remains enforced by the portal. */
    public function deactivate(string $tenantSlug, string $licenseKey, string $activationId): bool
    {
        $cache = $this->cachePath($licenseKey);
        if (is_file($cache)) @unlink($cache);
        try {
            $this->request('POST', 'api/v1/portal/' . rawurlencode($tenantSlug) . '/deactivate',
                json_encode(['licenseKey' => $licenseKey, 'activationId' => $activationId], JSON_THROW_ON_ERROR), 204);
            return true;
        } catch (\Throwable) { return false; }
    }

    public function checkout(string $licenseKey, ?string $deviceId = null, ?string $deviceName = null): FloatingSession
    {
        $payload = ['licenseKey' => $licenseKey, 'deviceId' => ($deviceId === null || $deviceId === '') ? $this->getHardwareId() : $deviceId];
        if ($deviceName !== null && $deviceName !== '') $payload['deviceName'] = $deviceName;
        return $this->floatingRequest('checkout', $payload);
    }

    public function heartbeat(string $sessionToken): FloatingSession
    {
        return $this->floatingRequest('heartbeat', ['sessionToken' => $sessionToken]);
    }

    public function tryCheckin(string $sessionToken): bool
    {
        try {
            $this->request('POST', 'api/v1/float/checkin', json_encode(['sessionToken' => $sessionToken], JSON_THROW_ON_ERROR), 204);
            return true;
        } catch (\Throwable) { return false; }
    }

    public function checkin(string $sessionToken): void { $this->tryCheckin($sessionToken); }

    private function floatingRequest(string $operation, array $payload): FloatingSession
    {
        $failure = fn() => new FloatingSession(errorCode: 'RequestFailed', message: 'Cannot complete floating request.');
        try {
            $data = $this->post('api/v1/float/' . $operation, $payload);
            if (!array_key_exists('success', $data) || !is_bool($data['success'])) return $failure();
            foreach (['sessionToken', 'expiresAt', 'message', 'errorCode'] as $field)
                if (isset($data[$field]) && !is_string($data[$field])) return $failure();
            if (!$data['success']) return new FloatingSession(message: $data['message'] ?? null, errorCode: ($data['errorCode'] ?? '') ?: 'FloatingDenied');
            if (trim($data['sessionToken'] ?? '') === '' || trim($data['expiresAt'] ?? '') === '') return $failure();
            $result = FloatingSession::fromArray($data); $result->errorCode = null; return $result;
        } catch (\Throwable) { return $failure(); }
    }

    // ── Offline token verification (pc_offline_v1, ECDSA P-256 / IEEE P1363) ──────────────

    /**
     * Verifies an offline activation token entirely locally — no network call.
     *
     * Token format: pc_offline_v1.<base64url(payload_json)>.<base64url(signature)>. The
     * signature is ECDSA P-256/SHA-256 over the raw ASCII bytes of the base64url payload
     * string (not the decoded JSON). The server (.NET's ECDsa.SignData) emits IEEE P1363
     * raw format (64-byte r||s) — openssl_verify() requires ASN.1 DER, so the raw signature
     * is converted via p1363ToDer() before verification.
     *
     * Never throws — malformed/tampered/expired input all come back as isValid = false.
     *
     * @return array{isValid: bool, message: string} plus payload fields (tokenId, tenantSlug,
     *         tenantId, licenseId, licenseKeyHash, deviceId, deviceName, productName,
     *         maxActivations, issuedAt, expiresAt, ...) merged in when the token parsed.
     */
    public function verifyOfflineToken(string $token, string $publicKeyBase64): array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3 || $parts[0] !== 'pc_offline_v1') {
                return ['isValid' => false, 'message' => 'Malformed token.'];
            }

            $rawSig = $this->base64UrlDecode($parts[2]);
            if ($rawSig === false || strlen($rawSig) !== 64) {
                return ['isValid' => false, 'message' => 'Malformed token.'];
            }

            $publicKeyPem = $this->spkiToPem($publicKeyBase64);
            $derSig       = $this->p1363ToDer($rawSig);

            // $parts[1] is passed as-is — openssl_verify() needs the raw bytes that were
            // signed, which are the ASCII bytes of the base64url payload STRING, not the
            // decoded JSON.
            $verified = openssl_verify($parts[1], $derSig, $publicKeyPem, OPENSSL_ALGO_SHA256);
            if ($verified !== 1) {
                return ['isValid' => false, 'message' => 'Invalid signature.'];
            }

            $json = $this->base64UrlDecode($parts[1]);
            if ($json === false) {
                return ['isValid' => false, 'message' => 'Malformed payload.'];
            }

            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                return ['isValid' => false, 'message' => 'Malformed payload.'];
            }

            if (($payload['version'] ?? null) !== 1 || !$this->validFutureDate($payload['expiresAt'] ?? null)) {
                return ['isValid' => false, 'message' => 'Token expired or malformed.'];
            }
            return array_merge($payload, ['isValid' => true, 'message' => 'Valid.']);
        } catch (\Throwable) {
            return ['isValid' => false, 'message' => 'Invalid or corrupt token.'];
        }
    }

    /** Verify configured trust and persist only the original signed token. */
    public function activateOffline(string $token, ?string $deviceId = null, ?string $expectedProductId = null): array
    {
        $id = $this->effectiveDeviceId($deviceId);
        $result = $this->trustedOfflineToken($token, $id, $expectedProductId);
        if ($result['isValid'] && $this->enableOfflineCache) {
            try {
                if (!is_dir($this->cacheDirectory)) mkdir($this->cacheDirectory, 0700, true);
                file_put_contents($this->offlineCachePath($id), json_encode(['token' => $token], JSON_THROW_ON_ERROR), LOCK_EX);
            } catch (\Throwable) { /* Disk failure does not invalidate a verified token. */ }
        }
        return $result;
    }

    /** Re-verify the original signed token and context on every read. */
    public function validateOffline(?string $deviceId = null, ?string $expectedProductId = null): array
    {
        if (!$this->enableOfflineCache) return ['isValid' => false, 'message' => 'Offline caching is disabled.'];
        try {
            $id = $this->effectiveDeviceId($deviceId);
            $file = $this->offlineCachePath($id);
            if (!is_file($file)) return ['isValid' => false, 'message' => 'No signed token found.'];
            $entry = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            return $this->trustedOfflineToken($entry['token'], $id, $expectedProductId);
        } catch (\Throwable) { return ['isValid' => false, 'message' => 'No trusted offline activation found.']; }
    }

    private function trustedOfflineToken(string $token, string $deviceId, ?string $productId): array
    {
        foreach ($this->trustedPublicKeys as $pin) {
            $result = $this->verifyOfflineToken($token, $pin);
            if (($result['isValid'] ?? false) === true && is_string($result['deviceId'] ?? null) &&
                strtolower(trim($result['deviceId'])) === $deviceId &&
                (!$productId || (is_string($result['productId'] ?? null) && strcasecmp($result['productId'], $productId) === 0))) return $result;
        }
        return ['isValid' => false, 'message' => 'Offline token trust or context check failed.'];
    }

    /**
     * Optional online check: asks the server to verify the token AND check its revocation
     * status. Requires network. Use verifyOfflineToken() for pure offline verification.
     */
    public function verifyOfflineOnline(string $token): array
    {
        try {
            $data = $this->post('api/v1/offline/verify', ['token' => $token]);
            return [
                'isValid' => (bool) ($data['isValid'] ?? false),
                'message' => (string) ($data['message'] ?? ''),
            ];
        } catch (\Throwable) {
            return ['isValid' => false, 'message' => 'Cannot reach license server.'];
        }
    }

    private function offlineCachePath(string $deviceId): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . '.permitcore_offline_v2_' . hash('sha256', $deviceId);
    }

    /** Wraps a base64-encoded SPKI (SubjectPublicKeyInfo) DER key into PEM for openssl_verify(). */
    private function spkiToPem(string $publicKeyBase64): string
    {
        $der = base64_decode($publicKeyBase64, true);
        if ($der === false) {
            throw new \RuntimeException('Invalid public key encoding.');
        }
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Converts a raw IEEE P1363 ECDSA signature (64-byte r||s, as produced by .NET's
     * ECDsa.SignData) into the ASN.1 DER SEQUENCE { INTEGER r, INTEGER s } that
     * openssl_verify() requires.
     */
    private function p1363ToDer(string $rawSig): string
    {
        $r = substr($rawSig, 0, 32);
        $s = substr($rawSig, 32, 32);

        $derR = $this->encodeDerInteger($r);
        $derS = $this->encodeDerInteger($s);

        $seqBody = $derR . $derS;
        return "\x30" . $this->encodeDerLength(strlen($seqBody)) . $seqBody;
    }

    /** DER INTEGER: strip leading zero bytes, re-add a single 0x00 if the sign bit would be set. */
    private function encodeDerInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . $this->encodeDerLength(strlen($bytes)) . $bytes;
    }

    /** DER length octet(s): short form for <0x80, long form (0x80 | byte-count) otherwise. */
    private function encodeDerLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $out = '';
        while ($len > 0) {
            $out = chr($len & 0xFF) . $out;
            $len >>= 8;
        }
        return chr(0x80 | strlen($out)) . $out;
    }

    private function base64UrlDecode(string $data): string|false
    {
        $b64    = strtr($data, '-_', '+/');
        $padLen = 4 - (strlen($b64) % 4);
        if ($padLen < 4) {
            $b64 .= str_repeat('=', $padLen);
        }
        return base64_decode($b64, true);
    }

    // ── Hardware ID ───────────────────────────────────────────────────────────

    /**
     * Generates a stable hardware fingerprint (SHA-256 of machine identifiers).
     */
    public function getHardwareId(): string
    {
        $components = [
            php_uname('n'),                   // hostname
            php_uname('s'),                   // OS name
            php_uname('m'),                   // machine type
            (string) (PHP_INT_SIZE * 8),      // architecture bits
            $this->getOrCreateSeed(),
        ];

        return hash('sha256', implode('|', array_filter($components)));
    }

    // ── Offline cache (pc_grace_v1, ECDSA-signed) ───────────────────────────────
    //
    // [S-Grace1] The server used to hand us a raw, unsigned JSON blob to cache — anyone with
    // filesystem access could hand-edit it and grant themselves permanent "valid" access. Now
    // the server signs the cacheable payload (pc_grace_v1 token, same ECDSA P-256/SHA-256/
    // IEEE-P1363 convention as the pc_offline_v1 tokens verified above) and we cache THAT,
    // verifying it locally (no network) on every offline read. A hand-edited cache file now
    // fails ECDSA verification instead of silently working.

    private function effectiveDeviceId(?string $id = null): string
    {
        return strtolower(trim($id ?? '') ?: (trim($this->deviceId ?? '') ?: $this->getHardwareId()));
    }

    private function trustedGraceToken(string $token, string $key, string $deviceId, ?string $productId, bool $activation): ?array
    {
        foreach ($this->trustedPublicKeys as $pin) {
            $check = $this->verifyGraceCacheToken($token, $pin);
            $p = $check['payload'] ?? null;
            if (($check['isValid'] ?? false) !== true || !is_array($p) || ($p['isValid'] ?? false) !== true ||
                ($p['licenseKeySha256'] ?? null) !== hash('sha256', $key) ||
                !is_string($p['productId'] ?? null) || $p['productId'] === '' ||
                ($productId && strcasecmp($p['productId'], $productId) !== 0) ||
                (isset($p['deviceId']) && (!is_string($p['deviceId']) || strtolower(trim($p['deviceId'])) !== $deviceId)) ||
                (($activation || ($p['nodeLocked'] ?? null) !== false) && (($p['deviceActivated'] ?? null) !== true || empty($p['deviceId'])))) continue;
            return $p;
        }
        return null;
    }

    private function saveCache(string $key, LicenseResult $result, string $deviceId, ?string $productId, bool $activation = false): void
    {
        if (!$this->enableOfflineCache || !$result->offlineCacheToken) return;
        try {
            if (!$this->trustedGraceToken($result->offlineCacheToken, $key, $deviceId, $productId, $activation)) return;
            if (!is_dir($this->cacheDirectory)) mkdir($this->cacheDirectory, 0700, true);
            file_put_contents($this->cachePath($key), json_encode(['token' => $result->offlineCacheToken], JSON_THROW_ON_ERROR), LOCK_EX);
        } catch (\Throwable) { /* Cache failure never blocks an online result. */ }
    }

    private function loadCache(string $key, string $deviceId, ?string $productId, bool $activation = false): ?LicenseResult
    {
        if (!$this->enableOfflineCache) return null;
        try {
            $file = $this->cachePath($key);
            if (!is_file($file)) return null;
            $entry = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $p = $this->trustedGraceToken($entry['token'], $key, $deviceId, $productId, $activation);
            if (!$p) return null;
            return new LicenseResult(isValid: true, productId: $p['productId'], nodeLocked: ($p['nodeLocked'] ?? false) === true,
                productName: $p['productName'] ?? null, remainingActivations: $p['remainingActivations'] ?? null,
                expiresAt: $p['expiresAt'] ?? null, features: $p['features'] ?? null, isOffline: true,
                message: 'Offline mode — valid until ' . $p['validUntil'] . ' (cryptographically verified)');
        } catch (\Throwable) { return null; }
    }

    private function cachePath(string $key): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . '.permitcore_cache_v2_' . hash('sha256', $key);
    }

    private function validFutureDate($value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return false;
        $date = new \DateTimeImmutable($value);
        $errors = \DateTimeImmutable::getLastErrors();
        return ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date > new \DateTimeImmutable('now');
    }

    // ── Grace-cache token verification (pc_grace_v1, ECDSA P-256 / IEEE P1363) ──────────

    /**
     * Verifies a pc_grace_v1 offline grace-cache token entirely locally — no network call.
     * Mirrors verifyOfflineToken() above (same crypto primitives, same byte-handling — the
     * signature covers the raw UTF-8 bytes of the base64url payload string, not the decoded
     * JSON) but for the grace-cache payload shape and the "validUntil" expiry field instead
     * of "expiresAt".
     *
     * Never throws — malformed/tampered/expired input all come back as isValid = false.
     *
     * @return array{isValid: bool, message: string, payload?: array} `payload` is present
     *         whenever the token parsed far enough to decode a payload (even if expired or
     *         otherwise invalid), matching verifyOfflineToken()'s array_merge behavior.
     */
    public function verifyGraceCacheToken(string $token, string $publicKeyBase64): array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3 || $parts[0] !== 'pc_grace_v1') {
                return ['isValid' => false, 'message' => 'Malformed token.'];
            }

            $rawSig = $this->base64UrlDecode($parts[2]);
            if ($rawSig === false || strlen($rawSig) !== 64) {
                return ['isValid' => false, 'message' => 'Malformed token.'];
            }

            $publicKeyPem = $this->spkiToPem($publicKeyBase64);
            $derSig       = $this->p1363ToDer($rawSig);

            // $parts[1] is passed as-is — openssl_verify() needs the raw bytes that were
            // signed, which are the ASCII bytes of the base64url payload STRING, not the
            // decoded JSON. Same subtlety verifyOfflineToken() already handles.
            $verified = openssl_verify($parts[1], $derSig, $publicKeyPem, OPENSSL_ALGO_SHA256);
            if ($verified !== 1) {
                return ['isValid' => false, 'message' => 'Invalid signature.'];
            }

            $json = $this->base64UrlDecode($parts[1]);
            if ($json === false) {
                return ['isValid' => false, 'message' => 'Malformed payload.'];
            }

            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                return ['isValid' => false, 'message' => 'Malformed payload.'];
            }

            if (($payload['version'] ?? null) !== 1 || !$this->validFutureDate($payload['validUntil'] ?? null)) {
                return ['isValid' => false, 'message' => 'Grace period expired or malformed.'];
            }
            return ['isValid' => true, 'message' => 'Valid.', 'payload' => $payload];
        } catch (\Throwable) {
            return ['isValid' => false, 'message' => 'Invalid or corrupt token.'];
        }
    }

    private function getOrCreateSeed(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '.permitcore_seed';
        if (!file_exists($path)) {
            file_put_contents($path, bin2hex(random_bytes(16)), LOCK_EX);
        }
        return trim((string) file_get_contents($path));
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private static function rejectedLicense(HttpRejection $error): LicenseResult
    {
        try {
            $data = json_decode($error->body, true);
            $result = LicenseResult::fromArray(is_array($data) ? $data : []);
        } catch (\Throwable) { $result = new LicenseResult(); }
        $result->isValid = false;
        $result->isOffline = false;
        $result->offlineCacheToken = null;
        if (empty($result->errorCode)) $result->errorCode = 'HttpError';
        if (empty($result->message)) $result->message = $error->getMessage();
        return $result;
    }

    private static function licenseResponse(array $data): LicenseResult
    {
        if (!array_key_exists('isValid', $data) || !is_bool($data['isValid']) ||
            (isset($data['errorCode']) && !is_string($data['errorCode']))) throw new InvalidResponse();
        try { $result = LicenseResult::fromArray($data); }
        catch (\Throwable $error) { throw new InvalidResponse('Invalid license response.', 0, $error); }
        $result->isOffline = false;
        if (!$result->isValid) $result->offlineCacheToken = null;
        return $result;
    }

    private function request(string $method, string $path, ?string $body, ?int $expectedStatus = null): array
    {
        $safe = ($method === 'GET' && ($path === 'api/v1/nonce' || preg_match('~^api/v1/[^/]+/public-key$~', $path))) ||
            ($method === 'POST' && in_array($path, ['api/v1/validate', 'api/v1/float/heartbeat'], true));
        for ($attempt = 0; ; $attempt++) {
            $ch = curl_init("{$this->baseUrl}/{$path}");
            if ($ch === false) throw new \RuntimeException('curl_init failed');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT => 'PermitCore-PHP/' . self::VERSION,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            ]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // CurlHandle is released automatically; curl_close is deprecated in PHP 8.5.
            if ($status >= 300 && $status < 500) throw new HttpRejection($status, $raw === false ? '' : (string) $raw);
            if ($status >= 200 && $status < 300 && ($errno !== CURLE_OK || $raw === false)) throw new InvalidResponse();
            if ($status >= 500 || $errno !== CURLE_OK || $raw === false) {
                if ($safe && $attempt < $this->maxRetries) {
                    unset($ch);
                    usleep(min(2000000, 250000 * (2 ** $attempt)));
                    continue;
                }
                throw new \RuntimeException("Request failed (HTTP {$status}, cURL {$errno}).");
            }
            if ($expectedStatus !== null && $status !== $expectedStatus) throw new \RuntimeException('Unexpected response status.');
            if ($status === 204 && $raw === '') return [];
            try {
                $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data)) throw new InvalidResponse();
                return $data;
            } catch (\JsonException $error) { throw new InvalidResponse('Malformed JSON.', 0, $error); }
        }
    }

}


// ── Value objects ─────────────────────────────────────────────────────────────

class LicenseResult
{
    public function __construct(
        public bool    $isValid             = false,
        public ?string $productName         = null,
        public ?int    $remainingActivations = null,
        public ?string $expiresAt           = null,
        public ?string $message             = null,
        public ?array  $customFields        = null,
        public ?string $vendorWarning       = null,
        public ?array  $features            = null,
        public bool    $isTrial             = false,
        public ?int    $trialDaysRemaining  = null,
        public bool    $nodeLocked          = false,
        public ?int    $offlineGraceDays    = null,
        public bool    $isOffline           = false,
        public ?string $minVersion          = null,
        public ?string $maxVersion          = null,
        // [S-Grace1] ECDSA-signed pc_grace_v1 token (non-null only when the license has an
        // offline grace period and this call succeeded). The SDK caches THIS — not the rest of
        // this response — and verifies it locally before trusting a cached result on a later
        // offline call. See PermitCoreClient::verifyGraceCacheToken().
        public ?string $offlineCacheToken   = null,
        // [QA-040 fix] Stable, machine-readable failure reason (e.g. "NotFound",
        // "SeatsExhausted", "Expired") — null on success. $message stays free-text for display;
        // use this for programmatic branching instead, since message wording may change.
        public ?string $errorCode           = null,
        /**
         * The product this license actually belongs to. Always present when a license was
         * found, regardless of whether you passed expectedProductId — null only when the key
         * itself wasn't found. Compare this yourself, or pass expectedProductId, to confirm a
         * valid key belongs to the specific product you expect.
         */
        public ?string $productId           = null,
        public ?string $paymentStatus       = null,
        public bool $isInGracePeriod         = false,
    ) {}

    /** Returns true if the license includes the given feature flag (case-insensitive). */
    public function hasFeature(string $feature): bool
    {
        if (empty($this->features)) {
            return false;
        }
        $lower = strtolower($feature);
        foreach ($this->features as $f) {
            if (strtolower($f) === $lower) {
                return true;
            }
        }
        return false;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            isValid:              (bool)  ($data['isValid']              ?? false),
            productName:                   $data['productName']           ?? null,
            remainingActivations: (int)   ($data['remainingActivations'] ?? 0) ?: null,
            expiresAt:                     $data['expiresAt']             ?? null,
            message:                       $data['message']               ?? null,
            customFields:                  $data['customFields']          ?? null,
            vendorWarning:                 $data['vendorWarning']         ?? null,
            paymentStatus:                 $data['paymentStatus']         ?? null,
            isInGracePeriod:               $data['isInGracePeriod']       ?? false,
            features:                      $data['features']              ?? null,
            isTrial:              (bool)  ($data['isTrial']              ?? false),
            trialDaysRemaining:   isset($data['trialDaysRemaining']) ? (int) $data['trialDaysRemaining'] : null,
            nodeLocked:           (bool)  ($data['nodeLocked']           ?? false),
            offlineGraceDays:     isset($data['offlineGraceDays'])    ? (int) $data['offlineGraceDays']    : null,
            isOffline:            (bool)  ($data['isOffline']            ?? false),
            minVersion:                    $data['minVersion']            ?? null,
            maxVersion:                    $data['maxVersion']            ?? null,
            offlineCacheToken:             $data['offlineCacheToken']     ?? null,
            errorCode:                     $data['errorCode']             ?? null,
            productId:                     $data['productId']             ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'isValid'              => $this->isValid,
            'productName'         => $this->productName,
            'remainingActivations' => $this->remainingActivations,
            'expiresAt'           => $this->expiresAt,
            'message'             => $this->message,
            'customFields'        => $this->customFields,
            'vendorWarning'       => $this->vendorWarning,
            'paymentStatus'       => $this->paymentStatus,
            'isInGracePeriod'     => $this->isInGracePeriod,
            'features'            => $this->features,
            'isTrial'             => $this->isTrial,
            'trialDaysRemaining'  => $this->trialDaysRemaining,
            'nodeLocked'          => $this->nodeLocked,
            'offlineGraceDays'    => $this->offlineGraceDays,
            'isOffline'           => $this->isOffline,
            'minVersion'          => $this->minVersion,
            'maxVersion'          => $this->maxVersion,
            'offlineCacheToken'   => $this->offlineCacheToken,
            'errorCode'           => $this->errorCode,
            'productId'           => $this->productId,
        ];
    }
}


class FloatingSession
{
    public function __construct(
        public bool    $success      = false,
        public ?string $sessionToken = null,
        public ?string $expiresAt    = null,
        public ?string $message      = null,
        public ?string $errorCode    = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            success:      (bool)  ($data['success']      ?? false),
            sessionToken:          $data['sessionToken']  ?? null,
            expiresAt:             $data['expiresAt']     ?? null,
            message:               $data['message']       ?? null,
            errorCode:             $data['errorCode']     ?? null,
        );
    }
}
