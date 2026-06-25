#!/usr/bin/env php
<?php
/**
 * PermitCore PHP SDK Tester
 * Run: php tester.php
 * Requires: PHP 8.0+, ext-curl, ext-json
 */

declare(strict_types=1);

require_once __DIR__ . '/src/PermitCoreClient.php';

use PermitCore\PermitCoreClient;
use PermitCore\LicenseResult;

$apiUrl     = '';
$licenseKey = '';

function ok(string $msg): void   { echo "\033[92m{$msg}\033[0m\n"; }
function fail(string $msg): void { echo "\033[91m{$msg}\033[0m\n"; }
function warn(string $msg): void { echo "\033[93m{$msg}\033[0m\n"; }
function dim(string $msg): void  { echo "\033[90m{$msg}\033[0m\n"; }

function banner(): void {
    echo "\033[95m\n";
    echo "  ╔══════════════════════════════════════╗\n";
    echo "  ║   PermitCore  ·  PHP SDK Tester      ║\n";
    echo "  ╚══════════════════════════════════════╝\n";
    echo "\033[0m\n";
}

function printResult(LicenseResult $r): void {
    echo "\n";
    $r->isValid ? ok('  ✓  LICENSE VALID') : fail('  ✗  LICENSE INVALID');
    if ($r->isOffline ?? false) warn('  ⚡ Offline mode (served from local cache)');

    $fields = [
        'Product'               => $r->productName ?? null,
        'Message'               => $r->message ?? null,
        'Remaining activations' => isset($r->remainingActivations) ? (string)$r->remainingActivations : null,
        'Expires at'            => $r->expiresAt ?? null,
        'Trial'                 => $r->isTrial ? "Yes ({$r->trialDaysRemaining} days remaining)" : null,
        'Node-locked'           => $r->nodeLocked ? 'Yes' : null,
        'Offline grace days'    => isset($r->offlineGraceDays) ? (string)$r->offlineGraceDays : null,
        'Features'              => !empty($r->features) ? implode(', ', $r->features) : null,
    ];
    foreach ($fields as $label => $val) {
        if ($val !== null) printf("  %-24s %s\n", $label, $val);
    }
    if (!empty($r->customFields)) {
        echo "  Custom fields:\n";
        foreach ($r->customFields as $k => $v) echo "    {$k}: {$v}\n";
    }
}

function prompt(string $text): string {
    echo $text;
    return trim((string)fgets(STDIN));
}

function ready(string $apiUrl, string $licenseKey): bool {
    if ($apiUrl === '')     { warn('  Set API URL first (option 1).'); return false; }
    if ($licenseKey === '') { warn('  Set License Key first (option 2).'); return false; }
    return true;
}

while (true) {
    system('clear') ?: system('cls');
    banner();

    $urlColor = $apiUrl     ? "\033[36m" : "\033[90m";
    $keyColor = $licenseKey ? "\033[33m" : "\033[90m";
    echo "  API URL    : {$urlColor}" . ($apiUrl ?: '(not set)') . "\033[0m\n";
    echo "  License    : {$keyColor}" . ($licenseKey ?: '(not set)') . "\033[0m\n";
    echo "\n";
    echo "  [1]  Set API URL\n";
    echo "  [2]  Set License Key\n";
    echo "  [3]  Validate\n";
    echo "  [4]  Activate  (this machine)\n";
    echo "  [5]  Test offline cache  (simulate no server)\n";
    echo "  [7]  Show Hardware ID\n";
    echo "  [Q]  Quit\n";
    echo "\n";

    $choice = strtoupper(prompt('  Choice: '));
    echo "\n";

    switch ($choice) {
        case '1':
            $apiUrl = prompt('  API URL (e.g. http://localhost:5127): ');
            break;

        case '2':
            $licenseKey = prompt('  License Key (PERMIT-XXXX-...): ');
            break;

        case '3':
            if (!ready($apiUrl, $licenseKey)) { prompt('  Press Enter...'); break; }
            echo "  Validating...\n";
            $client = new PermitCoreClient($apiUrl);
            $r = $client->validate($licenseKey);
            printResult($r);
            prompt("\n  Press Enter to continue...");
            break;

        case '4':
            if (!ready($apiUrl, $licenseKey)) { prompt('  Press Enter...'); break; }
            $client = new PermitCoreClient($apiUrl);
            $hwid   = $client->getHardwareId();
            echo "  HWID: " . substr($hwid, 0, 12) . "…\n";
            echo "  Activating...\n";
            $r = $client->activate($licenseKey, null, gethostname());
            printResult($r);
            prompt("\n  Press Enter to continue...");
            break;

        case '5':
            if ($licenseKey === '') { warn('  Set License Key first.'); prompt('  Press Enter...'); break; }
            echo "  Loading from offline cache (no server contact)...\n";
            $client = new PermitCoreClient('http://0.0.0.0:1', enableOfflineCache: true, timeout: 1);
            $r = $client->validate($licenseKey);
            printResult($r);
            if (!($r->isOffline ?? false) && !$r->isValid)
                warn('  No offline cache found. Validate/Activate first to seed the cache.');
            prompt("\n  Press Enter to continue...");
            break;

        case '7':
            $client = new PermitCoreClient('http://localhost');
            $hwid = $client->getHardwareId();
            ok("  Hardware ID: {$hwid}");
            dim('  (This is the device fingerprint used during Activate)');
            prompt("\n  Press Enter to continue...");
            break;

        case 'Q':
            echo "  Bye!\n";
            exit(0);

        default:
            warn('  Unknown option.');
            prompt('  Press Enter...');
            break;
    }
}
