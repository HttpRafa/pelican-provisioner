<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class ResourceAmountParser
{
    public static function parse(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0 || $value > ResourceAmounts::ABSOLUTE_MAX) {
                throw new InvalidResourceAmountException();
            }

            return $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', $value) === 1) {
            if (strlen($value) > 10) {
                throw new InvalidResourceAmountException();
            }

            $int = (int) $value;

            if ((string) $int !== $value || $int > ResourceAmounts::ABSOLUTE_MAX) {
                throw new InvalidResourceAmountException();
            }

            return $int;
        }

        throw new InvalidResourceAmountException();
    }
}
