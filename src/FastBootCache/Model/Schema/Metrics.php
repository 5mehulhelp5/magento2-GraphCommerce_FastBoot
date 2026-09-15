<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Model\Schema;

final class Metrics
{
    public static array $values = [];
    public static function add(string $key, float $value = 1): void
    {
        self::$values[$key] = (self::$values[$key] ?? 0) + $value;
    }
}
