<?php

declare(strict_types=1);

/**
 * Tiny mock HTTP router used only by tests/ProductScopingTest.php.
 *
 * PermitCoreClient::request() calls curl directly with no mock seam, so this router is started
 * as a real PHP built-in server (`php -S host:port -t tests/fixtures mock-server-router.php`)
 * background process for the duration of that test class — the only way to prove the actual
 * bytes PermitCoreClient sends on the wire, rather than a hand-mirrored copy of its
 * payload-building logic that could silently drift from the real implementation.
 *
 * Behavior is driven entirely through two file paths passed in via env vars (set once, at
 * server start, from the test's proc_open() call):
 *   - MOCK_RESPONSE_PATH — read on every validate()/activate() request; its current contents
 *     are echoed back verbatim as the JSON response body. The test overwrites this file before
 *     each call to control what LicenseResult::fromArray() parses.
 *   - MOCK_CAPTURE_PATH  — the raw request body of the last validate()/activate() request is
 *     written here, so the test can read back exactly what PermitCoreClient sent.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/api/v1/nonce') {
    echo json_encode(['nonce' => 'test-nonce-123']);
    exit;
}

if ($path === '/api/v1/validate' || $path === '/api/v1/activate') {
    $captureFile = getenv('MOCK_CAPTURE_PATH');
    if ($captureFile !== false) {
        file_put_contents($captureFile, file_get_contents('php://input'));
    }

    $responseFile = getenv('MOCK_RESPONSE_PATH');
    $response     = ($responseFile !== false && file_exists($responseFile))
        ? file_get_contents($responseFile)
        : json_encode(['isValid' => true]);

    echo $response;
    exit;
}

http_response_code(404);
echo json_encode(['message' => 'not found']);
