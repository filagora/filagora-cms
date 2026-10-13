<?php

declare(strict_types=1);

namespace Filagora\Cms;

use Filagora\Cms\Filament\Resources\CmsBlocks\CmsBlockResource;
use Filagora\Cms\Filament\Resources\CmsMediaAssets\CmsMediaAssetResource;
use Filagora\Cms\Filament\Resources\CmsNavigations\CmsNavigationResource;
use Filagora\Cms\Filament\Resources\CmsPages\CmsPageResource;
use Filagora\Cms\Filament\Resources\CmsPreviewShares\CmsPreviewShareResource;
use Filagora\Cms\Filament\Resources\CmsTags\CmsTagResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class FilagoraCmsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filagora-cms';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            CmsPageResource::class,
            CmsBlockResource::class,
            CmsNavigationResource::class,
            CmsTagResource::class,
            CmsMediaAssetResource::class,
            CmsPreviewShareResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
