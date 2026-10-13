<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsNavigations\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use ForumKit\Cms\Filament\Resources\CmsNavigations\CmsNavigationResource;

class ListCmsNavigations extends ListRecords
{
    protected static string $resource = CmsNavigationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
