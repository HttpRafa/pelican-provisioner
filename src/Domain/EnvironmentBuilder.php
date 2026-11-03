<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class EnvironmentBuilder
{
    public const int MAX_VALUE_LENGTH = 65535;

    /** 2^53. A float this large can no longer hold every integer exactly, so its digits cannot be trusted. */
    private const float MAX_EXACT_FLOAT = 9_007_199_254_740_992.0;

    /**
     * Client values are kept only for variables the egg marks both viewable and editable.
     * Every other variable is forced back to the egg default.
     *
     * @param  list<EggVariableDefinition>  $definitions
     * @param  array<string, mixed>  $submitted
     * @return array<string, string>
     */
    public function build(array $definitions, array $submitted): array
    {
        $environment = [];

        foreach ($definitions as $definition) {
            $value = $definition->defaultValue;

            if ($definition->userViewable && $definition->userEditable && array_key_exists($definition->environmentName, $submitted)) {
                $value = $this->stringify($submitted[$definition->environmentName]);
            }

            $environment[$definition->environmentName] = $value;
        }

        return $environment;
    }

    /** The form submits null for a blank field and a float for a number field. The egg's rules decide later whether those values are acceptable. */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return $this->stringifyFloat($value);
        }

        if (!is_string($value)) {
            throw new InvalidEnvironmentException();
        }

        if (strlen($value) > self::MAX_VALUE_LENGTH || str_contains($value, "\0")) {
            throw new InvalidEnvironmentException();
        }

        return $value;
    }

    private function stringifyFloat(float $value): string
    {
        if (!is_finite($value) || abs($value) >= self::MAX_EXACT_FLOAT) {
            throw new InvalidEnvironmentException();
        }

        return floor($value) === $value ? (string) (int) $value : (string) $value;
    }
}
