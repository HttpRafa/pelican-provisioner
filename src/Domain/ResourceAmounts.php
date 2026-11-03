<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final readonly class ResourceAmounts
{
    public const int ABSOLUTE_MAX = 4_294_967_295;

    public function __construct(
        public int $cpu,
        public int $memory,
        public int $disk,
    ) {}

    public function growthFrom(self $current): self
    {
        return new self(
            max(0, $this->cpu - $current->cpu),
            max(0, $this->memory - $current->memory),
            max(0, $this->disk - $current->disk),
        );
    }

    public function isZero(): bool
    {
        return $this->cpu === 0 && $this->memory === 0 && $this->disk === 0;
    }
}
