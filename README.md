# PermitCore PHP SDK

Official PHP client for [PermitCore](https://permitcore.dev) license management.

**Requirements:** PHP 8.0+, `ext-curl`, `ext-json`, `ext-openssl` (present on effectively every standard PHP installation — needed for offline license token verification)

---

## Installation

```bash
composer require permitcore/sdk
```

Or install from source ZIP:

```bash
unzip permitcore-sdk-php.zip -d permitcore-sdk-php
cd your-project
composer require permitcore/sdk:@dev --repository='{"type":"path","url":"../permitcore-sdk-php"}'
```

---

## Quick start

```php
<?php
require 'vendor/autoload.php';

use PermitCore\PermitCoreClient;

$client = new PermitCoreClient('https://your-instance.com');
$result = $client->validate('PERMIT-XXXX-XXXX-XXXX-XXXX');

if ($result->isValid) {
    echo 'Valid! Product: ' . $result->productName . PHP_EOL;

    if ($result->hasFeature('export')) {
        enableExport();
    }
}
```

---

## Validate

```php
$result = $client->validate($licenseKey);

// isValid                  bool
// productName              ?string
// remainingActivations     ?int
// expiresAt                ?string  (ISO 8601)
// features                 ?array   (e.g. ['export', 'api'])
// isTrial                  bool
// trialDaysRemaining       ?int
// nodeLocked               bool
// offlineGraceDays         ?int
// minVersion               ?string
// maxVersion               ?string
// vendorWarning            ?string
// message                  ?string
// isOffline                bool     (true when served from local cache)
// productId                ?string  (the license's real product GUID; see "Product scoping" below)
```

Validate never consumes an activation slot. It falls back to the local disk cache when the server is unreachable, as long as the license has `offlineGraceDays` configured.

---

## Activate

```php
$result = $client->activate(
    licenseKey: 'PERMIT-XXXX-XXXX-XXXX-XXXX',
    deviceId:   null,          // auto-generated HWID when null
    deviceName: 'Production Server #1'
);

if (!$result->isValid) {
    die('Activation failed: ' . $result->message);
}
```

Call `activate()` **once** per installation. Use `validate()` on every subsequent launch.

---

## Product scoping (added 1.1.0)

`validate()`/`activate()` find a key purely by the key itself — by default, any active key
belonging to your tenant validates successfully, regardless of which of your products it was
actually issued for. If your app should only accept keys issued for *this* product, either check
`$result->productId` yourself, or pass `expectedProductId` and let the server reject a mismatch
for you (`$result->errorCode === 'WrongProduct'`). Find your product's ID in the Admin panel
under Products (or on a license's own detail page).

```php
$result = $client->validate($licenseKey, expectedProductId: 'your-product-guid-here');

if ($result->errorCode === 'WrongProduct') {
    die('This key was not issued for this product.');
}
```

`expectedProductId` is entirely optional on both `validate()` and `activate()` — omit it and
nothing changes from prior versions. `$result->productId` is populated on every successful
lookup regardless of whether `expectedProductId` was passed, so existing callers can start
checking it themselves without touching the request side at all.

---

## Meter (usage events)

```php
// Record a single API call
$recorded = $client->meter('PERMIT-XXXX-XXXX-XXXX-XXXX', 'api_call');

// Record bulk usage with metadata
$recorded = $client->meter(
    licenseKey: 'PERMIT-XXXX-XXXX-XXXX-XXXX',
    eventName:  'export',
    quantity:   5,
    meta:       ['format' => 'pdf', 'pages' => 12]
);
```

Returns `true` if the event was recorded on the server.

---

## Floating licenses

```php
// Check out a seat at session start
$session = $client->checkout('PERMIT-XXXX-XXXX-XXXX-XXXX');
if (!$session->success) {
    die('No seats available: ' . $session->message);
}

$token = $session->sessionToken;

// Heartbeat every 4–5 minutes to keep the seat alive
$client->heartbeat($token);

// Release the seat when done
$client->checkin($token);
```

---

## Offline license tokens

An offline activation token (`pc_offline_v1.<payload>.<signature>`) lets your app verify a
license with **zero network calls**, using ECDSA P-256 signature verification against your
tenant's public key (`GET /api/v1/{tenantSlug}/public-key`). Useful for air-gapped or
intermittently-connected deployments.

```php
// Pure local verification — no network call. Never throws.
$result = $client->verifyOfflineToken($token, $publicKeyBase64);

if ($result['isValid']) {
    echo 'Valid! Product: ' . $result['productName'] . PHP_EOL;
    echo 'Expires: ' . $result['expiresAt'] . PHP_EOL;
} else {
    echo 'Invalid: ' . $result['message'] . PHP_EOL;
}
```

```php
// Verify + bind to this device + persist locally (call once, e.g. at install time)
$result = $client->activateOffline($token, $publicKeyBase64, $deviceId);

// On every later launch — no token needed, reads the local cache, still no network call
$result = $client->validateOffline($deviceId);
```

```php
// Optional: ask the server to verify the token AND check its revocation status (requires network)
$result = $client->verifyOfflineOnline($token);
```

All four methods return an array shaped `['isValid' => bool, 'message' => string, ...]` — on
a valid token, the payload fields (`tokenId`, `tenantSlug`, `tenantId`, `licenseId`,
`licenseKeyHash`, `deviceId`, `deviceName`, `productName`, `maxActivations`, `issuedAt`,
`expiresAt`) are merged in alongside `isValid`/`message`. `verifyOfflineToken()` and
`validateOffline()` never throw — malformed, tampered, expired, or missing input all come
back as `isValid = false` with a descriptive `message`.

`activateOffline()`'s local cache is stored in `sys_get_temp_dir()` as
`.permitcore_offline_<hash>` (same convention as the `validate()`/`activate()` cache, keyed
by device ID instead of license key).

---

## Version enforcement

```php
$result = $client->validate($licenseKey);

$myVersion = '2.3.0';
if ($result->minVersion !== null && version_compare($myVersion, $result->minVersion, '<')) {
    die("Please update to version {$result->minVersion} or newer.");
}
if ($result->maxVersion !== null && version_compare($myVersion, $result->maxVersion, '>')) {
    die("This build ({$myVersion}) is not licensed for versions above {$result->maxVersion}.");
}
```

---

## Offline grace pattern

```php
$result = $client->validate($licenseKey); // falls back to cache automatically

if (!$result->isValid) {
    die('License invalid: ' . $result->message);
}

if ($result->isOffline) {
    // Server unreachable — running on cached result
    showNotice('Running in offline mode. Connect to the internet to refresh your license.');
}
```

The cache is stored in `sys_get_temp_dir()` as `.permitcore_cache_<hash>`. It expires after `offlineGraceDays` days.

---

## Constructor options

```php
$client = new PermitCoreClient(
    baseUrl:            'https://your-instance.com',
    enableOfflineCache: true,   // default — set false to always require network
    timeout:            5        // HTTP timeout in seconds
);
```

---

## LicenseResult reference

| Property | Type | Description |
|----------|------|-------------|
| `isValid` | `bool` | True if the license is active and valid |
| `productName` | `?string` | Product the license belongs to |
| `remainingActivations` | `?int` | Slots left before MaxActivations is reached |
| `expiresAt` | `?string` | Expiry date (ISO 8601 UTC), null if perpetual |
| `features` | `?array` | Feature flag list, e.g. `['export','api']` |
| `isTrial` | `bool` | True for trial licenses |
| `trialDaysRemaining` | `?int` | Days until trial expires |
| `nodeLocked` | `bool` | True if bound to a specific device |
| `offlineGraceDays` | `?int` | How many days the cache is valid |
| `minVersion` | `?string` | Minimum app version allowed |
| `maxVersion` | `?string` | Maximum app version allowed |
| `vendorWarning` | `?string` | Non-fatal message from the vendor |
| `message` | `?string` | Reason when `isValid = false` |
| `isOffline` | `bool` | True when result came from local cache |
| `errorCode` | `?string` | Stable, machine-readable failure reason (e.g. `"WrongProduct"`), null on success |
| `productId` | `?string` | The license's real product GUID — always present when the key was found, regardless of whether `expectedProductId` was passed |

`hasFeature(string $feature): bool` — case-insensitive feature check.
