<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsPreviewShares\Pages;

use Filagora\Cms\Filament\Resources\CmsPreviewShares\CmsPreviewShareResource;
use Filament\Resources\Pages\ListRecords;

class ListCmsPreviewShares extends ListRecords
{
    protected static string $resource = CmsPreviewShareResource::class;
}
