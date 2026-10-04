<?php

declare(strict_types=1);

namespace Wmos\Infrastructure;

/** Expected policy deferral is not a failed provider attempt. */
final class DeferredException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $delay = 300)
    {
        parent::__construct($message);
    }
}
