<?php

declare(strict_types=1);

use ForumKit\Cms\Models\CmsBlock;
use ForumKit\Cms\Models\CmsBlockVersion;
use ForumKit\Cms\Models\CmsMediaAsset;
use ForumKit\Cms\Models\CmsNavigation;
use ForumKit\Cms\Models\CmsNavigationDraft;
use ForumKit\Cms\Models\CmsNavigationDraftVariant;
use ForumKit\Cms\Models\CmsNavigationVersion;
use ForumKit\Cms\Models\CmsPage;
use ForumKit\Cms\Models\CmsPageDraft;
use ForumKit\Cms\Models\CmsPageDraftVariant;
use ForumKit\Cms\Models\CmsPageVersion;
use ForumKit\Cms\Models\CmsPreviewShare;
use ForumKit\Cms\Models\CmsTag;

return [

    /*
    |--------------------------------------------------------------------------
    | Navigation cache
    |--------------------------------------------------------------------------
    |
    | Seconds a menu's items are cached (key `cms:navigation:{name}`). Saving or deleting a
    | menu clears the cache at once, so this is only the upper bound for outside changes.
    |
    */
    'navigation_cache_seconds' => (int) env('FORUMKIT_CMS_NAVIGATION_CACHE_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | The classes this package uses for its tables. Point a key at your own subclass to
    | replace one (here, or under `forumkit.models.<key>`, which wins). Subclass the package
    | model; the package always asks for the class through its Models helper, so relations
    | and queries follow your class.
    |
    */
    'models' => [
        'page' => CmsPage::class,
        'block' => CmsBlock::class,
        'block_version' => CmsBlockVersion::class,
        'page_version' => CmsPageVersion::class,
        'page_draft' => CmsPageDraft::class,
        'page_draft_variant' => CmsPageDraftVariant::class,
        'navigation' => CmsNavigation::class,
        'navigation_version' => CmsNavigationVersion::class,
        'navigation_draft' => CmsNavigationDraft::class,
        'navigation_draft_variant' => CmsNavigationDraftVariant::class,
        'tag' => CmsTag::class,
        'media_asset' => CmsMediaAsset::class,
        'preview_share' => CmsPreviewShare::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gate abilities
    |--------------------------------------------------------------------------
    |
    | Maps each CMS ability to the entitlement that allows it. Point an ability at a different
    | entitlement to use your own keys.
    |
    */
    'gates' => [
        'forumkit.cms.manage' => 'forumkit.cms.manage',
        'forumkit.cms.preview' => 'forumkit.cms.preview',
    ],

    /*
    |--------------------------------------------------------------------------
    | Previews
    |--------------------------------------------------------------------------
    |
    | `uncacheable_middleware`: add the middleware that marks every response uncacheable and
    | noindex while a preview is in effect to the `web` group. `share_route`: the name of the
    | route you define to open a shared preview link (it takes a `token`).
    |
    */
    'preview' => [
        'uncacheable_middleware' => true,
        'share_route' => 'cms.preview.share.open',
    ],

];
