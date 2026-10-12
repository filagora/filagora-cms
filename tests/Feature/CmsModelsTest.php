<?php

declare(strict_types=1);

use ForumKit\Cms\Enums\CmsPageStatus;
use ForumKit\Cms\Models\CmsBlock;
use ForumKit\Cms\Models\CmsBlockVersion;
use ForumKit\Cms\Models\CmsMediaAsset;
use ForumKit\Cms\Models\CmsNavigation;
use ForumKit\Cms\Models\CmsNavigationDraft;
use ForumKit\Cms\Models\CmsPage;
use ForumKit\Cms\Models\CmsPageDraft;
use ForumKit\Cms\Models\CmsPageDraftVariant;
use ForumKit\Cms\Models\CmsPageVersion;
use ForumKit\Cms\Models\CmsPreviewShare;
use ForumKit\Cms\Models\CmsTag;
use ForumKit\Cms\Support\Models;
use ForumKit\Cms\Tests\Support\TestUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function page(array $attributes = []): CmsPage
{
    return CmsPage::create(array_merge(['slug' => 'p-'.uniqid(), 'title' => 'A page'], $attributes))->refresh();
}

// ------------------------------------------------------------------ helper and config

it('resolves every model through the helper, and a host can swap one', function () {
    foreach (array_keys(config('forumkit-cms.models')) as $key) {
        expect(class_exists(Models::class($key)))->toBeTrue($key);
    }

    expect(fn () => Models::class('nothing'))->toThrow(InvalidArgumentException::class);

    config(['forumkit.models.tag' => 'Host\\Tag']);
    expect(Models::class('tag'))->toBe('Host\\Tag');
});

it('uses the prefixed table names', function () {
    expect((new CmsPage)->getTable())->toBe('forumkit_cms_pages')
        ->and((new CmsPreviewShare)->getTable())->toBe('forumkit_cms_preview_shares');

    config(['forumkit.table_prefix' => '']);
    expect((new CmsPage)->getTable())->toBe('cms_pages');
});

// ------------------------------------------------------------------ pages

it('normalises a custom route to a leading slash and no trailing one, and blank to null', function (?string $given, ?string $stored) {
    expect(page(['custom_route' => $given])->custom_route)->toBe($stored);
})->with([
    'plain' => ['about', '/about'],
    'already slashed' => ['/about', '/about'],
    'trailing' => ['/about/', '/about'],
    'nested' => ['a/b/', '/a/b'],
    'padded' => ['  /x  ', '/x'],
    'blank' => ['   ', null],
    'null' => [null, null],
    'root' => ['/', '/'],
]);

it('defaults a new page to draft, and stamps published_at when it becomes published without one', function () {
    $draft = page();

    expect($draft->status)->toBe(CmsPageStatus::DRAFT)
        ->and($draft->published_at)->toBeNull();

    $draft->update(['status' => CmsPageStatus::PUBLISHED]);
    expect($draft->refresh()->published_at)->not->toBeNull();

    $kept = page(['status' => 'published', 'published_at' => now()->subYear()]);
    expect($kept->published_at->isPast())->toBeTrue()
        ->and((int) $kept->published_at->diffInDays(now()))->toBeGreaterThanOrEqual(364);
});

it('counts a page as published only when its time has arrived', function () {
    $live = page(['status' => 'published', 'published_at' => now()->subMinute()]);
    page(['status' => 'published', 'published_at' => now()->addDay()]);
    page(['status' => 'draft']);

    expect(CmsPage::published()->pluck('id')->all())->toBe([$live->id]);
});

it('loads a status written by the old site', function () {
    DB::table('forumkit_cms_pages')->insert(['slug' => 'old', 'title' => 'Old', 'status' => 'published', 'published_at' => now()->subDay()]);

    expect(CmsPage::where('slug', 'old')->sole()->status)->toBe(CmsPageStatus::PUBLISHED);
});

