# PermitCore PHP SDK

Official PHP client for [PermitCore](https://permitcore.dev) license management.

**Requirements:** PHP 8.0+, `ext-curl`, `ext-json`

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

`hasFeature(string $feature): bool` — case-insensitive feature check.
