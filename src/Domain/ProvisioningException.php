<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

use RuntimeException;

final class ProvisioningException extends RuntimeException
{
    public function __construct(
        public readonly ProvisioningFailure $failure,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($failure->value);
    }
}
