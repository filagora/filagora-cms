<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsMediaAssets\Pages;

use Filament\Resources\Pages\CreateRecord;
use ForumKit\Cms\Filament\Resources\CmsMediaAssets\CmsMediaAssetResource;
use ForumKit\Cms\Media\MediaAssets;

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
