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
use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Navigation\NavigationItems;
use Filagora\Cms\Navigation\NavigationLinkTypes;
use Filagora\Cms\Preview\PreviewSession;
use Filagora\Cms\Preview\PreviewShares;
use Filagora\Cms\Search\CmsPageSearchIndex;
use Filagora\Cms\Search\SearchRelations;
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
use Filagora\Core\Support\GateAbilities;
use Filagora\Entitlements\Registry\Definition;
use Filagora\Entitlements\Registry\EntitlementRegistry;
use Filagora\Search\Support\SearchIndexRegistry;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
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
            new Module('cms', 'Filagora CMS', 'filagora/filagora-cms', requires: ['core']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('cms', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        $this->registerNavigationGroup();
        $this->declareEntitlements();
        $this->defineGates();
        $this->registerPreviewMiddleware();
        $this->registerSearch();

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

    /**
     * With `filagora-search` installed, published pages become a search index and a change to a
     * live block keeps its page's document right. Without it there is nothing to set up.
     */
    private function registerSearch(): void
    {
        if (! class_exists(SearchIndexRegistry::class) || ! $this->app->bound(SearchIndexRegistry::class)) {
            return;
        }

        $this->app->make(SearchIndexRegistry::class)->register($this->app->make(CmsPageSearchIndex::class));

        foreach (['saved', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: *", function (string $name, array $payload): void {
                $model = $payload[0] ?? null;

                if ($model instanceof CmsBlock) {
                    $this->app->make(SearchRelations::class)->blockChanged($model);
                }
            });
        }
    }

    /**
     * With `filagora-entitlements` installed, the keys the abilities map to are declared so
     * `filagora:entitlements:sync` creates their definitions. Without it there is nothing to
     * declare them to.
     */
    private function declareEntitlements(): void
    {
        if (! class_exists(EntitlementRegistry::class) || ! $this->app->bound(EntitlementRegistry::class)) {
            return;
        }

        $registry = $this->app->make(EntitlementRegistry::class);

        foreach (GateAbilities::keys('filagora-cms.gates') as $entitlement) {
            $registry->define(new Definition($entitlement, wildcardChildren: false));
        }
    }

    private function defineGates(): void
    {
        GateAbilities::define('filagora-cms.gates');
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
