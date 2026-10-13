<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsTags\Pages;

use Filagora\Cms\Filament\Resources\CmsTags\CmsTagResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCmsTag extends EditRecord
{
    protected static string $resource = CmsTagResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
