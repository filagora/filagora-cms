<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsNavigations\Pages;

use Filagora\Cms\Filament\Resources\CmsNavigations\CmsNavigationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCmsNavigations extends ListRecords
{
    protected static string $resource = CmsNavigationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
