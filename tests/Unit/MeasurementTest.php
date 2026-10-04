<?php
declare(strict_types=1);
namespace Wmos\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Wmos\Application\Measurement;
use Wmos\Application\Experiments;
use Wmos\Domain\Money;
final class MeasurementTest extends TestCase {
    public function testEveryModelConservesIntegerCredit(): void {
        $points = [
            ['uuid' => 'a', 'channel' => 'email', 'occurred_at' => '2026-01-01 00:00:00'],
            ['uuid' => 'b', 'channel' => 'social', 'occurred_at' => '2026-01-02 00:00:00'],
            ['uuid' => 'c', 'channel' => 'direct', 'occurred_at' => '2026-01-03 00:00:00'],
        ];
        foreach (Measurement::MODELS as $model) {
            $weights = Measurement::weights($points, $model, strtotime('2026-01-04 UTC'));
            self::assertSame(1000000, array_sum($weights));
            $amounts = (new Money(101, 'USD'))->allocate($weights);
            self::assertSame(101, array_sum(array_map(static fn(Money $amount): int => $amount->minor, $amounts)));
        }
        self::assertSame([0 => 0, 1 => 1000000, 2 => 0], Measurement::weights($points, 'last_non_direct', strtotime('2026-01-04 UTC')));
        self::assertSame(['unattributed' => 1000000], Measurement::weights([], 'linear', time()));
    }
    public function testStableAssignmentAndVariantBoundary(): void {
        self::assertSame(Experiments::bucket('identity', 'seed'), Experiments::bucket('identity', 'seed'));
        $variants = [['key' => 'a', 'weight' => 5000], ['key' => 'b', 'weight' => 5000]];
        self::assertSame('a', Experiments::variant(4999, $variants));
        self::assertSame('b', Experiments::variant(5000, $variants));
    }
    public function testIntervalsHandleEmptyAndBoundarySamples(): void {
        self::assertNull(Experiments::wilson(0, 0));
        self::assertGreaterThan(0, Experiments::wilson(0, 10)['upper']);
        self::assertLessThan(1, Experiments::wilson(10, 10)['lower']);
    }
}
