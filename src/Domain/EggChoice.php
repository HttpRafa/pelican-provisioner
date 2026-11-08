<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final readonly class EggChoice
{
    /**
     * @param  list<int>  $ids
     * @param  list<int>  $excludedIds
     */
    public function __construct(
        public bool $allowAll,
        public array $ids,
        public array $excludedIds = [],
    ) {}
}
