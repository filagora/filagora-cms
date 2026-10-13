<?php

declare(strict_types=1);

namespace ForumKit\Cms;

use ForumKit\Cms\BlockTypes\BlockTypeRegistry;
use ForumKit\Cms\BlockTypes\GenericBlockTypes;
use ForumKit\Cms\Console\PublishDueCommand;
use ForumKit\Cms\Contracts\PreviewResolver;
use ForumKit\Cms\Navigation\NavigationItems;
use ForumKit\Cms\Navigation\NavigationLinkTypes;
use ForumKit\Cms\Services\BlockDrafts;
use ForumKit\Cms\Services\CmsContent;
use ForumKit\Cms\Services\MediaAssetUsage;
use ForumKit\Cms\Services\PageDraftDiff;
use ForumKit\Cms\Services\PageDrafts;
use ForumKit\Cms\Services\PageVersioning;
use ForumKit\Cms\Support\NoPreview;
use ForumKit\Core\Modules\Module;
use ForumKit\Core\Modules\ModuleRegistry;
use ForumKit\Core\Schema\PackageSchema;
use ForumKit\Core\Schema\SchemaRegistry;
use Illuminate\Support\ServiceProvider;

class CmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/forumkit-cms.php', 'forumkit-cms');

        $this->app->bindIf(PreviewResolver::class, NoPreview::class);
        $this->app->singleton(BlockTypeRegistry::class, function (): BlockTypeRegistry {
            $registry = new BlockTypeRegistry;

            foreach (GenericBlockTypes::all() as $type) {
                $registry->register($type);
            }

            return $registry;
        });
        $this->app->singleton(NavigationLinkTypes::class);
        $this->app->singleton(NavigationItems::class);
        $this->app->singleton(MediaAssetUsage::class);
        $this->app->scoped(CmsContent::class);
        $this->app->singleton(PageVersioning::class);
        $this->app->singleton(PageDrafts::class);
        $this->app->singleton(PageDraftDiff::class);
        $this->app->singleton(BlockDrafts::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('cms', 'ForumKit CMS', 'zcwilt/forumkit-cms', requires: ['core', 'entitlements']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('cms', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'forumkit-cms');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'forumkit-cms');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PublishDueCommand::class]);

            $this->publishes([
                __DIR__.'/../config/forumkit-cms.php' => config_path('forumkit-cms.php'),
            ], 'forumkit-cms-config');
        }
    }
}
