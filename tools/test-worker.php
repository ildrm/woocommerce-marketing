<?php
// Concurrency test process, excluded from the release archive.
declare(strict_types=1);
$_SERVER['HTTP_HOST']='127.0.0.1:8089'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SERVER_PROTOCOL']='HTTP/1.1';
require dirname(__DIR__) . '/.runtime/wordpress/wp-load.php';
$plugin=Wmos\Platform\Plugin::instance();
$plugin->services()['queue']->register('test.concurrent',function(array $job) use($plugin): void { $plugin->services()['audit']->record('test.concurrent_effect',$job['uuid'],['job_uuid'=>$job['uuid']]); });
$plugin->services()['queue']->tick();
