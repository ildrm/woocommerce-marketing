<?php

declare(strict_types=1);

namespace Wmos\Contracts\V1;

use Wmos\Contracts\ProviderOutcome;

/** Stable 1.x extension contract. Transport outcomes never imply delivery. */
interface Provider
{
    public function capabilities(): array;
    public function submit(string $channel, string $destination, array $content, array $configuration, array $secrets, string $operationKey): ProviderOutcome;
}
