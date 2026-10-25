<?php
declare(strict_types=1);
namespace PermitCore\Tests;

use PermitCore\PermitCoreClient;
use PHPUnit\Framework\TestCase;

final class CacheTrustTest extends TestCase
{
    private string $directory;
    private $trusted;
    private $attacker;
    private const KEY = 'PERMIT-CACHE-TEST';
    private const PRODUCT = '3c127b6d-0723-4215-9a39-a13cfd838011';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../src/PermitCoreClient.php';
        $this->directory = sys_get_temp_dir() . '/pc-cache-trust-' . bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->trusted = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->attacker = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    }
    protected function tearDown(): void
    {
        foreach (scandir($this->directory) as $file) {
            if ($file !== '.' && $file !== '..') unlink($this->directory . '/' . $file);
        }
        rmdir($this->directory);
    }
    private function pin($key): string
    {
        return str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\r", "\n"], '', openssl_pkey_get_details($key)['key']);
    }
    private function sign(string $prefix, array $payload, bool $attacker = false): string
    {
        $b64 = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $body = $b64(json_encode($payload, JSON_THROW_ON_ERROR));
        openssl_sign($body, $der, $attacker ? $this->attacker : $this->trusted, OPENSSL_ALGO_SHA256);
        // P-256 DER SEQUENCE of two INTEGERs, each at most 33 bytes (short-form lengths).
        $rLength = ord($der[3]);
        $r = substr($der, 4, $rLength);
        $sLength = ord($der[5 + $rLength]);
        $s = substr($der, 6 + $rLength, $sLength);
        $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
        return $prefix . '.' . $body . '.' . $b64($raw);
    }
    private function client(?array $pins = null): PermitCoreClient
    {
        return new PermitCoreClient('http://127.0.0.1:1', true, 1,
            $pins ?? [$this->pin($this->trusted)], $this->directory, ' DEVICE ');
    }
    private function proof(): array
    {
        return ['version' => 1, 'licenseKeySha256' => hash('sha256', self::KEY), 'productId' => self::PRODUCT,
            'deviceId' => 'device', 'deviceActivated' => true, 'nodeLocked' => true, 'isValid' => true,
            'validUntil' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)];
    }
    private function store(string $token, array $extra = []): void
    {
        file_put_contents($this->directory . '/.permitcore_cache_v2_' . hash('sha256', self::KEY),
            json_encode(['token' => $token] + $extra, JSON_THROW_ON_ERROR));
    }
    public function testPinnedContextAuthorizesAndUntrustedCacheKeyDoesNot(): void
    {
        $client = $this->client();
        $this->store($this->sign('pc_grace_v1', $this->proof()));
        self::assertTrue($client->validate(self::KEY, null, self::PRODUCT)->isValid);
        self::assertTrue($client->activate(self::KEY, null, null, null, self::PRODUCT)->isValid);
        self::assertFalse($this->client([])->validate(self::KEY)->isValid);
        self::assertTrue($this->client([$this->pin($this->attacker), $this->pin($this->trusted)])->validate(self::KEY)->isValid);
        $this->store($this->sign('pc_grace_v1', $this->proof(), true), ['publicKey' => $this->pin($this->attacker)]);
        self::assertFalse($client->validate(self::KEY)->isValid);
    }
    public function testSignedWrongContextFailsClosed(): void
    {
        $client = $this->client();
        foreach (['licenseKeySha256' => 'other', 'productId' => 'other', 'deviceId' => 'other',
            'deviceActivated' => false, 'validUntil' => 'bad', 'version' => 2, 'isValid' => 'true'] as $field => $value) {
            $p = $this->proof(); $p[$field] = $value;
            $this->store($this->sign('pc_grace_v1', $p));
            self::assertFalse($client->validate(self::KEY, null, self::PRODUCT)->isValid, $field);
            self::assertFalse($client->activate(self::KEY, null, null, null, self::PRODUCT)->isValid, $field);
        }
    }
    public function testManualCacheReverifiesOriginalToken(): void
    {
        $client = $this->client();
        $p = ['version' => 1, 'deviceId' => 'device', 'productId' => self::PRODUCT, 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)];
        $token = $this->sign('pc_offline_v1', $p);
        self::assertTrue($client->activateOffline($token, null, self::PRODUCT)['isValid']);
        $file = $this->directory . '/.permitcore_offline_v2_' . hash('sha256', 'device');
        self::assertSame(['token' => $token], json_decode(file_get_contents($file), true));
        self::assertTrue($client->validateOffline(null, self::PRODUCT)['isValid']);
        self::assertFalse($client->validateOffline(null, 'other')['isValid']);
        file_put_contents($file, json_encode($p + ['isValid' => true]));
        self::assertFalse($client->validateOffline()['isValid']);
        file_put_contents($file, json_encode(['token' => $this->sign('pc_offline_v1', $p, true), 'publicKey' => $this->pin($this->attacker)]));
        self::assertFalse($client->validateOffline()['isValid']);
        foreach ([null, 'bad', '2000-01-01T00:00:00Z'] as $expires) {
            $p['expiresAt'] = $expires;
            self::assertFalse($client->activateOffline($this->sign('pc_offline_v1', $p))['isValid']);
        }
    }
}
