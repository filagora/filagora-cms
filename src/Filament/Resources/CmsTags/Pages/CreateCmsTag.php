<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsTags\Pages;

use Filament\Resources\Pages\CreateRecord;
use ForumKit\Cms\Filament\Resources\CmsTags\CmsTagResource;

class CreateCmsTag extends CreateRecord
{
    protected static string $resource = CmsTagResource::class;
}
