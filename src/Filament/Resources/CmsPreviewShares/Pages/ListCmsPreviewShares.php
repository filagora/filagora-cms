<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsPreviewShares\Pages;

use Filament\Resources\Pages\ListRecords;
use ForumKit\Cms\Filament\Resources\CmsPreviewShares\CmsPreviewShareResource;

class ListCmsPreviewShares extends ListRecords
{
    protected static string $resource = CmsPreviewShareResource::class;
}
