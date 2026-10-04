<?php
declare(strict_types=1);
namespace Wmos\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Wmos\Domain\Money;
final class MoneyTest extends TestCase {
    public function testDecimalConversionDoesNotUseFloats(): void {
        self::assertSame(100000000000001, Money::fromDecimal('1000000000000.01', 'USD')->minor);
        self::assertSame(-19, Money::fromDecimal('-0.19', 'USD')->minor);
        self::assertSame(123, Money::fromDecimal('123', 'JPY', 0)->minor);
        self::assertSame(1234, Money::fromDecimal('1.234', 'KWD', 3)->minor);
    }
    public function testPrecisionLossIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromDecimal('1.234', 'USD', 2);
    }
    public function testCurrencyMixingIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        (new Money(10, 'USD'))->add(new Money(10, 'EUR'));
    }
    public function testOverflowIsRejectedBeforeArithmetic(): void {
        $this->expectException(\OverflowException::class);
        (new Money(PHP_INT_MAX, 'USD'))->add(new Money(1, 'USD'));
    }
    public function testRemaindersAreExactForPositiveAndRefundAllocations(): void {
        foreach ([101, -101, PHP_INT_MAX] as $minor) {
            $parts = (new Money($minor, 'USD'))->allocate(['b' => 333333, 'a' => 333334, 'c' => 333333]);
            self::assertSame($minor, array_sum(array_map(static fn(Money $money): int => $money->minor, $parts)));
        }
        $parts = (new Money(1, 'USD'))->allocate(['z' => 1, 'a' => 1]);
        self::assertSame(1, $parts['a']->minor);
        self::assertSame(0, $parts['z']->minor);
    }
}
