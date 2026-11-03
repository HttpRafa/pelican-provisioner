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
}
