<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class UsageFactory
{
    /**
     * MySQL returns SUM() as a decimal string. Anything that is not a safe integer fails closed.
     *
     * @param  array<string, int|string|null>  $row
     */
    public static function fromAggregate(array $row): Usage
    {
        return new Usage(
            cpu: self::column($row['cpu'] ?? null),
            memory: self::column($row['memory'] ?? null),
            disk: self::column($row['disk'] ?? null),
            servers: self::column($row['servers'] ?? null),
            unlimitedCpuServers: self::column($row['unlimited_cpu'] ?? null),
            unlimitedMemoryServers: self::column($row['unlimited_memory'] ?? null),
            unlimitedDiskServers: self::column($row['unlimited_disk'] ?? null),
        );
    }

    private static function column(int|string|null $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_int($value)) {
            return $value < 0 ? ResourceAmounts::ABSOLUTE_MAX + 1 : $value;
        }

        if (preg_match('/^(0|[1-9]\d*)(?:\.0+)?$/', $value, $matches) !== 1) {
            return ResourceAmounts::ABSOLUTE_MAX + 1;
        }

        $digits = $matches[1];

        if (strlen($digits) > 10) {
            return ResourceAmounts::ABSOLUTE_MAX + 1;
        }

        $int = (int) $digits;

        if ((string) $int !== $digits || $int > ResourceAmounts::ABSOLUTE_MAX) {
            return ResourceAmounts::ABSOLUTE_MAX + 1;
        }

        return $int;
    }
}
