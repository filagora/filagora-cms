<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsMediaAssets\Pages;

use Filagora\Cms\Filament\Resources\CmsMediaAssets\CmsMediaAssetResource;
use Filagora\Cms\Media\MediaAssets;
use Filament\Resources\Pages\CreateRecord;

class CreateCmsMediaAsset extends CreateRecord
{
    protected static string $resource = CmsMediaAssetResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(MediaAssets::class)->enrich($data, auth()->id());
    }
}
