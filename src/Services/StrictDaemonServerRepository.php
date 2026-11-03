<?php

declare(strict_types=1);

namespace PelicanProvisioner\Services;

use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * The panel's deletion service only stops on a ConnectionException. An HTTP error from the node would still remove the panel row, free the quota, and leave the server running. Turning that error into a ConnectionException makes the service stop, while a forced delete still goes through. A 404 means the node has nothing left to delete.
 */
final class StrictDaemonServerRepository extends DaemonServerRepository
{
    public function delete(): void
    {
        try {
            parent::delete();
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return;
            }

            throw new ConnectionException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}
