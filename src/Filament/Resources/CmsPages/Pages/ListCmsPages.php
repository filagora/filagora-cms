<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsPages\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use ForumKit\Cms\Filament\Resources\CmsPages\CmsPageResource;

class ListCmsPages extends ListRecords
{
    protected static string $resource = CmsPageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
