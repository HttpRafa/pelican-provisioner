<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class PositiveId
{
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= 2_147_483_647 ? $value : null;
        }

        if (!is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1 || strlen($value) > 10) {
            return null;
        }

        $int = (int) $value;

        if ((string) $int !== $value || $int > 2_147_483_647) {
            return null;
        }

        return $int;
    }
}