it('keeps slug, custom route and preview token unique', function () {
    page(['slug' => 's', 'custom_route' => '/r', 'preview_token' => 't']);

    expect(fn () => page(['slug' => 's']))->toThrow(QueryException::class)
        ->and(fn () => page(['custom_route' => '/r']))->toThrow(QueryException::class)
        ->and(fn () => page(['preview_token' => 't']))->toThrow(QueryException::class);

    page(['custom_route' => null]);
    page(['custom_route' => null]);
    expect(CmsPage::whereNull('custom_route')->count())->toBeGreaterThanOrEqual(2);
});

// ------------------------------------------------------------------ blocks

it('keeps staged copies of blocks out of live reads', function () {
    $page = page();
    $live = $page->blocks()->create(['type' => 'hero', 'name' => 'Live', 'sort_order' => 1, 'is_published' => true]);
    $page->draftBlocks()->create(['type' => 'hero', 'name' => 'Staged', 'sort_order' => 1, 'live_block_id' => $live->id]);

    expect($page->blocks()->pluck('name')->all())->toBe(['Live'])
        ->and($page->draftBlocks()->pluck('name')->all())->toBe(['Staged'])
        ->and(CmsBlock::count())->toBe(1)
        ->and(CmsBlock::withoutGlobalScopes()->count())->toBe(2)
        ->and($page->draftBlocks()->first()->is_draft)->toBeTrue();
});

it('returns blocks in sort order, and hides the staging columns when serialised', function () {
    $page = page();
    $page->blocks()->create(['type' => 'divider', 'sort_order' => 2]);
    $page->blocks()->create(['type' => 'hero', 'sort_order' => 1]);

    expect($page->blocks()->pluck('type')->all())->toBe(['hero', 'divider'])
        ->and($page->blocks()->first()->toArray())->not->toHaveKeys(['is_draft', 'live_block_id']);
});

it('loads a block type the registry has never heard of, as the plain string', function () {
    $page = page();
    $page->blocks()->create(['type' => 'some_site_only_type', 'data' => ['x' => 1]]);

    $block = $page->blocks()->sole();
    expect($block->type)->toBe('some_site_only_type')
        ->and($block->data)->toBe(['x' => 1]);
});

it('finds a staged copy from the live block, and the live block from the copy', function () {
    $live = CmsBlock::create(['type' => 'cta_band', 'slug' => 'shared', 'is_reusable' => true, 'is_published' => true]);
    $copy = CmsBlock::withoutGlobalScopes()->create(['type' => 'cta_band', 'is_draft' => true, 'live_block_id' => $live->id]);

    expect($live->draftCopy->id)->toBe($copy->id)
        ->and($copy->liveBlock->id)->toBe($live->id);
});

it('removes a page\'s blocks with the page but keeps reusable blocks, and nulls a deleted live pointer', function () {
    $page = page();
    $page->blocks()->create(['type' => 'hero']);
    $reusable = CmsBlock::create(['type' => 'hero', 'slug' => 'r', 'is_reusable' => true]);
    $copy = CmsBlock::withoutGlobalScopes()->create(['type' => 'hero', 'is_draft' => true, 'live_block_id' => $reusable->id]);

    $page->delete();
    expect(CmsBlock::count())->toBe(1);

    $reusable->delete();
    expect($copy->refresh()->live_block_id)->toBeNull();
});

it('keeps block slugs unique', function () {
    CmsBlock::create(['type' => 'hero', 'slug' => 'one']);

    expect(fn () => CmsBlock::create(['type' => 'hero', 'slug' => 'one']))->toThrow(QueryException::class);
});

// ------------------------------------------------------------------ versions, drafts, variants

