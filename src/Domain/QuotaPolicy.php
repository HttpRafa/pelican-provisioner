<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final class QuotaPolicy
{
    public function assertCanCreate(Quota $quota, Usage $usage, ResourceAmounts $requested): void
    {
        $this->assertServerSlot($quota, $usage);
        $this->assertResource($quota->cpu, $usage->cpu, $usage->unlimitedCpuServers, $requested->cpu, QuotaResource::Cpu);
        $this->assertResource($quota->memory, $usage->memory, $usage->unlimitedMemoryServers, $requested->memory, QuotaResource::Memory);
        $this->assertResource($quota->disk, $usage->disk, $usage->unlimitedDiskServers, $requested->disk, QuotaResource::Disk);
    }

    public function assertCanUpdate(Quota $quota, Usage $usage, ResourceAmounts $current, ResourceAmounts $requested): void
    {
        $baseline = $usage->without($current);

        $this->assertResource($quota->cpu, $baseline->cpu, $baseline->unlimitedCpuServers, $requested->cpu, QuotaResource::Cpu);
        $this->assertResource($quota->memory, $baseline->memory, $baseline->unlimitedMemoryServers, $requested->memory, QuotaResource::Memory);
        $this->assertResource($quota->disk, $baseline->disk, $baseline->unlimitedDiskServers, $requested->disk, QuotaResource::Disk);
    }

    public function assertWithin(Quota $quota, Usage $usage): void
    {
        $cap = $quota->serverCap();

        if ($cap !== null && $usage->servers > $cap) {
            throw new QuotaViolationException(QuotaResource::Servers);
        }

        $this->assertConsumed($quota->cpu, $usage->cpu, $usage->unlimitedCpuServers, QuotaResource::Cpu);
        $this->assertConsumed($quota->memory, $usage->memory, $usage->unlimitedMemoryServers, QuotaResource::Memory);
        $this->assertConsumed($quota->disk, $usage->disk, $usage->unlimitedDiskServers, QuotaResource::Disk);
    }

    public function maximum(int $limit, int $used, int $unlimitedServers): ?int
    {
        if ($limit === 0) {
            return null;
        }

        if ($unlimitedServers > 0 || $used < 0 || $used >= $limit) {
            return 0;
        }

        return $limit - $used;
    }

    public function remainingServers(Quota $quota, Usage $usage): ?int
    {
        $cap = $quota->serverCap();

        if ($cap === null) {
            return null;
        }

        return max(0, $cap - max(0, $usage->servers));
    }

    private function assertServerSlot(Quota $quota, Usage $usage): void
    {
        $cap = $quota->serverCap();

        if ($cap !== null && ($usage->servers < 0 || $usage->servers >= $cap)) {
            throw new QuotaViolationException(QuotaResource::Servers);
        }
    }

    private function assertResource(int $limit, int $used, int $unlimitedServers, int $requested, QuotaResource $resource): void
    {
        if ($requested < 0 || $requested > ResourceAmounts::ABSOLUTE_MAX || $used < 0 || $unlimitedServers < 0) {
            throw new QuotaViolationException($resource);
        }

        if ($limit === 0) {
            return;
        }

        if ($requested === 0 || $unlimitedServers > 0 || $used > $limit || $requested > ($limit - $used)) {
            throw new QuotaViolationException($resource);
        }
    }

    private function assertConsumed(int $limit, int $used, int $unlimitedServers, QuotaResource $resource): void
    {
        if ($limit === 0) {
            return;
        }

        if ($unlimitedServers > 0 || $used < 0 || $used > $limit) {
            throw new QuotaViolationException($resource);
        }
    }
}
