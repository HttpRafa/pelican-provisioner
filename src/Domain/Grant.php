<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * The limits and egg or node access a user may spend. An override is one row. The default is the saved plugin setting, with global egg and node rules.
 */
final readonly class Grant
{
    public function __construct(
        public Quota $quota,
        public ?string $eggMode,
        public mixed $allowedEggIds,
        public bool $allowAllEggs,
        public ?string $nodeMode,
        public mixed $allowedNodeIds,
        public GrantSource $source,
    ) {}

    public static function override(
        Quota $quota,
        ?string $eggMode,
        mixed $allowedEggIds,
        bool $allowAllEggs,
        ?string $nodeMode,
        mixed $allowedNodeIds,
    ): self {
        return new self($quota, $eggMode, $allowedEggIds, $allowAllEggs, $nodeMode, $allowedNodeIds, GrantSource::Override);
    }

    public static function fallback(Quota $quota): self
    {
        return new self($quota, null, null, false, null, null, GrantSource::Default);
    }
}
