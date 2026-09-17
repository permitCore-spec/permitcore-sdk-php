<?php

declare(strict_types=1);

namespace PermitCore\Tests;

use PermitCore\LicenseResult;
use PermitCore\PermitCoreClient;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the opt-in product-scoping mechanism (`expectedProductId` request
 * field, `errorCode === 'WrongProduct'`, and the always-present `productId` response field)
 * added to validate()/activate() and LicenseResult.
 *
 * Unlike VectorsTest.php's requestShape tests (which mirror PermitCoreClient's private
 * payload-building logic in a duplicate test-local method — fine for that file's purpose, but a
 * mirror can silently drift from the real implementation and still pass), these tests drive the
 * REAL validate()/activate() methods over a real HTTP round-trip against a tiny PHP built-in
 * server (tests/fixtures/mock-server-router.php) started as a background process for this class.
 * PermitCoreClient::request() calls curl directly with no mock seam, so this is the only way to
 * assert the exact bytes PermitCoreClient puts on the wire, not a hand-copied approximation of it.
 */
final class ProductScopingTest extends TestCase
{
    /** @var resource|false|null */
    private static $serverProcess;
    private static string $capturePath;
    private static string $responsePath;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        $pid = getmypid() ?: random_int(1, 99999);
        self::$capturePath  = sys_get_temp_dir() . "/permitcore_test_capture_{$pid}.json";
        self::$responsePath = sys_get_temp_dir() . "/permitcore_test_response_{$pid}.json";

        $port = 18700 + ($pid % 800);
        self::$baseUrl = "http://127.0.0.1:{$port}";

        $docroot = __DIR__ . '/fixtures';
        $router  = $docroot . '/mock-server-router.php';

        $env = array_merge(
            getenv() ?: [],
            [
                'MOCK_CAPTURE_PATH'  => self::$capturePath,
                'MOCK_RESPONSE_PATH' => self::$responsePath,
            ]
        );

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot, $router],
            $descriptors,
            $pipes,
            null,
            $env
        );

        if (self::$serverProcess === false) {
            self::fail('Failed to start the PHP built-in test server.');
        }

        // Give the built-in server a moment to bind before the first request.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(50_000);
        }
        self::fail('Test HTTP server did not start in time.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        @unlink(self::$capturePath);
        @unlink(self::$responsePath);
    }

    protected function setUp(): void
    {
        @unlink(self::$capturePath);
    }

    private function setMockResponse(array $data): void
    {
        file_put_contents(self::$responsePath, json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function capturedRequestBody(): array
    {
        $deadline = microtime(true) + 3.0;
        while (!file_exists(self::$capturePath) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        self::assertFileExists(self::$capturePath, 'Mock server never received a request.');
        $raw = file_get_contents(self::$capturePath);
        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    }

    // ── validate() ───────────────────────────────────────────────────────────

    public function testValidateWithoutExpectedProductIdOmitsField(): void
    {
        $this->setMockResponse(['isValid' => true, 'productId' => '11111111-1111-1111-1111-111111111111']);
        $client = new PermitCoreClient(self::$baseUrl, enableOfflineCache: false);

        $client->validate('PERMIT-TEST-KEY');

        $body = $this->capturedRequestBody();
        self::assertArrayNotHasKey('expectedProductId', $body);
        self::assertSame('PERMIT-TEST-KEY', $body['licenseKey']);
    }

    public function testValidateWithExpectedProductIdIncludesField(): void
    {
        $this->setMockResponse(['isValid' => true, 'productId' => '11111111-1111-1111-1111-111111111111']);
        $client = new PermitCoreClient(self::$baseUrl, enableOfflineCache: false);

        $client->validate('PERMIT-TEST-KEY', expectedProductId: '22222222-2222-2222-2222-222222222222');

        $body = $this->capturedRequestBody();
        self::assertSame('22222222-2222-2222-2222-222222222222', $body['expectedProductId']);
        self::assertSame('PERMIT-TEST-KEY', $body['licenseKey']);
    }

    // ── activate() ───────────────────────────────────────────────────────────

    public function testActivateWithoutExpectedProductIdOmitsField(): void
    {
        $this->setMockResponse(['isValid' => true, 'productId' => '11111111-1111-1111-1111-111111111111']);
        $client = new PermitCoreClient(self::$baseUrl, enableOfflineCache: false);

        $client->activate('PERMIT-TEST-KEY', deviceId: 'device-1');

        $body = $this->capturedRequestBody();
        self::assertArrayNotHasKey('expectedProductId', $body);
        self::assertSame('device-1', $body['deviceId']);
    }

    public function testActivateWithExpectedProductIdIncludesField(): void
    {
        $this->setMockResponse(['isValid' => true, 'productId' => '11111111-1111-1111-1111-111111111111']);
        $client = new PermitCoreClient(self::$baseUrl, enableOfflineCache: false);

        $client->activate(
            'PERMIT-TEST-KEY',
            deviceId: 'device-1',
            expectedProductId: '22222222-2222-2222-2222-222222222222'
        );

        $body = $this->capturedRequestBody();
        self::assertSame('22222222-2222-2222-2222-222222222222', $body['expectedProductId']);
    }

    // ── LicenseResult parsing ────────────────────────────────────────────────

    public function testFromArrayParsesProductId(): void
    {
        $result = LicenseResult::fromArray([
            'isValid'   => true,
            'productId' => '11111111-1111-1111-1111-111111111111',
        ]);

        self::assertSame('11111111-1111-1111-1111-111111111111', $result->productId);
    }

    public function testFromArrayParsesWrongProductErrorCode(): void
    {
        $result = LicenseResult::fromArray([
            'isValid'   => false,
            'errorCode' => 'WrongProduct',
        ]);

        self::assertSame('WrongProduct', $result->errorCode);
        self::assertFalse($result->isValid);
    }

    public function testFromArrayProductIdNullWhenAbsent(): void
    {
        $result = LicenseResult::fromArray(['isValid' => false, 'errorCode' => 'NotFound']);

        self::assertNull($result->productId);
    }

    public function testToArrayRoundTripsProductId(): void
    {
        $result = new LicenseResult(isValid: true, productId: '33333333-3333-3333-3333-333333333333');
        $array  = $result->toArray();

        self::assertSame('33333333-3333-3333-3333-333333333333', $array['productId']);
    }

    // ── End-to-end: server response's productId/errorCode flow through validate() ──────────

    public function testValidateEndToEndParsesProductIdFromResponse(): void
    {
        $this->setMockResponse(['isValid' => true, 'productId' => '44444444-4444-4444-4444-444444444444']);
        $client = new PermitCoreClient(self::$baseUrl, enableOfflineCache: false);

        $result = $client->validate('PERMIT-TEST-KEY');

        self::assertTrue($result->isValid);
        self::assertSame('44444444-4444-4444-4444-444444444444', $result->productId);
    }

    public function testValidateEndToEndParsesWrongProductErrorCode(): void
    {
        $this->setMockResponse([
            'isValid'   => false,
            'errorCode' => 'WrongProduct',
            'productId' => '44444444-4444-4444-4444-444444444444',
        ]);
        $client = new PermitCoreClient(self::$baseUrl, enableOfflineCache: false);

        $result = $client->validate('PERMIT-TEST-KEY', expectedProductId: '55555555-5555-5555-5555-555555555555');

        self::assertFalse($result->isValid);
        self::assertSame('WrongProduct', $result->errorCode);
        self::assertSame('44444444-4444-4444-4444-444444444444', $result->productId);
    }
}
