<?php
declare(strict_types=1);
namespace Wmos\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Wmos\Application\{Personalization, Recommendations};
final class PersonalizationTest extends TestCase {
    public function testBoundedTypedStrategiesAccepted(): void {
        Recommendations::validate('pinned', ['product_ids' => [1, 2], 'limit' => 2]);
        Personalization::validate(['surface' => 'banner', 'title' => 'Welcome', 'rule' => ['field' => 'order_count', 'operator' => 'gt', 'value' => 1], 'fallback' => ['title' => 'Browse products']]);
        $this->addToAssertionCount(2);
    }
    public function testPublicFallbackCannotConsumePrivateEvidence(): void {
        $this->expectException(\InvalidArgumentException::class);
        Personalization::validate(['fallback' => ['strategy' => 'purchase_history']]);
    }
    public function testUnlimitedCatalogueQueriesAreImpossible(): void {
        $this->expectException(\InvalidArgumentException::class);
        Recommendations::validate('latest', ['limit' => -1]);
    }
    public function testArbitraryCriteriaAreRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        Recommendations::validate('category', ['category_ids' => [1], 'meta_query' => ['private_key' => 'credential']]);
    }
}
