<?php
declare(strict_types=1);
namespace PermitCore\Tests;
use PermitCore\LicenseResult;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    public function testPaymentStateAndGraceAreParsedAndSerialized(): void
    {
        require_once __DIR__ . '/../src/PermitCoreClient.php';
        $result = LicenseResult::fromArray(['isValid' => true, 'paymentStatus' => 'past_due', 'isInGracePeriod' => true]);
        self::assertSame('past_due', $result->paymentStatus);
        self::assertTrue($result->isInGracePeriod);
        self::assertNull(LicenseResult::fromArray(['isValid' => true])->paymentStatus);
    }
}
