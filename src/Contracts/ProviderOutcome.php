<?php

declare(strict_types=1);

namespace Wmos\Contracts;

final readonly class ProviderOutcome
{
    public function __construct(public string $state, public ?string $reference = null, public ?string $error = null, public int $retryAfter = 0)
    {
        if (!in_array($state, ['accepted', 'rejected', 'retryable', 'ambiguous'], true)) {
            throw new \InvalidArgumentException('Invalid provider outcome.');
        }
    }
}
