<?php

declare(strict_types=1);

namespace Wmos\Infrastructure;

final class Json
{
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function decode(string $value): array
    {
        $decoded = json_decode($value, true, 12, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ValidationException('Expected a JSON object or array.');
        }
        return $decoded;
    }
}
