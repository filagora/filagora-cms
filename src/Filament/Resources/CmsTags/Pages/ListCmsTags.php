<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsTags\Pages;

use Filagora\Cms\Filament\Resources\CmsTags\CmsTagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCmsTags extends ListRecords
{
    protected static string $resource = CmsTagResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