it('stores page snapshots as arrays, newest version first, one number per page', function () {
    $page = page();
    $user = TestUser::make();
    foreach ([1, 2] as $n) {
        CmsPageVersion::create(['cms_page_id' => $page->id, 'version_number' => $n, 'snapshot' => ['title' => "v{$n}"], 'created_by_id' => $user->id]);
    }

    expect($page->versions->pluck('version_number')->all())->toBe([2, 1])
        ->and($page->versions->first()->snapshot)->toBe(['title' => 'v2'])
        ->and($page->versions->first()->created_at)->not->toBeNull()
        ->and($page->versions->first()->createdBy->id)->toBe($user->id)
        ->and(fn () => CmsPageVersion::create(['cms_page_id' => $page->id, 'version_number' => 2, 'snapshot' => []]))->toThrow(QueryException::class);
});

it('keeps a version when its author is deleted', function () {
    $page = page();
    $user = TestUser::make();
    $version = CmsPageVersion::create(['cms_page_id' => $page->id, 'version_number' => 1, 'snapshot' => [], 'created_by_id' => $user->id]);

    $user->delete();

    expect($version->refresh()->created_by_id)->toBeNull();
});

it('keeps block versions per block', function () {
    $block = CmsBlock::create(['type' => 'hero', 'slug' => 'b']);
    CmsBlockVersion::create(['cms_block_id' => $block->id, 'version_number' => 1, 'snapshot' => ['a' => 1]]);
    CmsBlockVersion::create(['cms_block_id' => $block->id, 'version_number' => 2, 'snapshot' => ['a' => 2]]);

    expect($block->versions->pluck('version_number')->all())->toBe([2, 1]);
});

it('holds one working draft per page with a payload, a schedule and parked variants', function () {
    $page = page();
    $draft = CmsPageDraft::create(['cms_page_id' => $page->id, 'payload' => ['page' => ['title' => 'New'], 'tag_ids' => [1]], 'is_dirty' => true, 'scheduled_publish_at' => now()->addDay()])->refresh();
    CmsPageDraftVariant::create(['cms_page_id' => $page->id, 'name' => 'B', 'snapshot' => ['page' => []]]);
    CmsPageDraftVariant::create(['cms_page_id' => $page->id, 'name' => 'A', 'snapshot' => ['page' => []]]);

    expect($draft->variant_name)->toBe('Main')
        ->and($draft->is_dirty)->toBeTrue()
        ->and($draft->payload['page']['title'])->toBe('New')
        ->and($draft->scheduled_publish_at->isFuture())->toBeTrue()
        ->and($page->draft->id)->toBe($draft->id)
        ->and($page->draftVariants->pluck('name')->all())->toBe(['A', 'B'])
        ->and(fn () => CmsPageDraft::create(['cms_page_id' => $page->id, 'payload' => []]))->toThrow(QueryException::class)
        ->and(fn () => CmsPageDraftVariant::create(['cms_page_id' => $page->id, 'name' => 'A', 'snapshot' => []]))->toThrow(QueryException::class);

    $page->delete();
    expect(CmsPageDraft::count())->toBe(0)->and(CmsPageDraftVariant::count())->toBe(0);
});

it('reads a menu draft\'s staged items', function () {
    $menu = CmsNavigation::create(['name' => 'Main', 'items' => []]);
    $draft = CmsNavigationDraft::create(['cms_navigation_id' => $menu->id, 'payload' => ['items' => [3 => ['label' => 'A'], 7 => ['label' => 'B']]]]);

    expect($draft->items())->toBe([['label' => 'A'], ['label' => 'B']])
        ->and(CmsNavigationDraft::create(['cms_navigation_id' => CmsNavigation::create(['name' => 'Other'])->id, 'payload' => []])->items())->toBe([]);
});

// ------------------------------------------------------------------ navigations

