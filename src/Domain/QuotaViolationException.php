<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

use RuntimeException;

final class QuotaViolationException extends RuntimeException
{
    public function __construct(public readonly QuotaResource $resource)
    {
        parent::__construct($resource->value);
    }
}
