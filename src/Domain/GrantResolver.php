<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * Ban, then the per-user override, then the saved default. The default never replaces an override, and a ban never becomes a quota of zeros.
 */
final class GrantResolver
{
    public static function decide(bool $banned, ?Grant $override, ?Quota $default): AccessDecision
    {
        if ($banned) {
            return AccessDecision::deny('banned');
        }

        if ($override instanceof Grant) {
            return AccessDecision::allow($override);
        }

        if ($default instanceof Quota) {
            return AccessDecision::allow(Grant::fallback($default));
        }

        return AccessDecision::deny('no_quota');
    }
}
