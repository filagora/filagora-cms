<?php

declare(strict_types=1);

namespace Filagora\Cms;

use Filagora\Cms\BlockTypes\BlockTypeRegistry;
use Filagora\Cms\BlockTypes\GenericBlockTypes;
use Filagora\Cms\Console\PublishDueCommand;
use Filagora\Cms\Contracts\PageUrlResolver;
use Filagora\Cms\Contracts\PreviewResolver;
use Filagora\Cms\Http\Middleware\MarkPreviewResponsesUncacheable;
use Filagora\Cms\Media\MediaAssets;
use Filagora\Cms\Navigation\NavigationItems;
use Filagora\Cms\Navigation\NavigationLinkTypes;
use Filagora\Cms\Preview\PreviewSession;
use Filagora\Cms\Preview\PreviewShares;
use Filagora\Cms\Services\BlockDrafts;
use Filagora\Cms\Services\CmsContent;
use Filagora\Cms\Services\MediaAssetUsage;
use Filagora\Cms\Services\NavigationDraftDiff;
use Filagora\Cms\Services\NavigationDrafts;
use Filagora\Cms\Services\PageDraftDiff;
use Filagora\Cms\Services\PageDrafts;
use Filagora\Cms\Services\PageVersioning;
use Filagora\Cms\Support\DefaultPageUrl;
use Filagora\Cms\Support\WorkflowLabels;
use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Navigation\NavigationGroupDefinition;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Entitlements\Registry\Definition;
use Filagora\Entitlements\Registry\EntitlementRegistry;
use Filagora\Entitlements\Services\AuthorizationService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-cms.php', 'filagora-cms');

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
            new Module('cms', 'Filagora CMS', 'filagora/filagora-cms', requires: ['core', 'entitlements']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('cms', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        $this->registerNavigationGroup();
        $this->declareEntitlements();
        $this->defineGates();
        $this->registerPreviewMiddleware();

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filagora-cms');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filagora-cms');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PublishDueCommand::class]);

            $this->publishes([
                __DIR__.'/../config/filagora-cms.php' => config_path('filagora-cms.php'),
            ], 'filagora-cms-config');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/filagora-cms'),
            ], 'filagora-cms-lang');
        }
    }

    private function declareEntitlements(): void
    {
        $registry = $this->app->make(EntitlementRegistry::class);

        foreach (config('filagora-cms.gates', []) as $entitlement) {
            if (is_string($entitlement) && $entitlement !== '') {
                $registry->define(new Definition($entitlement, wildcardChildren: false));
            }
        }
    }

    private function defineGates(): void
    {
        foreach (config('filagora-cms.gates', []) as $ability => $entitlement) {
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
        if (config('filagora-cms.preview.uncacheable_middleware', true)) {
            $this->app->make(Router::class)->pushMiddlewareToGroup('web', MarkPreviewResponsesUncacheable::class);
        }
    }

    private function registerNavigationGroup(): void
    {
        $this->app->make(NavigationGroupRegistry::class)->register(new NavigationGroupDefinition(
            key: 'cms',
            label: fn (): string => __('filagora-cms::messages.navigation_groups.cms'),
            icon: 'heroicon-o-document-duplicate',
            sort: 40,
        ));
    }
}
