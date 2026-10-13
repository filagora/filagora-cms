<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsTags\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use ForumKit\Cms\Filament\Resources\CmsTags\CmsTagResource;

class ListCmsTags extends ListRecords
{
    protected static string $resource = CmsTagResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
