<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class EggAllowlist
{
    /** @param list<int> $allowedEggIds An empty list allows nothing. Every egg is only available through the explicit $allowAll setting. */
    public function permits(int $eggId, array $allowedEggIds, bool $allowAll): bool
    {
        if ($eggId <= 0) {
            return false;
        }

        if ($allowAll) {
            return true;
        }

        return in_array($eggId, $allowedEggIds, true);
    }
}
