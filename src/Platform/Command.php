<?php

declare(strict_types=1);

namespace Wmos\Platform;

/** Operate bounded plugin work from the system cron and inspect safe health data. */
final class Command
{
    public function __construct(private Plugin $plugin)
    {
    }
    /** Show schema, scheduler and durable queue health. */
    public function status(): void
    {
        \WP_CLI::line(wp_json_encode($this->plugin->health(), JSON_PRETTY_PRINT));
    }
    /** Execute one bounded queue tick (maximum 20 jobs and five seconds between jobs). */
    public function tick(): void
    {
        $this->plugin->services()['queue']->tick();
        \WP_CLI::success('Bounded queue tick completed.');
    }
    /** Retry a failed job. Unknown external outcomes require reconciliation. */
    public function retry(array $args): void
    {
        $this->plugin->services()['queue']->retry((string)($args[0] ?? ''));
        \WP_CLI::success('Job queued.');
    }
    /** Run a bounded retention, loyalty expiry and order reconciliation pass. */
    public function maintenance(): void
    {
        $this->plugin->maintenance();
        \WP_CLI::success('Bounded maintenance completed.');
    }
}
