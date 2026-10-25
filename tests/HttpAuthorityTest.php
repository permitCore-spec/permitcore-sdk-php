<?php
declare(strict_types=1);
namespace PermitCore\Tests;

use PermitCore\PermitCoreClient;
use PHPUnit\Framework\TestCase;

final class HttpAuthorityTest extends TestCase
{
    private static $server;
    private static string $baseUrl;
    private static array $files = [];

    public static function rejectionCases(): iterable
    {
        $path = __DIR__ . '/../test-vectors/http-contract.json';
        if (!is_file($path)) $path = __DIR__ . '/../../test-vectors/http-contract.json';
        $contract = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_merge($contract['httpAuthority'], $contract['httpLicenseResponses'], $contract['httpTransientResponses']) as $index => $case) {
            foreach (['validate', 'activate'] as $operation) {
                yield "$operation/{$case['status']}/$index" => [$operation, $case];
            }
        }
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
    public function testHttpResultMatchesSharedContract(string $operation, array $case): void
    {
        file_put_contents(self::$files['case'], json_encode($case, JSON_THROW_ON_ERROR));
        file_put_contents(self::$files['capture'], '');
        $client = new PermitCoreClient(self::$baseUrl, false);
        $result = $operation === 'validate'
            ? $client->validate('QA-HTTP-REJECTED')
            : $client->activate('QA-HTTP-REJECTED', 'qa-device');
        $requests = array_map(fn($line) => json_decode($line, true), file(self::$files['capture'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $posts = array_values(array_filter($requests, fn($r) => $r['method'] === 'POST'));
        self::assertCount($operation === 'validate' && $case['status'] >= 500 ? 3 : 1, $posts);
        self::assertSame('/api/v1/' . $operation, $posts[0]['path']);
        self::assertSame($case['expectedValid'] ?? false, $result->isValid);
        self::assertSame($case['expectedOffline'] ?? false, $result->isOffline);
        self::assertSame($case['errorCode'], $result->errorCode ?? '');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) { proc_terminate(self::$server); proc_close(self::$server); }
        foreach (self::$files as $path) { if (is_file($path)) unlink($path); }
    }
}
