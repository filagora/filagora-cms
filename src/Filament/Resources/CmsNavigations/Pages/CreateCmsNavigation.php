<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsNavigations\Pages;

use Filagora\Cms\Filament\Resources\CmsNavigations\CmsNavigationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCmsNavigation extends CreateRecord
{
    protected static string $resource = CmsNavigationResource::class;
}
