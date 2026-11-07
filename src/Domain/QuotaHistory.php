<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * The activity entry for a quota change. The actor is named in the sentence, and the missing side of a create or delete is a dash so it is not stored as zero. A limit of 0, and a server limit that is empty, mean unlimited and are written that way, because "0% CPU" or "— servers" would read as none or as a missing quota.
 */
final class QuotaHistory
{
    public const string EVENT = 'user:provisioner.quota';

    /**
     * @return array<string, string>
     */
    public static function properties(?string $actor, ?Quota $before, ?Quota $after, string $unlimited = 'unlimited'): array
    {
        $name = trim(strip_tags($actor ?? ''));
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return [
            'actor' => $name === '' ? 'system' : $name,
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

    private static function figure(?Quota $quota, ?int $value, string $unlimited, string $unit = ''): string
    {
        if ($quota === null) {
            return '—';
        }

        return $value === null || $value === 0 ? $unlimited : $value.$unit;
    }
}
