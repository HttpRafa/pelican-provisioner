<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Connection;
use PelicanProvisioner\Domain\ProvisioningException;
use PelicanProvisioner\Domain\ProvisioningFailure;
use Throwable;

/**
 * One lock per user so two creates cannot both pass the quota. MySQL and MariaDB use GET_LOCK, which survives across Octane workers. Other databases use the cache lock. The wait is short because a waiting request keeps a PHP worker busy.
 */
final class UserLock
{
    private const int WAIT_SECONDS = 3;

    private const int CACHE_LOCK_SECONDS = 120;

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheManager $cache,
    ) {}

    public function run(int $userId, callable $callback): mixed
    {
        $name = 'pp_quota_'.$userId;

        if (in_array($this->connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $row = $this->connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, self::WAIT_SECONDS]);

            if ($this->acquired($row) !== 1) {
                throw new ProvisioningException(ProvisioningFailure::Busy);
            }

            try {
                return $callback();
            } finally {
                $this->connection->select('SELECT RELEASE_LOCK(?)', [$name]);
            }
        }

        try {
            $lock = $this->cache->lock($name, self::CACHE_LOCK_SECONDS);
            $lock->block(self::WAIT_SECONDS);
        } catch (LockTimeoutException) {
            throw new ProvisioningException(ProvisioningFailure::Busy);
        } catch (Throwable $exception) {
            report($exception);

            throw new ProvisioningException(ProvisioningFailure::Busy);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function acquired(mixed $row): int
    {
        if (is_object($row) && isset($row->acquired)) {
            return (int) $row->acquired;
        }

        if (is_array($row) && isset($row['acquired'])) {
            return (int) $row['acquired'];
        }

        return 0;
    }
}
