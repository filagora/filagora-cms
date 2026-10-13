<?php

declare(strict_types=1);

use ForumKit\Cms\Contracts\PreviewResolver;
use ForumKit\Cms\Models\CmsBlock;
use ForumKit\Cms\Models\CmsMediaAsset;
use ForumKit\Cms\Models\CmsNavigation;
use ForumKit\Cms\Models\CmsPage;
use ForumKit\Cms\Models\CmsTag;
use ForumKit\Cms\Preview\PreviewSession;
use ForumKit\Cms\Services\CmsContent;
use ForumKit\Cms\Services\MediaAssetUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function content(): CmsContent
{
    app()->forgetInstance(CmsContent::class);

    return app(CmsContent::class);
}

function livePage(array $attributes = []): CmsPage
{
    return CmsPage::create(array_merge(['slug' => 'p-'.uniqid(), 'title' => 'T', 'status' => 'published', 'published_at' => now()->subMinute()], $attributes))->refresh();
}

it('binds the preview session as the resolver by default', function () {
    expect(app(PreviewResolver::class))->toBeInstanceOf(PreviewSession::class)->and(app(PreviewResolver::class)->pageForSlug('x'))->toBeNull()->and(app(PreviewResolver::class)->draftItemsFor('Main'))->toBeNull();
});

// ------------------------------------------------------------------ pages

it('lists published pages newest first, with their tags, optionally by tag', function () {
    $old = livePage(['slug' => 'old', 'published_at' => now()->subDays(3)]);
    $new = livePage(['slug' => 'new', 'published_at' => now()->subDay()]);
    livePage(['slug' => 'later', 'published_at' => now()->addDay()]);
    CmsPage::create(['slug' => 'draft', 'title' => 'D']);
    $tag = CmsTag::create(['name' => 'News', 'slug' => 'news']);
    $old->tags()->attach($tag);

    $all = content()->publishedPages();

    expect($all->pluck('slug')->all())->toBe(['new', 'old'])
        ->and($all->last()->relationLoaded('tags'))->toBeTrue()
        ->and(content()->publishedPages('news')->pluck('slug')->all())->toBe(['old'])
        ->and(content()->publishedPages('missing'))->toHaveCount(0);
});

it('reads a published page by slug with its live blocks in order', function () {
    $page = livePage(['slug' => 'about']);
    $page->blocks()->create(['type' => 'divider', 'sort_order' => 2]);
    $page->blocks()->create(['type' => 'hero', 'sort_order' => 1]);
    $page->draftBlocks()->create(['type' => 'image', 'sort_order' => 0]);

    $read = content()->publishedPageBySlug('about');

    expect($read->id)->toBe($page->id)
        ->and($read->blocks->pluck('type')->all())->toBe(['hero', 'divider']);
});

it('does not serve a draft, a future page or a missing one by slug', function () {
    CmsPage::create(['slug' => 'draft', 'title' => 'D']);
    livePage(['slug' => 'soon', 'published_at' => now()->addHour()]);
    livePage(['slug' => 'unpublished', 'status' => 'draft']);
    DB::table('forumkit_cms_pages')->where('slug', 'unpublished')->update(['published_at' => now()->subDay()]);

    foreach (['draft', 'soon', 'unpublished', 'missing'] as $slug) {
        expect(content()->publishedPageBySlug($slug))->toBeNull($slug);
    }
});

it('serves a draft page only to its preview token, and not to a wrong one', function () {
    CmsPage::create(['slug' => 'secret', 'title' => 'D', 'preview_token' => 'tok123']);

    expect(content()->publishedPageBySlug('secret', 'tok123')?->slug)->toBe('secret')
        ->and(content()->publishedPageBySlug('secret', 'wrong'))->toBeNull()
        ->and(content()->publishedPageBySlug('secret'))->toBeNull()
        ->and(content()->publishedPageBySlug('other', 'tok123'))->toBeNull();
});

