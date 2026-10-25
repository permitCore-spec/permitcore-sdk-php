<?php
declare(strict_types=1);
namespace PermitCore\Tests;

use PermitCore\PermitCoreClient;
use PHPUnit\Framework\TestCase;

final class FloatingContractTest extends TestCase
{
    private static $server;
    private static string $baseUrl;
    private static array $files = [];

    public static function rejectionCases(): iterable
    {
        $path = __DIR__ . '/../test-vectors/http-contract.json';
        if (!is_file($path)) $path = __DIR__ . '/../../test-vectors/http-contract.json';
        $contract = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_merge($contract['floatingCases'], $contract['operationCases']) as $case) yield $case['name'] => [$case];
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
        self::$server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/http-authority-router.php'], $descriptors, $pipes, null, $environment);
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

    /** @dataProvider rejectionCases */
    public function testSharedFloatingContract(array $case): void
    {
        file_put_contents(self::$files['case'], json_encode($case, JSON_THROW_ON_ERROR));
        file_put_contents(self::$files['capture'], '');
        $client = new PermitCoreClient(self::$baseUrl, false);
        if ($case['operation'] === 'meter') {self::assertSame($case['expectedReleased'],$client->meter('QA-METER-KEY','qa_export',$case['quantity'],$case['meta']));$count=1;}
        elseif ($case['operation'] === 'deactivate') { self::assertSame($case['expectedReleased'], $client->deactivate('qa-tenant','QA-DEVICE-KEY','11111111-2222-3333-4444-555555555555'));$count=1; }
        elseif ($case['operation'] === 'checkin') {
            self::assertSame($case['expectedReleased'], $client->tryCheckin('qa-floating-token'));
            $client->checkin('qa-floating-token');
            $count = 2;
        } else {
            $result = $case['operation'] === 'checkout'
                ? $client->checkout('QA-FLOAT-KEY', 'qa-device', 'QA desktop')
                : $client->heartbeat('qa-floating-token');
            self::assertSame($case['expectedSuccess'], $result->success);
            self::assertSame($case['expectedErrorCode'], $result->errorCode ?? '');
            if ($result->success) {
                self::assertSame('qa-floating-token', $result->sessionToken);
                self::assertNotEmpty($result->expiresAt);
            } else { self::assertNull($result->sessionToken); self::assertNull($result->expiresAt); }
            $count = $case["operation"] === "heartbeat" && $case["status"] >= 500 ? 3 : 1;
        }
        $requests = array_map(fn($line) => json_decode($line, true), file(self::$files['capture'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        self::assertCount($count, $requests);
        foreach ($requests as $request) {
            self::assertSame('POST', $request['method']);
            self::assertSame($case['operation'] === 'meter' ? '/api/v1/meter' : ($case['operation'] === 'deactivate' ? '/api/v1/portal/qa-tenant/deactivate' : '/api/v1/float/' . $case['operation']), $request['path']);
            self::assertSame($case['operation'] === 'meter' ? $case['request'] : ($case['operation'] === 'deactivate' ? ['licenseKey'=>'QA-DEVICE-KEY','activationId'=>'11111111-2222-3333-4444-555555555555'] : ($case['operation'] === 'checkout'
                ? ['licenseKey' => 'QA-FLOAT-KEY', 'deviceId' => 'qa-device', 'deviceName' => 'QA desktop']
                : ['sessionToken' => 'qa-floating-token'])), $request['body']);
        }
    }

    public function testActualConnectionRefusalFailsClosed(): void
    {
        $reserved = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        self::assertIsResource($reserved);
        try {
            $client = new PermitCoreClient('http://' . stream_socket_get_name($reserved, false), false, 1);
            foreach ([$client->checkout('QA-FLOAT-KEY', 'qa-device'), $client->heartbeat('qa-floating-token')] as $result) {
                self::assertFalse($result->success);self::assertSame('RequestFailed', $result->errorCode);
                self::assertNull($result->sessionToken);self::assertNull($result->expiresAt);
            }
            self::assertFalse($client->meter('QA-METER-KEY','qa_export'));self::assertFalse($client->deactivate('qa-tenant','QA-DEVICE-KEY','11111111-2222-3333-4444-555555555555'));self::assertFalse($client->tryCheckin('qa-floating-token'));$client->checkin('qa-floating-token');
        } finally { fclose($reserved); }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) { proc_terminate(self::$server); proc_close(self::$server); }
        foreach (self::$files as $path) { if (is_file($path)) unlink($path); }
    }
}
