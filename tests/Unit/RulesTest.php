<?php
declare(strict_types=1);
namespace Wmos\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Wmos\Domain\Rules;
final class RulesTest extends TestCase {
    public function testNestedThreeValuedConditions(): void {
        $unknown = ['field' => 'facts.loyalty_points', 'operator' => 'gt', 'value' => 5];
        $yes = ['field' => 'order_count', 'operator' => 'gte', 'value' => 2];
        self::assertNull(Rules::matches(['all' => [$yes, $unknown]], ['order_count' => '2']));
        self::assertTrue(Rules::matches(['any' => [$yes, $unknown]], ['order_count' => '2']));
        self::assertFalse(Rules::matches(['all' => [$yes, $unknown]], ['order_count' => '1']));
        self::assertNull(Rules::matches(['not' => $unknown], []));
    }
    public function testSqlNeverInterpolatesUserValues(): void {
        $compiled = Rules::compile(['field' => 'state', 'operator' => 'eq', 'value' => "active' OR 1=1 --"]);
        self::assertSame('p.`state` = %s', $compiled['sql']);
        self::assertSame(["active' OR 1=1 --"], $compiled['args']);
    }
    public function testSqlAndInMemoryNumericMembershipAgree(): void {
        $rule = ['field' => 'order_count', 'operator' => 'in', 'value' => [1, 2, 3]];
        self::assertTrue(Rules::matches($rule, ['order_count' => '2']));
        self::assertSame('p.`order_count` IN (%d,%d,%d)', Rules::compile($rule)['sql']);
    }
    public function testUnindexedAttributesRequireAsyncEvaluation(): void {
        $rule = ['field' => 'attributes.locale', 'operator' => 'eq', 'value' => 'fa_IR'];
        self::assertTrue(Rules::matches($rule, ['attributes' => '{"locale":"fa_IR"}']));
        $this->expectException(\DomainException::class);
        Rules::compile($rule);
    }
    public function testExecutableFieldNamesAreRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        Rules::validate(['field' => 'order_count);DROP TABLE profiles', 'operator' => 'eq', 'value' => 0]);
    }
    public function testNumericCoercionIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        Rules::validate(['field' => 'order_count', 'operator' => 'eq', 'value' => 'not-a-number']);
    }
}
