<?php

declare(strict_types=1);

namespace ForumKit\Cms;

use ForumKit\Cms\BlockTypes\BlockTypeRegistry;
use ForumKit\Cms\BlockTypes\GenericBlockTypes;
use ForumKit\Cms\Console\PublishDueCommand;
use ForumKit\Cms\Contracts\PageUrlResolver;
use ForumKit\Cms\Contracts\PreviewResolver;
use ForumKit\Cms\Http\Middleware\MarkPreviewResponsesUncacheable;
use ForumKit\Cms\Media\MediaAssets;
use ForumKit\Cms\Navigation\NavigationItems;
use ForumKit\Cms\Navigation\NavigationLinkTypes;
use ForumKit\Cms\Preview\PreviewSession;
use ForumKit\Cms\Preview\PreviewShares;
use ForumKit\Cms\Services\BlockDrafts;
use ForumKit\Cms\Services\CmsContent;
use ForumKit\Cms\Services\MediaAssetUsage;
use ForumKit\Cms\Services\NavigationDraftDiff;
use ForumKit\Cms\Services\NavigationDrafts;
use ForumKit\Cms\Services\PageDraftDiff;
use ForumKit\Cms\Services\PageDrafts;
use ForumKit\Cms\Services\PageVersioning;
use ForumKit\Cms\Support\DefaultPageUrl;
use ForumKit\Cms\Support\WorkflowLabels;
use ForumKit\Core\Modules\Module;
use ForumKit\Core\Modules\ModuleRegistry;
use ForumKit\Core\Navigation\NavigationGroupDefinition;
use ForumKit\Core\Navigation\NavigationGroupRegistry;
use ForumKit\Core\Schema\PackageSchema;
use ForumKit\Core\Schema\SchemaRegistry;
use ForumKit\Entitlements\Registry\Definition;
use ForumKit\Entitlements\Registry\EntitlementRegistry;
use ForumKit\Entitlements\Services\AuthorizationService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/forumkit-cms.php', 'forumkit-cms');

        $this->app->scoped(PreviewSession::class);
        $this->app->bindIf(PreviewResolver::class, PreviewSession::class);
        $this->app->singleton(PreviewShares::class);
        $this->app->bindIf(PageUrlResolver::class, DefaultPageUrl::class);
        $this->app->singleton(WorkflowLabels::class);
        $this->app->singleton(MediaAssets::class);
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
        $this->app->singleton(NavigationDrafts::class);
        $this->app->singleton(NavigationDraftDiff::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('cms', 'ForumKit CMS', 'zcwilt/forumkit-cms', requires: ['core', 'entitlements']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('cms', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        $this->registerNavigationGroup();
        $this->declareEntitlements();
        $this->defineGates();
        $this->registerPreviewMiddleware();

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

    private function declareEntitlements(): void
    {
        $registry = $this->app->make(EntitlementRegistry::class);

        foreach (config('forumkit-cms.gates', []) as $entitlement) {
            if (is_string($entitlement) && $entitlement !== '') {
                $registry->define(new Definition($entitlement, wildcardChildren: false));
            }
        }
    }

    private function defineGates(): void
    {
        foreach (config('forumkit-cms.gates', []) as $ability => $entitlement) {
            if (! is_string($ability) || ! is_string($entitlement)) {
                continue;
            }

            Gate::define($ability, fn (?Authenticatable $user): bool => $this->app->make(AuthorizationService::class)->allows($user, $entitlement));
        }
    }

    /**
     * Previews must never be cached or indexed: add the middleware to the `web` group.
     */
    private function registerPreviewMiddleware(): void
    {
        if (config('forumkit-cms.preview.uncacheable_middleware', true)) {
            $this->app->make(Router::class)->pushMiddlewareToGroup('web', MarkPreviewResponsesUncacheable::class);
        }
    }

    private function registerNavigationGroup(): void
    {
        $this->app->make(NavigationGroupRegistry::class)->register(new NavigationGroupDefinition(
            key: 'cms',
            label: fn (): string => __('forumkit-cms::messages.navigation_groups.cms'),
            icon: 'heroicon-o-document-duplicate',
            sort: 40,
        ));
    }
}
