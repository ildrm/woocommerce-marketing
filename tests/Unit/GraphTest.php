<?php
declare(strict_types=1);
namespace Wmos\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Wmos\Domain\Graph;
use Wmos\Application\Automation;
final class GraphTest extends TestCase {
    private function graph(): array {
        return ['nodes' => [['id' => 'start', 'type' => 'trigger'], ['id' => 'delay', 'type' => 'delay', 'config' => ['seconds' => 60]], ['id' => 'end', 'type' => 'exit']], 'edges' => [['from' => 'start', 'to' => 'delay'], ['from' => 'delay', 'to' => 'end']]];
    }
    public function testFiniteDagAccepted(): void { Graph::validate($this->graph()); $this->addToAssertionCount(1); }
    public function testCycleRejected(): void {
        $graph = $this->graph(); $graph['edges'][] = ['from' => 'delay', 'to' => 'delay', 'outcome' => 'loop'];
        $this->expectException(\InvalidArgumentException::class); Graph::validate($graph);
    }
    public function testUnreachableNodeRejected(): void {
        $graph = $this->graph(); $graph['nodes'][] = ['id' => 'hidden', 'type' => 'exit'];
        $this->expectException(\InvalidArgumentException::class); Graph::validate($graph);
    }
    public function testMissingUnknownBranchRejected(): void {
        $graph = $this->graph(); $graph['nodes'][1] = ['id' => 'delay', 'type' => 'condition', 'config' => ['rule' => ['field' => 'order_count', 'operator' => 'gt', 'value' => 0]]];
        $graph['edges'][1]['outcome'] = 'true';
        $this->expectException(\InvalidArgumentException::class); Graph::validate($graph);
    }
    public function testCalendarDelayUsesDeclaredZoneAcrossDst(): void {
        $now = strtotime('2026-03-07 17:00:00 UTC');
        $due = Automation::delayDue(['days' => 1, 'at' => '09:00', 'timezone' => 'America/New_York'], $now);
        self::assertSame('2026-03-08 13:00:00', gmdate('Y-m-d H:i:s', $due));
        self::assertSame($now + 86400, Automation::delayDue(['seconds' => 86400], $now));
        $gap = Automation::delayDue(['days' => 1, 'at' => '02:30', 'timezone' => 'America/New_York'], strtotime('2026-03-07 05:00:00 UTC'));
        self::assertSame('2026-03-08 07:00:00', gmdate('Y-m-d H:i:s', $gap));
    }
}
