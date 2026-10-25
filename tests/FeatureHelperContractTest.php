<?php
declare(strict_types=1);
namespace PermitCore\Tests;
use PermitCore\LicenseResult;
use PHPUnit\Framework\TestCase;

final class FeatureHelperContractTest extends TestCase
{
    public static function cases(): iterable
    {
        $path = __DIR__ . '/../test-vectors/http-contract.json';
        if (!is_file($path)) $path = __DIR__ . '/../../test-vectors/http-contract.json';
        foreach (json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR)['featureCases'] as $value) yield $value['name'] => [$value];
    }
    /** @dataProvider cases */
    public function testFeatureLookup(array $value): void
    {
        require_once __DIR__ . '/../src/PermitCoreClient.php';
        $result = new LicenseResult(isValid: true, features: $value['features']);
        self::assertSame($value['expected'],$result->hasFeature($value['feature']));
    }
}