it('reads a page by custom route with the same rules', function () {
    livePage(['slug' => 'home', 'custom_route' => '/']);
    CmsPage::create(['slug' => 'beta', 'title' => 'B', 'custom_route' => 'beta', 'preview_token' => 'bt']);

    expect(content()->publishedPageByCustomRoute('/')?->slug)->toBe('home')
        ->and(content()->publishedPageByCustomRoute('/beta'))->toBeNull()
        ->and(content()->publishedPageByCustomRoute('/beta', 'bt')?->slug)->toBe('beta');
});

it('serves the previewed page instead when a preview is in effect', function () {
    $previewed = new CmsPage(['slug' => 'about', 'title' => 'Staged title']);
    app()->instance(PreviewResolver::class, new class($previewed) implements PreviewResolver
    {
        public function __construct(private CmsPage $page) {}

        public function pageForSlug(string $slug): ?CmsPage
        {
            return $slug === 'about' ? $this->page : null;
        }

        public function pageForCustomRoute(string $customRoute): ?CmsPage
        {
            return $customRoute === '/staged' ? $this->page : null;
        }

        public function draftItemsFor(string $name): ?array
        {
            return null;
        }
    });
    livePage(['slug' => 'about', 'title' => 'Live title']);

    expect(content()->publishedPageBySlug('about')->title)->toBe('Staged title')
        ->and(content()->publishedPageByCustomRoute('/staged')->title)->toBe('Staged title')
        ->and(content()->publishedPageBySlug('other'))->toBeNull();
});

// ------------------------------------------------------------------ navigations

it('reads a menu, normalised for the visitor, and caches it under the documented key', function () {
    CmsNavigation::create(['name' => 'Main', 'items' => [['label' => 'Home', 'url' => '/'], ['label' => 'Gone', 'url' => '#']]]);

    $items = content()->navigationItems('Main');

    expect(array_column($items, 'label'))->toBe(['Home'])
        ->and(Cache::has('cms:navigation:Main'))->toBeTrue()
        ->and(content()->navigation('Missing'))->toBeNull()
        ->and(content()->navigationItems('Missing'))->toBe([]);
});

it('serves a cached menu without touching the database, until the menu is saved', function () {
    $menu = CmsNavigation::create(['name' => 'Main', 'items' => [['label' => 'One', 'url' => '/1']]]);
    content()->navigation('Main');

    DB::table('forumkit_cms_navigations')->where('id', $menu->id)->update(['items' => json_encode([['label' => 'Raw', 'url' => '/raw']])]);
    expect(array_column(content()->navigationItems('Main'), 'label'))->toBe(['One']);

    $menu->update(['items' => [['label' => 'Two', 'url' => '/2']]]);
    expect(array_column(content()->navigationItems('Main'), 'label'))->toBe(['Two']);
});

it('remembers a missing menu for the request only as null, and finds it once created', function () {
    expect(content()->navigation('Later'))->toBeNull();

    CmsNavigation::create(['name' => 'Later', 'items' => [['label' => 'A', 'url' => '/a']]]);
    Cache::forget('cms:navigation:Later');

    expect(content()->navigationItems('Later'))->toHaveCount(1);
});

it('shows the previewed items of a menu being previewed, never cached', function () {
    CmsNavigation::create(['name' => 'Main', 'items' => [['label' => 'Live', 'url' => '/live']]]);
    app()->instance(PreviewResolver::class, new class implements PreviewResolver
    {
        public function pageForSlug(string $slug): ?CmsPage
        {
            return null;
        }

        public function pageForCustomRoute(string $customRoute): ?CmsPage
        {
            return null;
        }

        public function draftItemsFor(string $name): ?array
        {
            return $name === 'Main' ? [['label' => 'Staged', 'url' => '/staged']] : null;
        }
    });

    expect(array_column(content()->navigationItems('Main'), 'label'))->toBe(['Staged'])
        ->and(Cache::has('cms:navigation:Main'))->toBeFalse();
});

it('follows the configured cache lifetime', function () {
    config(['forumkit-cms.navigation_cache_seconds' => 60]);
    CmsNavigation::create(['name' => 'Short', 'items' => []]);

    content()->navigation('Short');

    expect(Cache::has('cms:navigation:Short'))->toBeTrue();
});

// ------------------------------------------------------------------ blocks and media

