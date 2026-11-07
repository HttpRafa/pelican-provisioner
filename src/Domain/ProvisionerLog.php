<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * Sentences for the panel log. The message is the whole line in that viewer, so the ids stay in the structured context.
 */
final class ProvisionerLog
{
    public static function created(string $server, string $owner, string $egg, int $cpu, int $memory, int $disk): string
    {
        return sprintf(
            'Created server "%s" for %s with the %s egg (%d%% CPU, %d memory, %d disk).',
            self::plain($server),
            self::plain($owner),
            self::plain($egg),
            $cpu,
            $memory,
            $disk,
        );
    }

    public static function denied(string $owner, string $action, string $reason, ?string $server): string
    {
        $target = $server === null || trim($server) === ''
            ? 'no server'
            : 'server "'.self::plain($server).'"';

        return sprintf(
            'Refused %s for %s on %s (%s).',
            self::plain($action),
            self::plain($owner),
            $target,
            self::plain($reason),
        );
    }

    public static function syncFailed(string $server, string $owner): string
    {
        return sprintf(
            'Could not sync resource limits for server "%s" owned by %s. The node did not answer.',
            self::plain($server),
            self::plain($owner),
        );
    }

    private static function plain(string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));

        if ($value === '') {
            return 'unknown';
        }

        if (mb_strlen($value) > 255) {
            return mb_substr($value, 0, 255);
        }

        return $value;
    }
}
