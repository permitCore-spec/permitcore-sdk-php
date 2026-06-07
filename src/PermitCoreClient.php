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
 * Requirements: PHP 8.0+, ext-curl, ext-json
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
    public function validate(string $licenseKey): LicenseResult
    {
        try {
            $data   = $this->get('api/v1/validate/' . rawurlencode($licenseKey));
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
     */
    public function activate(
        string  $licenseKey,
        ?string $deviceId   = null,
        ?string $deviceName = null
    ): LicenseResult {
        $hwid    = $deviceId ?? $this->getHardwareId();
        $payload = ['licenseKey' => $licenseKey, 'deviceId' => $hwid];
        if ($deviceName !== null) {
            $payload['deviceName'] = $deviceName;
        }
        try {
            $data   = $this->post('api/v1/activate', $payload);
            $result = LicenseResult::fromArray($data);
            if ($result->isValid) {
                $this->saveCache($licenseKey, $result);
            }
            return $result;
        } catch (\Throwable) {
            return new LicenseResult(isValid: false, message: 'Cannot reach license server.');
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
     */
    public function heartbeat(string $sessionToken): FloatingSession
    {
        $data = $this->post("api/v1/float/heartbeat/{$sessionToken}", []);
        return FloatingSession::fromArray($data);
    }

    /**
     * Releases a floating seat back to the pool.
     */
    public function checkin(string $sessionToken): void
    {
        $this->request('DELETE', "api/v1/float/checkin/{$sessionToken}", null);
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

    // ── Offline cache ─────────────────────────────────────────────────────────

    private function saveCache(string $licenseKey, LicenseResult $result): void
    {
        if (!$this->enableOfflineCache || $result->offlineGraceDays === null) {
            return;
        }
        try {
            $entry = [
                'result'      => $result->toArray(),
                'valid_until' => time() + $result->offlineGraceDays * 86400,
            ];
            file_put_contents($this->cachePath($licenseKey), json_encode($entry), LOCK_EX);
        } catch (\Throwable) {
            // best-effort — ignore write errors
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
            if (($entry['valid_until'] ?? 0) < time()) {
                return null;
            }
            $result            = LicenseResult::fromArray($entry['result']);
            $result->isOffline = true;
            $result->message   = 'Offline mode — cached result';
            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cachePath(string $licenseKey): string
    {
        $hash = substr(hash('sha256', $licenseKey), 0, 16);
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . ".permitcore_cache_{$hash}";
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
        curl_close($ch);

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
