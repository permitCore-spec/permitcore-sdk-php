<?php

declare(strict_types=1);

namespace PermitCore\Tests;

use PermitCore\PermitCoreClient;
use PHPUnit\Framework\TestCase;

/**
 * Shared cross-SDK protocol test-vector suite (CTO review F-27).
 *
 * Loads SDKs/test-vectors/vectors.json — generated once and self-verified, never edited here —
 * and drives this SDK's own offline/grace-cache token verification against every vector, plus a
 * request-shape (required/optional JSON key) check for validate()/activate() so a future silent
 * field drift (e.g. the missing `version` field this session fixed) fails a test instead of
 * shipping unnoticed.
 */
final class VectorsTest extends TestCase
{
    private static ?array $vectors = null;

    private static function vectors(): array
    {
        if (self::$vectors === null) {
            $path = __DIR__ . '/../../test-vectors/vectors.json';
            $json = file_get_contents($path);
            self::assertNotFalse($json, "Could not read vectors.json at {$path}");
            self::$vectors = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        }
        return self::$vectors;
    }

    private static function publicKey(): string
    {
        return self::vectors()['signingKeyPair']['publicKeyBase64Spki'];
    }

    public static function offlineTokenVectorProvider(): array
    {
        $cases = [];
        foreach (self::vectors()['offlineTokenVectors'] as $v) {
            $cases[$v['name']] = [$v];
        }
        return $cases;
    }

    public static function graceTokenVectorProvider(): array
    {
        $cases = [];
        foreach (self::vectors()['graceTokenVectors'] as $v) {
            $cases[$v['name']] = [$v];
        }
        return $cases;
    }

    /**
     * @dataProvider offlineTokenVectorProvider
     */
    public function testOfflineTokenVector(array $vector): void
    {
        $client = new PermitCoreClient('http://localhost');
        $result = $client->verifyOfflineToken($vector['token'], self::publicKey());

        // verifyOfflineToken() returns a FLAT associative array — isValid/message are merged
        // with the decoded payload fields at the top level, NOT nested under a 'payload' key.
        self::assertSame($vector['expectValid'], $result['isValid'], $vector['name'] . ': isValid mismatch');

        if ($vector['expectValid']) {
            $expected = $vector['expectedPayload'];
            self::assertSame($expected['deviceId'], $result['deviceId'] ?? null, $vector['name'] . ': deviceId');
            self::assertSame($expected['licenseId'], $result['licenseId'] ?? null, $vector['name'] . ': licenseId');
            self::assertSame($expected['tenantId'], $result['tenantId'] ?? null, $vector['name'] . ': tenantId');
            self::assertSame(
                $expected['maxActivations'],
                $result['maxActivations'] ?? null,
                $vector['name'] . ': maxActivations'
            );
        }
    }

    /**
     * @dataProvider graceTokenVectorProvider
     */
    public function testGraceTokenVector(array $vector): void
    {
        $client = new PermitCoreClient('http://localhost');
        $result = $client->verifyGraceCacheToken($vector['token'], self::publicKey());

        // Unlike verifyOfflineToken(), verifyGraceCacheToken() keeps isValid/message at the top
        // level but nests the decoded payload under a 'payload' key — NOT flat. Confirmed by
        // reading PermitCoreClient::verifyGraceCacheToken()/loadCache() before writing this.
        self::assertSame($vector['expectValid'], $result['isValid'], $vector['name'] . ': isValid mismatch');

        if ($vector['expectValid']) {
            self::assertArrayHasKey('payload', $result, $vector['name'] . ': expected nested payload key');
            $payload  = $result['payload'];
            $expected = $vector['expectedPayload'];
            self::assertSame($expected['deviceId'], $payload['deviceId'] ?? null, $vector['name'] . ': deviceId');
            self::assertSame(
                $expected['licenseKeyHash'],
                $payload['licenseKeyHash'] ?? null,
                $vector['name'] . ': licenseKeyHash'
            );
            self::assertSame(
                $expected['remainingActivations'],
                $payload['remainingActivations'] ?? null,
                $vector['name'] . ': remainingActivations'
            );
            self::assertSame($expected['isValid'], $payload['isValid'] ?? null, $vector['name'] . ': payload.isValid');
        }
    }

    /**
     * Regression coverage for the `version` gap fixed alongside this test: mirrors
     * PermitCoreClient::validate()'s exact payload-building logic (see the private
     * buildValidatePayload() helper below) and asserts the resulting JSON key set matches
     * requestShapes.validate's requiredKeys/optionalKeys exactly, with and without version set.
     */
    public function testValidateRequestShape(): void
    {
        $shape = self::vectors()['requestShapes']['validate'];

        $withoutVersion = $this->buildValidatePayload('PERMIT-TEST', null);
        $withVersion    = $this->buildValidatePayload('PERMIT-TEST', '1.0');

        self::assertSame($shape['requiredKeys'], array_keys($withoutVersion));
        self::assertEqualsCanonicalizing(
            array_merge($shape['requiredKeys'], $shape['optionalKeys']),
            array_keys($withVersion)
        );
    }

    /**
     * Same as above for activate(): confirms `version` is included only when non-null, alongside
     * the pre-existing conditional `deviceName` field.
     */
    public function testActivateRequestShape(): void
    {
        $shape = self::vectors()['requestShapes']['activate'];

        $withoutOptionals = $this->buildActivatePayload('PERMIT-TEST', 'device-1', 'nonce-1', null, null);
        $withOptionals    = $this->buildActivatePayload('PERMIT-TEST', 'device-1', 'nonce-1', 'My Device', '1.0');

        self::assertSame($shape['requiredKeys'], array_keys($withoutOptionals));
        self::assertEqualsCanonicalizing(
            array_merge($shape['requiredKeys'], $shape['optionalKeys']),
            array_keys($withOptionals)
        );
    }

    /** Mirrors PermitCoreClient::validate()'s request-body-building logic exactly. */
    private function buildValidatePayload(string $licenseKey, ?string $version): array
    {
        $payload = ['licenseKey' => $licenseKey];
        if ($version !== null) {
            $payload['version'] = $version;
        }
        return $payload;
    }

    /** Mirrors PermitCoreClient::activate()'s request-body-building logic exactly. */
    private function buildActivatePayload(
        string  $licenseKey,
        string  $deviceId,
        string  $nonce,
        ?string $deviceName,
        ?string $version
    ): array {
        $payload = ['licenseKey' => $licenseKey, 'deviceId' => $deviceId, 'nonce' => $nonce];
        if ($deviceName !== null) {
            $payload['deviceName'] = $deviceName;
        }
        if ($version !== null) {
            $payload['version'] = $version;
        }
        return $payload;
    }
}