it('clears a menu\'s cached items when it is saved, renamed or deleted', function () {
    $menu = CmsNavigation::create(['name' => 'Footer', 'items' => [['label' => 'A']]]);
    $key = CmsNavigation::CACHE_PREFIX.'Footer';

    Cache::put($key, 'stale');
    $menu->update(['items' => [['label' => 'B']]]);
    expect(Cache::has($key))->toBeFalse();

    Cache::put($key, 'stale');
    Cache::put(CmsNavigation::CACHE_PREFIX.'Bottom', 'stale');
    $menu->update(['name' => 'Bottom']);
    expect(Cache::has($key))->toBeFalse()->and(Cache::has(CmsNavigation::CACHE_PREFIX.'Bottom'))->toBeFalse();

    Cache::put(CmsNavigation::CACHE_PREFIX.'Bottom', 'stale');
    $menu->delete();
    expect(Cache::has(CmsNavigation::CACHE_PREFIX.'Bottom'))->toBeFalse();
});

it('keeps menu names unique and stores items as an array', function () {
    $menu = CmsNavigation::create(['name' => 'Main', 'items' => [['label' => 'Home', 'url' => '/']]]);

    expect($menu->refresh()->items)->toBe([['label' => 'Home', 'url' => '/']])
        ->and(fn () => CmsNavigation::create(['name' => 'Main']))->toThrow(QueryException::class);
});

// ------------------------------------------------------------------ tags, media, shares

it('relates tags and pages both ways, and clears the pivot with either side', function () {
    $page = page();
    $tag = CmsTag::create(['name' => 'News', 'slug' => 'news']);
    $page->tags()->attach($tag);

    expect($page->tags->pluck('slug')->all())->toBe(['news'])
        ->and($tag->pages->pluck('id')->all())->toBe([$page->id]);

    $tag->delete();
    expect($page->refresh()->tags)->toHaveCount(0);
});

it('describes a media asset: image check, size, dimensions and url only while the file exists', function () {
    Storage::fake('public');
    Storage::disk('public')->put('cms/a.png', 'x');
    $asset = CmsMediaAsset::create(['path' => 'cms/a.png', 'filename' => 'a.png', 'mime_type' => 'image/png', 'size' => 1, 'meta' => ['extension' => 'png', 'width' => 40, 'height' => 20]])->refresh();

    expect($asset->disk)->toBe('public')
        ->and($asset->isImage())->toBeTrue()
        ->and($asset->extension())->toBe('png')
        ->and($asset->dimensionsLabel())->toBe('40 x 20')
        ->and($asset->publicUrl())->toContain('cms/a.png');

    Storage::disk('public')->delete('cms/a.png');
    expect($asset->publicUrl())->toBeNull();

    $plain = CmsMediaAsset::create(['path' => 'cms/b.txt', 'filename' => 'b.txt', 'mime_type' => 'text/plain']);
    expect($plain->isImage())->toBeFalse()->and($plain->dimensionsLabel())->toBeNull()->and($plain->extension())->toBeNull();
    expect(fn () => CmsMediaAsset::create(['path' => 'cms/b.txt', 'filename' => 'b']))->toThrow(QueryException::class);
});

it('knows when a preview share can still be used', function () {
    $live = CmsPreviewShare::create(['token' => 't1', 'items' => ['navigations' => ['Main' => 'A'], 'pages' => [4 => null]], 'expires_at' => now()->addDay()])->refresh();
    $expired = CmsPreviewShare::create(['token' => 't2', 'items' => [], 'expires_at' => now()->subMinute()]);
    $revoked = CmsPreviewShare::create(['token' => 't3', 'items' => [], 'expires_at' => now()->addDay(), 'revoked_at' => now()]);

    expect($live->isUsable())->toBeTrue()
        ->and($expired->isUsable())->toBeFalse()
        ->and($revoked->isUsable())->toBeFalse()
        ->and(CmsPreviewShare::usable()->pluck('id')->all())->toBe([$live->id])
        ->and($live->navigations())->toBe(['Main' => 'A'])
        ->and($live->pages())->toBe([4 => null])
        ->and($live->open_count)->toBe(0)
        ->and($live->toArray())->not->toHaveKey('token');
});
