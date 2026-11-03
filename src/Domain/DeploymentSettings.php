<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final readonly class DeploymentSettings
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $ports
     * @param  list<int>  $allowedEggIds
     */
    public function __construct(
        public bool $createUsable,
        public int $databaseLimit,
        public int $allocationLimit,
        public int $backupLimit,
        public int $subdomainLimit,
        public bool $usersCanUpdate,
        public bool $usersCanDelete,
        public array $tags,
        public array $ports,
        public array $allowedEggIds,
    ) {}

    public static function blocked(): self
    {
        return new self(false, 0, 0, 0, 0, false, false, [], [], []);
    }
}
