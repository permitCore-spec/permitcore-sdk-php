<?php

declare(strict_types=1);

namespace PermitCore;

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
    private string $baseUrl;
    private int    $timeout;
    private bool   $enableOfflineCache;

    public function __construct(
        string $baseUrl,
        bool   $enableOfflineCache = true,
        int    $timeout = 5
    ) {
        $this->baseUrl            = rtrim($baseUrl, '/');
        $this->enableOfflineCache = $enableOfflineCache;
        $this->timeout            = $timeout;
    }

    // ── Validate ──────────────────────────────────────────────────────────────

    /**
     * Validates a license key. Does NOT consume an activation slot.
     * Falls back to local cache when the server is unreachable.
     */
    public function validate(string $licenseKey, ?string $version = null): LicenseResult
    {
        try {
            $payload = ['licenseKey' => $licenseKey];
            if ($version !== null) {
                $payload['version'] = $version;
            }
            $data   = $this->post('api/v1/validate', $payload);
            $result = LicenseResult::fromArray($data);
            if ($result->isValid) {
                $this->saveCache($licenseKey, $result);
            }
            return $result;
        } catch (\Throwable) {
            $cached = $this->loadCache($licenseKey);
            if ($cached !== null) {
                return $cached;
            }
            return new LicenseResult(isValid: false, message: 'Cannot reach license server.', isOffline: true);
        }
    }

    // ── Activate ──────────────────────────────────────────────────────────────

    /**
     * Validates AND activates the key on this device. Call only once per installation.
     *
     * @param string|null $deviceId   Custom hardware ID — auto-generated HWID used when null.
     * @param string|null $deviceName Human-readable device label shown in the admin panel.
     * @param string|null $version    Client app version, included in the request when set.
     */
    public function activate(
        string  $licenseKey,
        ?string $deviceId   = null,
        ?string $deviceName = null,
        ?string $version    = null
    ): LicenseResult {
        $hwid = $deviceId ?? $this->getHardwareId();
        try {
            // [S-Nonce] Fetch a single-use nonce first (replay-attack protection)
            $nonceData = $this->get('api/v1/nonce');
            $payload   = ['licenseKey' => $licenseKey, 'deviceId' => $hwid, 'nonce' => $nonceData['nonce']];
            if ($deviceName !== null) {
                $payload['deviceName'] = $deviceName;
            }
            if ($version !== null) {
                $payload['version'] = $version;
            }
            $data   = $this->post('api/v1/activate', $payload);
            $result = LicenseResult::fromArray($data);
            if ($result->isValid) {
                $this->saveCache($licenseKey, $result);
            }
            return $result;
        } catch (\Throwable) {
            // [S-Continuity] If this device already activated successfully before (e.g. an app
            // that re-runs activate() on every launch, or a reinstall that kept the cache file),
            // fall back to that cached result instead of failing outright.
            $cached = $this->loadCache($licenseKey);
            if ($cached !== null) {
                return $cached;
            }
            return new LicenseResult(isValid: false, message: 'Cannot reach license server.', isOffline: true);
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
        $payload = ['licenseKey' => $licenseKey, 'eventName' => $eventName, 'quantity' => $quantity];
        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }
        try {
            $data = $this->post('api/v1/meter', $payload);
            return (bool) ($data['recorded'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Floating licenses ─────────────────────────────────────────────────────

    /**
     * Checks out a concurrent seat for a floating license.
     */
    public function checkout(
        string  $licenseKey,
        ?string $deviceId   = null,
        ?string $deviceName = null
    ): FloatingSession {
        $hwid    = $deviceId ?? $this->getHardwareId();
        $payload = ['licenseKey' => $licenseKey, 'deviceId' => $hwid];
        if ($deviceName !== null) {
            $payload['deviceName'] = $deviceName;
        }
        $data = $this->post('api/v1/float/checkout', $payload);
        return FloatingSession::fromArray($data);
    }

    /**
     * Keeps a floating session alive. Call every 4–5 minutes.
     *
     * [S-Float] Session token in the POST body — never the URL path — matches
     * FloatingController.Heartbeat's FloatingTokenRequest exactly.
     */
    public function heartbeat(string $sessionToken): FloatingSession
    {
        $data = $this->post('api/v1/float/heartbeat', ['sessionToken' => $sessionToken]);
        return FloatingSession::fromArray($data);
    }

    /**
     * Releases a floating seat back to the pool.
     *
     * [S-Float] Session token in the POST body — never the URL path — matches
     * FloatingController.Checkin's FloatingTokenRequest exactly (also: it's a POST, not DELETE).
     */
    public function checkin(string $sessionToken): void
    {
        $this->post('api/v1/float/checkin', ['sessionToken' => $sessionToken]);
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

            $expiresAt = new \DateTime((string) ($payload['expiresAt'] ?? ''), new \DateTimeZone('UTC'));
            $now       = new \DateTime('now', new \DateTimeZone('UTC'));
            if ($expiresAt < $now) {
                return array_merge(['isValid' => false, 'message' => 'Token expired.'], $payload);
            }

            return array_merge(['isValid' => true, 'message' => 'Valid.'], $payload);
        } catch (\Throwable) {
            return ['isValid' => false, 'message' => 'Invalid or corrupt token.'];
        }
    }

    /**
     * Verifies an offline token, additionally checks it was issued for this device, and — on
     * success — persists the payload to local disk so a later run can call validateOffline()
     * without needing the original token again.
     */
    public function activateOffline(string $token, string $publicKeyBase64, string $deviceId): array
    {
        $result = $this->verifyOfflineToken($token, $publicKeyBase64);
        if (!($result['isValid'] ?? false)) {
            return $result;
        }

        if (strcasecmp((string) ($result['deviceId'] ?? ''), $deviceId) !== 0) {
            return array_merge($result, [
                'isValid' => false,
                'message' => 'Token was issued for a different device.',
            ]);
        }

        try {
            $payload = $result;
            unset($payload['isValid'], $payload['message']);
            file_put_contents(
                $this->offlineCachePath($deviceId),
                json_encode($payload, JSON_THROW_ON_ERROR),
                LOCK_EX
            );
        } catch (\Throwable) {
            // best-effort — cache failure must never block a successful verification
        }

        return $result;
    }

    /**
     * Reads the locally persisted offline-activation result (from a prior activateOffline()
     * call) and checks it's still within its validity window. No network call, no token
     * needed — call this on every app launch once already offline-activated.
     */
    public function validateOffline(string $deviceId): array
    {
        $path = $this->offlineCachePath($deviceId);
        if (!file_exists($path)) {
            return ['isValid' => false, 'message' => 'No local offline activation found.'];
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                return ['isValid' => false, 'message' => 'Corrupt local offline activation.'];
            }

            if (strcasecmp((string) ($payload['deviceId'] ?? ''), $deviceId) !== 0) {
                return array_merge(['isValid' => false, 'message' => 'Device mismatch.'], $payload);
            }

            $expiresAt = new \DateTime((string) ($payload['expiresAt'] ?? ''), new \DateTimeZone('UTC'));
            $now       = new \DateTime('now', new \DateTimeZone('UTC'));
            if ($expiresAt < $now) {
                return array_merge(['isValid' => false, 'message' => 'Offline activation expired.'], $payload);
            }

            return array_merge(['isValid' => true, 'message' => 'Valid (offline).'], $payload);
        } catch (\Throwable) {
            return ['isValid' => false, 'message' => 'Corrupt local offline activation.'];
        }
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
        $hash = substr(hash('sha256', $deviceId), 0, 16);
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . ".permitcore_offline_{$hash}";
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

    private function saveCache(string $licenseKey, LicenseResult $result): void
    {
        // The server only issues offlineCacheToken when a grace period is configured, so a
        // null/missing token here already means "nothing to cache," same as the old
        // offlineGraceDays check used to mean.
        if (!$this->enableOfflineCache || empty($result->offlineCacheToken)) {
            return;
        }
        try {
            // Safe to read before verifying — only used to pick which tenant's public-key
            // endpoint to fetch. The verifyGraceCacheToken() call below is what actually
            // establishes trust before anything gets persisted to disk.
            $tenantSlug = $this->extractUnverifiedTenantSlug($result->offlineCacheToken);
            if ($tenantSlug === null) {
                return;
            }

            $pubKeyData = $this->get('api/v1/' . rawurlencode($tenantSlug) . '/public-key');
            $publicKey  = $pubKeyData['publicKey'] ?? null;
            if (!is_string($publicKey) || $publicKey === '') {
                return;
            }

            // Verify before persisting anything — never cache a token this SDK can't itself
            // verify later; that would just recreate the old "trust an opaque file" problem.
            $check = $this->verifyGraceCacheToken($result->offlineCacheToken, $publicKey);
            if (!($check['isValid'] ?? false)) {
                return;
            }

            $entry = ['token' => $result->offlineCacheToken, 'publicKey' => $publicKey];
            file_put_contents(
                $this->cachePath($licenseKey),
                json_encode($entry, JSON_THROW_ON_ERROR),
                LOCK_EX
            );
        } catch (\Throwable) {
            // cache failure must never block the normal online flow
        }
    }

    private function loadCache(string $licenseKey): ?LicenseResult
    {
        if (!$this->enableOfflineCache) {
            return null;
        }
        $path = $this->cachePath($licenseKey);
        if (!file_exists($path)) {
            return null;
        }
        try {
            $entry = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entry) || !isset($entry['token'], $entry['publicKey'])) {
                return null;
            }

            // No network call here — verification uses only the public key persisted alongside
            // the token at save time. This is the entire point: a hand-edited cache file (or one
            // copied to another machine) fails ECDSA verification instead of silently working.
            $check = $this->verifyGraceCacheToken((string) $entry['token'], (string) $entry['publicKey']);
            if (!($check['isValid'] ?? false) || !isset($check['payload']) || !is_array($check['payload'])) {
                return null;
            }

            $p = $check['payload'];

            $validUntilDisplay = (string) ($p['validUntil'] ?? '');
            try {
                $dt = new \DateTime($validUntilDisplay, new \DateTimeZone('UTC'));
                $validUntilDisplay = $dt->format('d M Y');
            } catch (\Throwable) {
                // fall back to the raw ISO string if unparsable — display only, never fatal
            }

            return new LicenseResult(
                isValid:              (bool)  ($p['isValid'] ?? false),
                productName:                   $p['productName'] ?? null,
                remainingActivations: isset($p['remainingActivations']) ? (int) $p['remainingActivations'] : null,
                expiresAt:                     $p['expiresAt'] ?? null,
                message:              "Offline mode — valid until {$validUntilDisplay} (cryptographically verified)",
                features:                      $p['features'] ?? null,
                isOffline:            true,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function cachePath(string $licenseKey): string
    {
        $hash = substr(hash('sha256', $licenseKey), 0, 16);
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . ".permitcore_cache_{$hash}";
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

            $validUntil = new \DateTime((string) ($payload['validUntil'] ?? ''), new \DateTimeZone('UTC'));
            $now        = new \DateTime('now', new \DateTimeZone('UTC'));
            if ($validUntil < $now) {
                return ['isValid' => false, 'message' => 'Grace period expired.', 'payload' => $payload];
            }

            return ['isValid' => true, 'message' => 'Valid.', 'payload' => $payload];
        } catch (\Throwable) {
            return ['isValid' => false, 'message' => 'Invalid or corrupt token.'];
        }
    }

    // Reads only the `tenantSlug` field out of the token's payload, WITHOUT verifying the
    // signature — safe to do because it's only used to pick which tenant's public-key endpoint
    // to fetch. The subsequent verifyGraceCacheToken() call is what actually establishes trust
    // before anything gets persisted to disk.
    private function extractUnverifiedTenantSlug(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== 'pc_grace_v1') {
            return null;
        }
        try {
            $json = $this->base64UrlDecode($parts[1]);
            if ($json === false) {
                return null;
            }
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !isset($payload['tenantSlug']) || !is_string($payload['tenantSlug'])) {
                return null;
            }
            return $payload['tenantSlug'];
        } catch (\Throwable) {
            return null;
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

    private function request(string $method, string $path, ?string $body): array
    {
        $ch = curl_init("{$this->baseUrl}/{$path}");
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => 'PermitCore-PHP/1.0',
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
            ]);
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        }

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // [QA-019] curl_close() is a deprecated no-op as of PHP 8.5 — curl handles have been
        // reference-counted CurlHandle objects (not resources needing manual closing) since PHP
        // 8.0, and calling this here emitted a real deprecation notice on PHP 8.5 that corrupted
        // every response: the notice's own output ran before this method's caller could send
        // headers, producing "headers already sent" warnings and turning a clean 403/CSV response
        // into HTML-prefixed garbage. $ch is released automatically when it goes out of scope.

        if ($errno !== CURLE_OK || $raw === false) {
            throw new \RuntimeException("cURL error {$errno}");
        }

        if ($status >= 400) {
            throw new \RuntimeException("HTTP {$status}: {$raw}");
        }

        if ($raw === '' || $raw === 'null') {
            return [];
        }

        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
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
            features:                      $data['features']              ?? null,
            isTrial:              (bool)  ($data['isTrial']              ?? false),
            trialDaysRemaining:   isset($data['trialDaysRemaining']) ? (int) $data['trialDaysRemaining'] : null,
            nodeLocked:           (bool)  ($data['nodeLocked']           ?? false),
            offlineGraceDays:     isset($data['offlineGraceDays'])    ? (int) $data['offlineGraceDays']    : null,
            isOffline:            (bool)  ($data['isOffline']            ?? false),
            minVersion:                    $data['minVersion']            ?? null,
            maxVersion:                    $data['maxVersion']            ?? null,
            offlineCacheToken:             $data['offlineCacheToken']     ?? null,
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
            'features'            => $this->features,
            'isTrial'             => $this->isTrial,
            'trialDaysRemaining'  => $this->trialDaysRemaining,
            'nodeLocked'          => $this->nodeLocked,
            'offlineGraceDays'    => $this->offlineGraceDays,
            'isOffline'           => $this->isOffline,
            'minVersion'          => $this->minVersion,
            'maxVersion'          => $this->maxVersion,
            'offlineCacheToken'   => $this->offlineCacheToken,
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
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            success:      (bool)  ($data['success']      ?? false),
            sessionToken:          $data['sessionToken']  ?? null,
            expiresAt:             $data['expiresAt']     ?? null,
            message:               $data['message']       ?? null,
        );
    }
}
