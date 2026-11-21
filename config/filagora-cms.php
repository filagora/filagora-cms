<?php

declare(strict_types=1);

use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Models\CmsBlockVersion;
use Filagora\Cms\Models\CmsMediaAsset;
use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Models\CmsNavigationDraft;
use Filagora\Cms\Models\CmsNavigationDraftVariant;
use Filagora\Cms\Models\CmsNavigationVersion;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Models\CmsPageDraft;
use Filagora\Cms\Models\CmsPageDraftVariant;
use Filagora\Cms\Models\CmsPageVersion;
use Filagora\Cms\Models\CmsPreviewShare;
use Filagora\Cms\Models\CmsTag;

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
    'navigation_cache_seconds' => (int) env('FILAGORA_CMS_NAVIGATION_CACHE_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | The classes this package uses for its tables. Point a key at your own subclass to
    | replace one (here, or under `filagora.models.<key>`, which wins). Subclass the package
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
        'filagora.cms.manage' => 'filagora.cms.manage',
        'filagora.cms.preview' => 'filagora.cms.preview',
    ],

    /*
    |--------------------------------------------------------------------------
    | Previews
    |--------------------------------------------------------------------------
    |
    | A preview is carried by the address: `/page?preview=TOKEN` (the token of a preview link)
    | shows the drafts that link names, and the same address without it is the live site.
    | `query_parameter`: its name. `uncacheable_middleware`: add the middleware that marks every
    | response to a request with a token uncacheable, noindex and referrer-free to the `web` group.
    | `carry_token`: add the middleware that keeps the token on links to other pages of the site
    | and on redirects, so a draft menu can be followed from page to page; `carry_token_except`:
    | path prefixes whose links are left alone (the staff area, sign-out). `share_route`: the name
    | of the route you define to open a preview link (it takes a `token`).
    |
    */
    'preview' => [
        'uncacheable_middleware' => true,
        'share_route' => 'cms.preview.share.open',
        'query_parameter' => 'preview',
        'carry_token' => true,
        'carry_token_except' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Used when `filagora/filagora-search` is installed. `content_limit`: the most characters of a
    | page's block text copied into its search document.
    |
    */
    'search' => [
        'content_limit' => 50000,
    ],

];
