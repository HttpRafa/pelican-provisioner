<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final readonly class Quota
{
    public function __construct(
        public int $cpu,
        public int $memory,
        public int $disk,
        public ?int $serverLimit,
    ) {}

    public function serverCap(): ?int
    {
        if ($this->serverLimit === null || $this->serverLimit <= 0) {
            return null;
        }

        return $this->serverLimit;
    }
}
