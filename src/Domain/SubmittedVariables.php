<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class SubmittedVariables
{
    public const int MAX_ROWS = 100;

    /**
     * @return array<string, mixed>
     */
    public static function fromRepeater(mixed $rows): array
    {
        if ($rows === null) {
            return [];
        }

        if (!is_array($rows)) {
            throw new InvalidEnvironmentException();
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidEnvironmentException();
        }

        $submitted = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidEnvironmentException();
            }

            $name = $row['env_variable'] ?? null;

            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,254}$/', $name) !== 1) {
                continue;
            }

            $submitted[$name] = $row['variable_value'] ?? null;
        }

        return $submitted;
    }
}
