<?php

declare(strict_types=1);

namespace ForumKit\Cms;

use Filament\Contracts\Plugin;
use Filament\Panel;
use ForumKit\Cms\Filament\Resources\CmsMediaAssets\CmsMediaAssetResource;
use ForumKit\Cms\Filament\Resources\CmsNavigations\CmsNavigationResource;
use ForumKit\Cms\Filament\Resources\CmsPages\CmsPageResource;
use ForumKit\Cms\Filament\Resources\CmsPreviewShares\CmsPreviewShareResource;
use ForumKit\Cms\Filament\Resources\CmsTags\CmsTagResource;

class ForumKitCmsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'forumkit-cms';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            CmsPageResource::class,
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
