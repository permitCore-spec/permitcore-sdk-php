<?php
declare(strict_types=1);
namespace PermitCore\Tests;

use PermitCore\PermitCoreClient;
use PHPUnit\Framework\TestCase;

final class TransportContractTest extends TestCase
{
    private static $server;
    private static string $baseUrl;
    private static array $files = [];

    public static function transportCases(): iterable
    {
        $path = __DIR__ . '/../test-vectors/http-contract.json';
        if (!is_file($path)) $path = __DIR__ . '/../../test-vectors/http-contract.json';
        $contract = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ($contract['transportCases'] as $case) yield $case['name'] => [$case];
    }

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../src/PermitCoreClient.php';
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$baseUrl = 'http://' . $address;
        foreach (['case', 'capture', 'stdout', 'stderr'] as $name) {
            self::$files[$name] = tempnam(sys_get_temp_dir(), 'pc-http-');
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', self::$files['stdout'], 'a'], 2 => ['file', self::$files['stderr'], 'a']];
        $environment = array_merge(getenv(), ['PERMITCORE_QA_HTTP_CASE' => self::$files['case'], 'PERMITCORE_QA_HTTP_CAPTURE' => self::$files['capture']]);
        self::$server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/transport-router.php'], $descriptors, $pipes, null, $environment);
        self::assertIsResource(self::$server);
        fclose($pipes[0]);
        $port = (int) substr(strrchr($address, ':'), 1);
        for ($i = 0; $i < 100; $i++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
            if ($probe !== false) { fclose($probe); return; }
            usleep(10000);
        }
        self::fail('Local HTTP fixture did not become ready.');
    }

    /** @dataProvider transportCases */
    public function testSharedTransportContract(array $case): void
    {
        file_put_contents(self::$files['case'], json_encode($case, JSON_THROW_ON_ERROR));
        file_put_contents(self::$files['capture'], '');
        $client = new PermitCoreClient(self::$baseUrl, false, 10, [], null, null, $case['maxRetries'] ?? 2);
        $op = $case['operation'];
        switch ($op) {
            case 'validate': $result = $client->validate('QA-TRANSPORT-KEY'); break;
            case 'activate': $result = $client->activate('QA-TRANSPORT-KEY', 'qa-device'); break;
            case 'checkout': $result = $client->checkout('QA-TRANSPORT-KEY', 'qa-device'); break;
            case 'heartbeat': $result = $client->heartbeat('qa-token'); break;
            case 'meter': $result = $client->meter('QA-TRANSPORT-KEY', 'qa_export'); break;
            case 'deactivate': $result = $client->deactivate('qa-tenant', 'QA-TRANSPORT-KEY', '11111111-2222-3333-4444-555555555555'); break;
            default: $result = $client->tryCheckin('qa-token');
        }
        $requests = array_map(fn($line) => json_decode($line, true), file(self::$files['capture'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        self::assertCount($case['expectedRequests'], array_filter($requests, fn($r) => $r['path'] !== '/api/v1/nonce'));
        self::assertCount($case['expectedNonceRequests'] ?? 0, array_filter($requests, fn($r) => $r['path'] === '/api/v1/nonce'));
        self::assertCount(0, array_filter($requests, fn($r) => $r['path'] === '/redirect-target'));
        if (in_array($op, ['validate', 'activate'], true)) {
            self::assertSame($case['expectedSuccess'], $result->isValid);
            self::assertSame($case['expectedOffline'], $result->isOffline);
            self::assertSame($case['expectedErrorCode'], $result->errorCode ?? '');
        } elseif (in_array($op, ['checkout', 'heartbeat'], true)) self::assertSame($case['expectedSuccess'], $result->success);
        else self::assertSame($case['expectedSuccess'], $result);
    }

    public function testInvalidConfigurationIsRejected(): void
    {
        foreach ([-1, 6] as $retries) {
            try { new PermitCoreClient('https://sdk.example.invalid', false, 10, [], null, null, $retries); self::fail('Invalid retries accepted'); }
            catch (\InvalidArgumentException $expected) { self::assertNotEmpty($expected->getMessage()); }
        }
        $this->expectException(\InvalidArgumentException::class);
        new PermitCoreClient('https://sdk.example.invalid', false, 0);
    }

    public function testConfiguredTimeoutWithoutReplay(): void
    {
        file_put_contents(self::$files['case'], json_encode(['statuses' => [200], 'body' => '{"isValid":true}', 'delayMicroseconds' => 1300000], JSON_THROW_ON_ERROR));
        file_put_contents(self::$files['capture'], '');
        $client = new PermitCoreClient(self::$baseUrl, false, 1, [], null, null, 0);
        $result = $client->validate('QA-TRANSPORT-KEY');
        self::assertFalse($result->isValid);
        self::assertSame('RequestFailed', $result->errorCode);
        self::assertCount(1, file(self::$files['capture'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) { proc_terminate(self::$server); proc_close(self::$server); }
        foreach (self::$files as $path) { if (is_file($path)) unlink($path); }
    }
}
