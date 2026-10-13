<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsPages\Pages;

use Filagora\Cms\Filament\Resources\CmsPages\CmsPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCmsPages extends ListRecords
{
    protected static string $resource = CmsPageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
