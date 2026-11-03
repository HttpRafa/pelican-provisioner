<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use Illuminate\Support\Facades\DB;
use PelicanProvisioner\Domain\Usage;
use PelicanProvisioner\Domain\UsageFactory;

/**
 * One aggregate on servers.owner_id per user. Registered scoped, not singleton, so a long-lived worker cannot reuse another request's numbers.
 */
final class UsageQuery
{
    /** @var array<int, Usage> */
    private array $reads = [];

    /** @var array<int, Usage> */
    private array $consistentReads = [];

    public function forOwner(int $userId, bool $consistent = false): Usage
    {
        $bucket = $consistent ? $this->consistentReads : $this->reads;

        if (array_key_exists($userId, $bucket)) {
            return $bucket[$userId];
        }

        $usage = $this->aggregate($userId, $consistent);

        if ($consistent) {
            $this->consistentReads[$userId] = $usage;
        } else {
            $this->reads[$userId] = $usage;
        }

        return $usage;
    }

    public function fresh(int $userId): Usage
    {
        unset($this->reads[$userId], $this->consistentReads[$userId]);

        return $this->forOwner($userId, true);
    }

    public function forget(int $userId): void
    {
        unset($this->reads[$userId], $this->consistentReads[$userId]);
    }

    private function aggregate(int $userId, bool $consistent): Usage
    {
        $query = DB::table('servers')->where('owner_id', $userId);

        if ($consistent) {
            $query->useWritePdo();
        }

        $row = $query
            ->selectRaw('COUNT(*) as servers')
            ->selectRaw('COALESCE(SUM(cpu), 0) as cpu')
            ->selectRaw('COALESCE(SUM(memory), 0) as memory')
            ->selectRaw('COALESCE(SUM(disk), 0) as disk')
            ->selectRaw('COALESCE(SUM(CASE WHEN cpu = 0 THEN 1 ELSE 0 END), 0) as unlimited_cpu')
            ->selectRaw('COALESCE(SUM(CASE WHEN memory = 0 THEN 1 ELSE 0 END), 0) as unlimited_memory')
            ->selectRaw('COALESCE(SUM(CASE WHEN disk = 0 THEN 1 ELSE 0 END), 0) as unlimited_disk')
            ->first();

        if ($row === null) {
            return Usage::none();
        }

        return UsageFactory::fromAggregate([
            'cpu' => $row->cpu,
            'memory' => $row->memory,
            'disk' => $row->disk,
            'servers' => $row->servers,
            'unlimited_cpu' => $row->unlimited_cpu,
            'unlimited_memory' => $row->unlimited_memory,
            'unlimited_disk' => $row->unlimited_disk,
        ]);
    }
}
