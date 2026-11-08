<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * The same bounds as a quota an administrator types in: a whole number from 0 through the absolute maximum. 0 stays 0, which means unlimited for CPU, memory, and disk, and no server cap.
 */
final class QuotaLimits
{
    public static function parse(mixed $value): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return ResourceAmountParser::parse($value);
    }
}
