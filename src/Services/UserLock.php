<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Database\ConnectionInterface;
use PelicanProvisioner\Domain\ProvisioningException;
use PelicanProvisioner\Domain\ProvisioningFailure;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * One lock per user so two creates cannot both pass the quota. MySQL and MariaDB use GET_LOCK, which survives across Octane workers. Other databases use the cache lock.
 */
final class UserLock
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly CacheManager $cache,
    ) {}

    public function run(int $userId, callable $callback): mixed
    {
        $name = 'pp_quota_'.$userId;

        if (in_array($this->connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $row = $this->connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$name]);

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
            return $this->cache->lock($name, 120)->block(10, $callback);
        } catch (ProvisioningException|HttpException $exception) {
            throw $exception;
        } catch (LockTimeoutException) {
            throw new ProvisioningException(ProvisioningFailure::Busy);
        } catch (Throwable $exception) {
            report($exception);

            throw new ProvisioningException(ProvisioningFailure::Busy);
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
