<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * A user may spend a grant, or is refused. A ban and a missing grant are different reasons so the panel log can say which one happened.
 */
final readonly class AccessDecision
{
    private function __construct(
        public ?string $reason,
        public ?Grant $grant,
    ) {}

    public function allowed(): bool
    {
        return $this->reason === null && $this->grant instanceof Grant;
    }

    public static function allow(Grant $grant): self
    {
        return new self(null, $grant);
    }

    public static function deny(string $reason): self
    {
        return new self($reason, null);
    }
}
