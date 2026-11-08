<?php

declare(strict_types=1);

namespace PelicanProvisioner\Observers;

use PelicanProvisioner\Domain\QuotaHistory;
use PelicanProvisioner\Models\QuotaBan;
use PelicanProvisioner\Services\OwnerActivity;

/**
 * Writes one owner-visible entry when self-service is blocked, and one when it is restored.
 */
class RecordQuotaBanHistory
{
    public function __construct(private readonly OwnerActivity $activity) {}

    public function created(QuotaBan $ban): void
    {
        $this->activity->write(
            (int) $ban->user_id,
            QuotaHistory::BAN_EVENT,
            fn (?string $actor): array => QuotaHistory::notice($actor),
        );
    }

    public function deleted(QuotaBan $ban): void
    {
        $this->activity->write(
            (int) $ban->user_id,
            QuotaHistory::UNBAN_EVENT,
            fn (?string $actor): array => QuotaHistory::notice($actor),
        );
    }
}
