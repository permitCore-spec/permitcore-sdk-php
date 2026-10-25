<?php
declare(strict_types=1);
namespace PermitCore\Tests;

use PHPUnit\Framework\TestCase;

final class ComposerAutoloadTest extends TestCase
{
    public static function publicTypes(): iterable
    {
        yield ['PermitCore\\LicenseResult'];
        yield ['PermitCore\\FloatingSession'];
    }

    /** @dataProvider publicTypes */
    public function testDtoAutoloadsBeforeClientInFreshProcess(string $type): void
    {
        // Tests in this process may already have loaded PermitCoreClient.php. A new PHP
        // process proves the distributed Composer map works without that accidental order.
        $code = 'require $argv[1]; $type = $argv[2]; $value = new $type(); echo get_class($value);';
        $process = proc_open([PHP_BINARY, '-r', $code, __DIR__ . '/../vendor/autoload.php', $type],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame($type, $stdout);
    }
}
