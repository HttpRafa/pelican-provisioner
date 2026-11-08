<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\Admin\Resources\QuotaBans\Pages;

use Filament\Resources\Pages\ManageRecords;
use PelicanProvisioner\Filament\Admin\Resources\QuotaBans\QuotaBanResource;

class ManageQuotaBans extends ManageRecords
{
    protected static string $resource = QuotaBanResource::class;
}
