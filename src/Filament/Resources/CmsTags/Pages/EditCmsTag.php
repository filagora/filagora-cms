<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsTags\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use ForumKit\Cms\Filament\Resources\CmsTags\CmsTagResource;

class EditCmsTag extends EditRecord
{
    protected static string $resource = CmsTagResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
