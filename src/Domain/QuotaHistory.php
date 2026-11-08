<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * The activity entry for a quota change. The actor is named in the sentence, and the missing side of a create or delete is a dash so it is not stored as zero. A limit of 0, and a server limit that is empty, mean unlimited and are written that way, because "0% CPU" or "— servers" would read as none or as a missing quota.
 */
final class QuotaHistory
{
    public const string EVENT = 'user:provisioner.quota';

    public const string REMOVED_EVENT = 'user:provisioner.quota_removed';

    public const string ACCESS_EVENT = 'user:provisioner.quota_access';

    public const string BAN_EVENT = 'user:provisioner.quota_ban';

    public const string UNBAN_EVENT = 'user:provisioner.quota_unban';

    /** Changing one of these writes a limits entry. */
    public const array LIMIT_FIELDS = ['cpu', 'memory', 'disk', 'server_limit'];

    /** Changing one of these writes an access entry, because it decides which eggs and nodes the owner may use. */
    public const array ACCESS_FIELDS = ['egg_mode', 'node_mode', 'allow_all_eggs', 'allowed_egg_ids', 'allowed_node_ids'];

    /**
     * @return array<string, string>
     */
    public static function properties(?string $actor, ?Quota $before, ?Quota $after, string $unlimited = 'unlimited'): array
    {
        return [
            'actor' => self::actor($actor),
            'old_cpu' => self::figure($before, $before?->cpu, $unlimited, '%'),
            'old_memory' => self::figure($before, $before?->memory, $unlimited),
            'old_disk' => self::figure($before, $before?->disk, $unlimited),
            'old_servers' => self::figure($before, $before?->serverLimit, $unlimited),
            'new_cpu' => self::figure($after, $after?->cpu, $unlimited, '%'),
            'new_memory' => self::figure($after, $after?->memory, $unlimited),
            'new_disk' => self::figure($after, $after?->disk, $unlimited),
            'new_servers' => self::figure($after, $after?->serverLimit, $unlimited),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function access(?string $actor, string $eggs, string $nodes): array
    {
        return [
            'actor' => self::actor($actor),
            'eggs' => $eggs,
            'nodes' => $nodes,
        ];
    }

    /** @return array<string, string> */
    public static function notice(?string $actor): array
    {
        return [
            'actor' => self::actor($actor),
        ];
    }

    private static function actor(?string $actor): string
    {
        $name = trim(strip_tags($actor ?? ''));
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return $name === '' ? 'system' : $name;
    }

    private static function figure(?Quota $quota, ?int $value, string $unlimited, string $unit = ''): string
    {
        if ($quota === null) {
            return '—';
        }

        return $value === null || $value === 0 ? $unlimited : $value.$unit;
    }
}
