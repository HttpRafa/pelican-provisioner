<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final readonly class EggVariableDefinition
{
    public function __construct(
        public string $environmentName,
        public string $defaultValue,
        public bool $userViewable,
        public bool $userEditable,
    ) {}
}
