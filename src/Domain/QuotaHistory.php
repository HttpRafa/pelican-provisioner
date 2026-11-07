<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * The activity entry for a quota change. The actor is named in the sentence, and the missing side of a create or delete is a dash so it is not stored as zero.
 */
final class QuotaHistory
{
    public const string EVENT = 'user:provisioner.quota';

    /**
     * @return array<string, string>
     */
    public static function properties(?string $actor, ?Quota $before, ?Quota $after): array
    {
        $name = trim(strip_tags($actor ?? ''));
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return [
            'actor' => $name === '' ? 'system' : $name,
            'old_cpu' => self::figure($before?->cpu),
            'old_memory' => self::figure($before?->memory),
            'old_disk' => self::figure($before?->disk),
            'old_servers' => self::figure($before?->serverLimit),
            'new_cpu' => self::figure($after?->cpu),
            'new_memory' => self::figure($after?->memory),
            'new_disk' => self::figure($after?->disk),
            'new_servers' => self::figure($after?->serverLimit),
        ];
    }

    private static function figure(?int $value): string
    {
        return $value === null ? '—' : (string) $value;
    }
}
