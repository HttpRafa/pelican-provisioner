<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\Admin\Resources\ResourceQuotas\Pages;

use Filament\Resources\Pages\ManageRecords;
use PelicanProvisioner\Filament\Admin\Resources\ResourceQuotas\ResourceQuotaResource;

class ManageResourceQuotas extends ManageRecords
{
    protected static string $resource = ResourceQuotaResource::class;
}
