<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

enum AccessMode: string
{
    case Additive = 'additive';
    case Negative = 'negative';
    case Overwrite = 'overwrite';

    public static function parse(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
