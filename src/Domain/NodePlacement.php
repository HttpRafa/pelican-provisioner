<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final readonly class NodePlacement
{
    /** @param list<int> $nodeIds */
    private function __construct(
        public bool $rejected,
        public array $nodeIds,
    ) {}

    public static function reject(): self
    {
        return new self(true, []);
    }

    /** @param list<int> $nodeIds */
    public static function automatic(array $nodeIds): self
    {
        return new self(false, $nodeIds);
    }

    public static function only(int $nodeId): self
    {
        return new self(false, [$nodeId]);
    }
}
