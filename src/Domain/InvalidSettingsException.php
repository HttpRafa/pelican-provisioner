<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

use RuntimeException;

final class InvalidSettingsException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
