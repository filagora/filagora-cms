<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsNavigations\Pages;

use Filament\Resources\Pages\CreateRecord;
use ForumKit\Cms\Filament\Resources\CmsNavigations\CmsNavigationResource;

class CreateCmsNavigation extends CreateRecord
{
    protected static string $resource = CmsNavigationResource::class;
}
