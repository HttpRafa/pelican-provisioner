<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class SubmittedVariables
{
    public const int MAX_ROWS = 100;

    /**
     * A row without a value is a locked field the form did not submit, so the egg default applies.
     *
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

            if (!array_key_exists('variable_value', $row)) {
                continue;
            }

            $submitted[$name] = $row['variable_value'];
        }

        return $submitted;
    }
}
