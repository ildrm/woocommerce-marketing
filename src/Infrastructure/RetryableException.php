<?php

declare(strict_types=1);

namespace Wmos\Infrastructure;

class RetryableException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $delay = 30)
    {
        parent::__construct($message);
    }
}
