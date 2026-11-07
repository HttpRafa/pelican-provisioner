<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class EggAllowlist
{
    /**
     * An empty list allows nothing. Every egg is only available through the explicit $allowAll setting. Excluded ids are refused even then, so a user can lose eggs from an otherwise open list.
     *
     * @param  list<int>  $allowedEggIds
     * @param  list<int>  $excludedEggIds
     */
    public function permits(int $eggId, array $allowedEggIds, bool $allowAll, array $excludedEggIds = []): bool
    {
        if ($eggId <= 0 || in_array($eggId, $excludedEggIds, true)) {
            return false;
        }

        if ($allowAll) {
            return true;
        }

        return in_array($eggId, $allowedEggIds, true);
    }
}
