<?php

declare(strict_types=1);

use Filagora\Cms\Contracts\PageUrlResolver;
use Filagora\Cms\Media\MediaAssets;
use Filagora\Cms\Models\CmsMediaAsset;
use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Models\CmsPreviewShare;
use Filagora\Cms\Navigation\NavigationLinkTypes;
use Filagora\Cms\Services\NavigationDrafts;
use Filagora\Cms\Services\PageDrafts;
use Filagora\Cms\Support\DefaultPageUrl;
use Filagora\Cms\Support\WorkflowLabels;
use Filagora\Cms\Tests\Support\TestUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// ------------------------------------------------------------------ page url

it('serves a page at its custom route, or at /pages/{slug}', function () {
    $resolver = app(PageUrlResolver::class);

    expect($resolver)->toBeInstanceOf(DefaultPageUrl::class)
        ->and($resolver->urlFor(new CmsPage(['slug' => 'about', 'title' => 'A'])))->toBe(url('/pages/about'))
        ->and($resolver->urlFor(new CmsPage(['slug' => ' about ', 'title' => 'A'])))->toBe(url('/pages/about'))
        ->and($resolver->urlFor(new CmsPage(['slug' => 'about', 'custom_route' => '/privacy', 'title' => 'A'])))->toBe(url('/privacy'));
});

// ------------------------------------------------------------------ workflow labels

it('describes a page in words and as a badge, from its draft', function () {
    $labels = app(WorkflowLabels::class);
    $live = CmsPage::create(['slug' => 'l', 'title' => 'L', 'status' => 'published', 'published_at' => now()->subDay()])->refresh();
    $draft = CmsPage::create(['slug' => 'd', 'title' => 'D'])->refresh();

    expect($labels->describePage($live))->toBe('Published')
        ->and($labels->pageBadge($live))->toBe(['label' => 'Published', 'color' => 'success'])
        ->and($labels->describePage($draft))->toBe('Draft (not public)')
        ->and($labels->pageBadge($draft))->toBe(['label' => 'Draft', 'color' => 'gray']);

    app(PageDrafts::class)->saveDraftFields($live, ['title' => 'x']);
    expect($labels->describePage($live->refresh()))->toBe('Published · Unpublished changes')
        ->and($labels->pageBadge($live->refresh()->load('draft')))->toBe(['label' => 'Unpublished changes', 'color' => 'warning']);

    app(PageDrafts::class)->schedule($live, now()->setDate(2030, 5, 6)->setTime(9, 30));
    expect($labels->describePage($live->refresh()))->toBe('Published · Changes scheduled for 6 May 2030 09:30')
        ->and($labels->pageBadge($live->refresh()->load('draft'))['label'])->toBe('Scheduled');
});

it('describes a menu the same way', function () {
    $labels = app(WorkflowLabels::class);
    $menu = CmsNavigation::create(['name' => 'Main', 'items' => []])->refresh();

    expect($labels->describeNavigation($menu))->toBe('Live')
        ->and($labels->navigationBadge($menu))->toBe(['label' => 'Published', 'color' => 'success']);

    app(NavigationDrafts::class)->saveDraft($menu, [['label' => 'x', 'url' => '/x']]);
    expect($labels->describeNavigation($menu->refresh()))->toBe('Live · Unpublished changes')
        ->and($labels->navigationBadge($menu->refresh()->load('draft'))['color'])->toBe('warning');

    app(NavigationDrafts::class)->schedule($menu, now()->setDate(2030, 1, 2)->setTime(8, 0));
    expect($labels->describeNavigation($menu->refresh()))->toBe('Live · Changes scheduled for 2 Jan 2030 08:00');
});

// ------------------------------------------------------------------ link kinds for the editor

it('offers URL first, then the kinds a site registered, with their reference choices', function () {
    $kinds = new NavigationLinkTypes;
    $kinds->register('app_page', fn (string $r): ?string => '/'.$r, label: 'App page', references: ['profile' => 'Profile']);
    $kinds->register('legal_page', fn (string $r): ?string => '/legal/'.$r);

    expect($kinds->options())->toBe(['url' => 'URL', 'app_page' => 'App page', 'legal_page' => 'Legal Page'])
        ->and($kinds->references('app_page'))->toBe(['profile' => 'Profile'])
        ->and($kinds->references('legal_page'))->toBe([])
        ->and($kinds->references('nope'))->toBe([]);
});

// ------------------------------------------------------------------ preview link url

