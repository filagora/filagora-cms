<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsBlocks\Pages;

use Filagora\Cms\Filament\Resources\CmsBlocks\CmsBlockResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCmsBlocks extends ListRecords
{
    protected static string $resource = CmsBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
