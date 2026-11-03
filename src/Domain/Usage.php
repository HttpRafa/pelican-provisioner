<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

final readonly class Usage
{
    public function __construct(
        public int $cpu,
        public int $memory,
        public int $disk,
        public int $servers,
        public int $unlimitedCpuServers,
        public int $unlimitedMemoryServers,
        public int $unlimitedDiskServers,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0);
    }

    /** @param list<ResourceAmounts> $servers */
    public static function fromServers(array $servers): self
    {
        $cpu = 0;
        $memory = 0;
        $disk = 0;
        $unlimitedCpu = 0;
        $unlimitedMemory = 0;
        $unlimitedDisk = 0;

        foreach ($servers as $server) {
            $cpu = self::add($cpu, $server->cpu);
            $memory = self::add($memory, $server->memory);
            $disk = self::add($disk, $server->disk);

            if ($server->cpu === 0) {
                $unlimitedCpu++;
            }

            if ($server->memory === 0) {
                $unlimitedMemory++;
            }

            if ($server->disk === 0) {
                $unlimitedDisk++;
            }
        }

        return new self($cpu, $memory, $disk, count($servers), $unlimitedCpu, $unlimitedMemory, $unlimitedDisk);
    }

    public function without(ResourceAmounts $current): self
    {
        return new self(
            cpu: $this->cpu - $current->cpu,
            memory: $this->memory - $current->memory,
            disk: $this->disk - $current->disk,
            servers: $this->servers,
            unlimitedCpuServers: $this->unlimitedCpuServers - ($current->cpu === 0 ? 1 : 0),
            unlimitedMemoryServers: $this->unlimitedMemoryServers - ($current->memory === 0 ? 1 : 0),
            unlimitedDiskServers: $this->unlimitedDiskServers - ($current->disk === 0 ? 1 : 0),
        );
    }

    private static function add(int $left, int $right): int
    {
        if ($right < 0 || $left < 0 || $left > ResourceAmounts::ABSOLUTE_MAX || $right > ResourceAmounts::ABSOLUTE_MAX - $left) {
            return ResourceAmounts::ABSOLUTE_MAX + 1;
        }

        return $left + $right;
    }
}
