<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * A saved default is four amounts. All four blank means no default is saved. A missing default is not a quota of zeros.
 */
final class DefaultQuota
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function parse(array $config): ?Quota
    {
        $cpu = self::one($config['default_cpu'] ?? null);
        $memory = self::one($config['default_memory'] ?? null);
        $disk = self::one($config['default_disk'] ?? null);
        $servers = self::one($config['default_server_limit'] ?? null);
        $present = array_filter(
            [$cpu, $memory, $disk, $servers],
            static fn (?int $amount): bool => $amount !== null,
        );

        if ($present === []) {
            return null;
        }

        if (count($present) !== 4 || $cpu === null || $memory === null || $disk === null || $servers === null) {
            throw new InvalidResourceAmountException();
        }

        return new Quota($cpu, $memory, $disk, $servers);
    }

    private static function one(mixed $value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return QuotaLimits::parse($value);
    }
}
