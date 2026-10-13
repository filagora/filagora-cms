<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsTags\Pages;

use Filagora\Cms\Filament\Resources\CmsTags\CmsTagResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCmsTag extends CreateRecord
{
    protected static string $resource = CmsTagResource::class;
}
