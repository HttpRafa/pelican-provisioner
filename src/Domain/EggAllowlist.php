<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class EggAllowlist
{
    /** @param list<int> $allowedEggIds Empty means every egg, matching the saved setting. */
    public function permits(int $eggId, array $allowedEggIds): bool
    {
        if ($eggId <= 0) {
            return false;
        }

        if ($allowedEggIds === []) {
            return true;
        }

        return in_array($eggId, $allowedEggIds, true);
    }
}