it('reads a reusable block by slug only while it is published', function () {
    CmsBlock::create(['type' => 'cta_band', 'slug' => 'cta', 'is_reusable' => true, 'is_published' => true]);
    CmsBlock::create(['type' => 'cta_band', 'slug' => 'unpub', 'is_reusable' => true, 'is_published' => false]);
    CmsBlock::create(['type' => 'cta_band', 'slug' => 'onpage', 'is_reusable' => false, 'is_published' => true]);
    CmsBlock::withoutGlobalScopes()->create(['type' => 'cta_band', 'slug' => 'staged', 'is_reusable' => true, 'is_published' => true, 'is_draft' => true]);

    expect(content()->publishedReusableBlock('cta')?->slug)->toBe('cta');

    foreach (['unpub', 'onpage', 'staged', 'nope'] as $slug) {
        expect(content()->publishedReusableBlock($slug))->toBeNull($slug);
    }
});

it('looks media up by path in one query, ignoring blanks, and gives urls only for files that exist', function () {
    Storage::fake('public');
    Storage::disk('public')->put('cms/a.png', 'x');
    CmsMediaAsset::create(['path' => 'cms/a.png', 'filename' => 'a.png']);
    CmsMediaAsset::create(['path' => 'cms/gone.png', 'filename' => 'gone.png']);
    $service = content();

    DB::enableQueryLog();
    $found = $service->mediaAssets([' cms/a.png ', 'cms/gone.png', 'cms/none.png', '', '  ']);
    $queries = count(DB::getQueryLog());
    $again = $service->mediaAssets(['cms/a.png', 'cms/none.png']);

    expect(array_keys($found))->toBe(['cms/a.png', 'cms/gone.png'])
        ->and($queries)->toBe(1)
        ->and(count(DB::getQueryLog()))->toBe($queries)
        ->and(array_keys($again))->toBe(['cms/a.png'])
        ->and($service->mediaAsset('cms/a.png')?->filename)->toBe('a.png')
        ->and($service->mediaAsset('  '))->toBeNull()
        ->and($service->mediaAsset('cms/none.png'))->toBeNull()
        ->and(array_keys($service->mediaUrls(['cms/a.png', 'cms/gone.png'])))->toBe(['cms/a.png'])
        ->and($service->mediaAssets([]))->toBe([]);
});

// ------------------------------------------------------------------ media usage

it('finds the live blocks that use an asset, with where they sit', function () {
    $asset = CmsMediaAsset::create(['path' => 'cms/u.png', 'filename' => 'u.png']);
    $other = CmsMediaAsset::create(['path' => 'cms/o.png', 'filename' => 'o.png']);
    $page = livePage(['slug' => 'usage', 'title' => 'Usage page']);
    $page->blocks()->create(['type' => 'image', 'name' => 'Pic', 'data' => ['asset_id' => $asset->id]]);
    $page->blocks()->create(['type' => 'gallery', 'data' => ['items' => [['asset_id' => $other->id], ['asset_id' => $asset->id]]]]);
    $page->blocks()->create(['type' => 'divider', 'data' => ['asset_id' => $asset->id]]);
    $page->draftBlocks()->create(['type' => 'image', 'data' => ['asset_id' => $asset->id]]);
    CmsBlock::create(['type' => 'hero', 'slug' => 'shared', 'is_reusable' => true, 'data' => ['background_image_asset_id' => $asset->id]]);
    $usage = app(MediaAssetUsage::class);

    $entries = $usage->forAsset($asset);

    expect($entries)->toHaveCount(3)
        ->and(collect($entries)->pluck('block_type')->sort()->values()->all())->toBe(['gallery', 'hero', 'image'])
        ->and(collect($entries)->firstWhere('block_type', 'image'))->toMatchArray(['block_name' => 'Pic', 'page_slug' => 'usage', 'page_title' => 'Usage page', 'is_reusable' => false])
        ->and(collect($entries)->firstWhere('block_type', 'hero'))->toMatchArray(['block_slug' => 'shared', 'page_id' => null, 'is_reusable' => true])
        ->and($usage->countForAsset($asset->id))->toBe(3)
        ->and($usage->countForAsset($other))->toBe(1)
        ->and($usage->forAsset(0))->toBe([]);
});
