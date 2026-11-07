<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\Components\Actions;

use App\Models\User;
use Filament\Actions\Action;
use PelicanProvisioner\Filament\App\Pages\CreateServerPage;
use PelicanProvisioner\Services\QuotaAccess;

class CreateServerAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'create_server';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => trans('pelican-provisioner::strings.create_server'));
        $this->icon('tabler-cube-plus');
        $this->button();
        $this->visible(fn (): bool => $this->access()?->canOpen($this->actorId()) ?? false);
        $this->disabled(function (): bool {
            $access = $this->access();
            $actorId = $this->actorId();

            return $access === null || !$access->canChooseAnEgg($actorId) || !$access->canSpendMinimum($actorId);
        });
        $this->tooltip(function (): ?string {
            $access = $this->access();
            $actorId = $this->actorId();

            if ($access === null || !$access->canOpen($actorId)) {
                return null;
            }

            if (!$access->canChooseAnEgg($actorId)) {
                return trans('pelican-provisioner::strings.no_eggs');
            }

            return $access->canSpendMinimum($actorId) ? null : trans('pelican-provisioner::strings.quota_full');
        });
        $this->url(fn (): string => CreateServerPage::getUrl());
    }

    private function actorId(): int
    {
        $actor = user();

        return $actor instanceof User ? (int) $actor->id : 0;
    }

    private function access(): ?QuotaAccess
    {
        if ($this->actorId() === 0) {
            return null;
        }

        return app(QuotaAccess::class);
    }
}
