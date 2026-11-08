<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\App\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use PelicanProvisioner\Domain\Grant;
use PelicanProvisioner\Domain\QuotaPolicy;
use PelicanProvisioner\Services\QuotaAccess;
use PelicanProvisioner\Services\UsageQuery;

class QuotaOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $actor = user();

        if (!$actor instanceof User) {
            return [];
        }

        $quota = app(QuotaAccess::class)->grant((int) $actor->id);

        if (!$quota instanceof Grant) {
            return [];
        }

        $usage = app(UsageQuery::class)->forOwner((int) $actor->id);
        $policy = app(QuotaPolicy::class);
        $domain = $quota->quota;
        $suffix = config('panel.use_binary_prefix') ? ' MiB' : ' MB';

        return [
            $this->stat(trans('pelican-provisioner::strings.cpu'), $usage->cpu, $domain->cpu, '%', $policy->maximum($domain->cpu, $usage->cpu, $usage->unlimitedCpuServers)),
            $this->stat(trans('pelican-provisioner::strings.memory'), $usage->memory, $domain->memory, $suffix, $policy->maximum($domain->memory, $usage->memory, $usage->unlimitedMemoryServers)),
            $this->stat(trans('pelican-provisioner::strings.disk'), $usage->disk, $domain->disk, $suffix, $policy->maximum($domain->disk, $usage->disk, $usage->unlimitedDiskServers)),
            $this->stat(trans('pelican-provisioner::strings.servers'), $usage->servers, $domain->serverCap() ?? 0, '', $policy->remainingServers($domain, $usage)),
        ];
    }

    public static function canView(): bool
    {
        $actor = user();

        return $actor instanceof User && app(QuotaAccess::class)->canOpen((int) $actor->id);
    }

    private function stat(string $label, int $used, int $limit, string $suffix, ?int $remaining): Stat
    {
        $unit = $suffix === '' ? '' : ($suffix === '%' ? '%' : $suffix);
        $limitLabel = $limit > 0 ? $limit.$unit : '∞';
        $stat = Stat::make($label, $used.$unit.' / '.$limitLabel)
            ->description($remaining === null
                ? trans('pelican-provisioner::strings.unlimited')
                : trans('pelican-provisioner::strings.widget.remaining', ['amount' => $remaining.$unit]));

        if ($limit <= 0 || $remaining === null) {
            return $stat->color('primary');
        }

        $ratio = $limit === 0 ? 0 : $used / $limit;

        return $stat->color($ratio >= 0.9 ? 'danger' : ($ratio >= 0.7 ? 'warning' : 'success'));
    }
}
