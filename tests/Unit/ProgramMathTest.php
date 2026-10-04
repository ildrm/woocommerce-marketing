<?php
declare(strict_types=1);
namespace Wmos\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Wmos\Application\Programs;
final class ProgramMathTest extends TestCase
{
    public function testHugeIntermediateProductsStillReturnExactRepresentableResult():void
    {
        self::assertSame(PHP_INT_MAX,Programs::proportion(PHP_INT_MAX,PHP_INT_MAX,PHP_INT_MAX));
        self::assertSame(4611686018427387903,Programs::proportion(PHP_INT_MAX,1,2));
        self::assertSame(4611686018427387904,Programs::proportion(PHP_INT_MAX,1,2,true));
        self::assertSame(1875,Programs::proportion(7500,2500,10000,true));
        self::assertSame(0,Programs::proportion(0,PHP_INT_MAX,1,true));
    }
    public function testSmallValuesMatchIntegerRationalOracle():void
    {
        for($a=0;$a<35;++$a){for($n=0;$n<15;++$n){for($d=1;$d<12;++$d){self::assertSame(intdiv($a*$n,$d),Programs::proportion($a,$n,$d));self::assertSame(intdiv($a*$n+intdiv($d,2),$d),Programs::proportion($a,$n,$d,true));}}}
    }
    public function testUnrepresentableResultFails():void{$this->expectException(\OverflowException::class);Programs::proportion(PHP_INT_MAX,2,1);}
}
