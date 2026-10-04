<?php
declare(strict_types=1);
namespace Wmos\Tests\Integration;
use PHPUnit\Framework\TestCase;
use Wmos\Platform\Plugin;

final class ConcurrencyTest extends TestCase
{
    public function testTwoIndependentWorkersClaimOneDurableJob(): void {
        if(getenv('WMOS_INTEGRATION')!=='1') { self::markTestSkipped('Enable isolated WordPress integration.'); }
        $plugin=Plugin::instance(); $job=$plugin->services()['queue']->enqueue('test.concurrent',[],'concurrency:' . bin2hex(random_bytes(8)));
        $plugin->database()->update('jobs',$job['uuid'],['available_at'=>'2000-01-01 00:00:00']);
        $workers=[];
        for($i=0;$i<2;++$i) {
            $pipes=[]; $process=proc_open([PHP_BINARY,dirname(__DIR__,2) . '/tools/test-worker.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            self::assertIsResource($process); $workers[]=[$process,$pipes];
        }
        foreach($workers as [$process,$pipes]) { $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0,proc_close($process),$stdout . $stderr); }
        $database=$plugin->database(); $db=$database->db();
        $count=$db->get_var($db->prepare('SELECT COUNT(*) FROM ' . $database->table('audit') . ' WHERE action=%s AND object_uuid=%s','test.concurrent_effect',$job['uuid']));
        self::assertSame(1,(int)$count); self::assertSame('completed',$database->get('jobs',$job['uuid'])['state']);
    }
}