it('gives a share its address only when the host has defined the route', function () {
    $share = CmsPreviewShare::create(['token' => 'tok-123', 'items' => [], 'expires_at' => now()->addDay()]);

    expect($share->url())->toBeNull();

    Route::get('/preview/{token}', fn () => 'ok')->name('cms.preview.share.open');
    app('router')->getRoutes()->refreshNameLookups();

    expect($share->url())->toBe(url('/preview/tok-123'));

    config(['filagora-cms.preview.share_route' => 'somewhere.else']);
    expect($share->url())->toBeNull();
});

// ------------------------------------------------------------------ media files

it('reads type, size, extension and image dimensions from the stored file', function () {
    Storage::fake('public');
    Storage::disk('public')->putFileAs('cms-media', UploadedFile::fake()->image('photo.png', 40, 20), 'photo.png');

    $data = app(MediaAssets::class)->enrich(['path' => 'cms-media/photo.png', 'filename' => '', 'meta' => ['keep' => 1]], 7);

    expect($data['disk'])->toBe('public')
        ->and($data['filename'])->toBe('photo.png')
        ->and($data['mime_type'])->toBe('image/png')
        ->and($data['size'])->toBeGreaterThan(0)
        ->and($data['uploaded_by_id'])->toBe(7)
        ->and($data['meta'])->toBe(['keep' => 1, 'extension' => 'png', 'width' => 40, 'height' => 20]);
});

it('drops stale dimensions for a file that is not an image, keeps an uploader already set, and survives a missing file', function () {
    Storage::fake('public');
    Storage::disk('public')->put('cms-media/a.txt', 'hello');
    $service = app(MediaAssets::class);

    $text = $service->enrich(['path' => 'cms-media/a.txt', 'filename' => 'a.txt', 'uploaded_by_id' => 3, 'meta' => ['width' => 9, 'height' => 9]], 7);
    $missing = $service->enrich(['path' => 'cms-media/gone.png', 'meta' => []]);
    $empty = $service->enrich(['path' => '  ', 'disk' => ' ']);

    expect($text['uploaded_by_id'])->toBe(3)
        ->and($text['mime_type'])->toBe('text/plain')
        ->and($text['meta'])->toBe(['extension' => 'txt'])
        ->and($missing['filename'])->toBe('gone.png')
        ->and($missing)->not->toHaveKey('mime_type')
        ->and($missing['meta'])->toBe(['extension' => 'png'])
        ->and($empty['disk'])->toBe('public');
});

it('makes one record per uploaded file, naming it from the original name when known', function () {
    Storage::fake('public');
    Storage::disk('public')->put('cms-media/x1.txt', 'a');
    Storage::disk('public')->put('cms-media/x2.txt', 'b');

    $uploader = TestUser::make();
    $assets = app(MediaAssets::class)->createFromUploadedPaths(['cms-media/x1.txt', ' ', 'cms-media/x2.txt'], ['cms-media/x1.txt' => 'Original one.txt'], $uploader->id);

    expect($assets)->toHaveCount(2)
        ->and($assets[0]->filename)->toBe('Original one.txt')
        ->and($assets[1]->filename)->toBe('x2.txt')
        ->and($assets[0]->uploaded_by_id)->toBe($uploader->id)
        ->and(CmsMediaAsset::count())->toBe(2);
});

it('deletes the stored file and tolerates one that is already gone', function () {
    Storage::fake('public');
    Storage::disk('public')->put('cms-media/del.txt', 'x');
    $asset = CmsMediaAsset::create(['path' => 'cms-media/del.txt', 'filename' => 'del.txt']);
    $service = app(MediaAssets::class);

    $service->deleteFile($asset);
    expect(Storage::disk('public')->exists('cms-media/del.txt'))->toBeFalse();

    $service->deleteFile($asset);
    $service->deletePath('public', '  ');
    expect(true)->toBeTrue();
});

it('lists the folders already in use under the upload root', function () {
    CmsMediaAsset::create(['path' => 'cms-media/heroes/home/a.png', 'filename' => 'a']);
    CmsMediaAsset::create(['path' => 'cms-media/b.png', 'filename' => 'b']);
    CmsMediaAsset::create(['path' => 'cms-media/legal/c.png', 'filename' => 'c']);
    CmsMediaAsset::create(['path' => 'elsewhere/d/d.png', 'filename' => 'd']);

    expect(app(MediaAssets::class)->folders())->toBe(['heroes/home' => 'heroes/home', 'legal' => 'legal']);
});
