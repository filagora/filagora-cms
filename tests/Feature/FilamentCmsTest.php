<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use ForumKit\Cms\Filament\Resources\CmsMediaAssets\CmsMediaAssetResource;
use ForumKit\Cms\Filament\Resources\CmsMediaAssets\Pages\CreateCmsMediaAsset;
use ForumKit\Cms\Filament\Resources\CmsMediaAssets\Pages\EditCmsMediaAsset;
use ForumKit\Cms\Filament\Resources\CmsMediaAssets\Pages\ListCmsMediaAssets;
use ForumKit\Cms\Filament\Resources\CmsNavigations\CmsNavigationResource;
use ForumKit\Cms\Filament\Resources\CmsNavigations\Pages\CreateCmsNavigation;
use ForumKit\Cms\Filament\Resources\CmsNavigations\Pages\EditCmsNavigation;
use ForumKit\Cms\Filament\Resources\CmsNavigations\Pages\ListCmsNavigations;
use ForumKit\Cms\Filament\Resources\CmsNavigations\RelationManagers\VersionsRelationManager as MenuVersions;
use ForumKit\Cms\Filament\Resources\CmsPages\CmsPageResource;
use ForumKit\Cms\Filament\Resources\CmsPages\Pages\CreateCmsPage;
use ForumKit\Cms\Filament\Resources\CmsPages\Pages\EditCmsPage;
use ForumKit\Cms\Filament\Resources\CmsPages\Pages\ListCmsPages;
use ForumKit\Cms\Filament\Resources\CmsPages\RelationManagers\BlocksRelationManager;
use ForumKit\Cms\Filament\Resources\CmsPages\RelationManagers\VersionsRelationManager;
use ForumKit\Cms\Filament\Resources\CmsPreviewShares\CmsPreviewShareResource;
use ForumKit\Cms\Filament\Resources\CmsPreviewShares\Pages\ListCmsPreviewShares;
use ForumKit\Cms\Filament\Resources\CmsTags\CmsTagResource;
use ForumKit\Cms\Filament\Resources\CmsTags\Pages\CreateCmsTag;
use ForumKit\Cms\Filament\Resources\CmsTags\Pages\EditCmsTag;
use ForumKit\Cms\Filament\Resources\CmsTags\Pages\ListCmsTags;
use ForumKit\Cms\Models\CmsBlock;
use ForumKit\Cms\Models\CmsMediaAsset;
use ForumKit\Cms\Models\CmsNavigation;
use ForumKit\Cms\Models\CmsNavigationDraft;
use ForumKit\Cms\Models\CmsPage;
use ForumKit\Cms\Models\CmsPageDraft;
use ForumKit\Cms\Models\CmsPreviewShare;
use ForumKit\Cms\Models\CmsTag;
use ForumKit\Cms\Preview\PreviewSession;
use ForumKit\Cms\Preview\PreviewShares;
use ForumKit\Cms\Services\NavigationDrafts;
use ForumKit\Cms\Services\PageDrafts;
use ForumKit\Cms\Tests\Support\TestUser;
use ForumKit\Entitlements\Models\UserEntitlementGrant;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

function cmsEditor(string ...$keys): TestUser
{
    $user = TestUser::make('Editor');

    foreach ($keys ?: ['forumkit.cms.manage'] as $key) {
        UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => $key, 'grant_type' => 'manual']);
    }

    actingAs($user);

    return $user;
}

function livePageWithBlocks(string $slug = 'about'): CmsPage
{
    $page = CmsPage::create(['slug' => $slug, 'title' => 'About', 'status' => 'published', 'published_at' => now()->subDay()])->refresh();
    $page->blocks()->create(['type' => 'hero', 'name' => 'Top', 'sort_order' => 1, 'data' => ['title' => 'Hello']]);

    return $page;
}

// ------------------------------------------------------------------ access

it('opens the CMS screens only to those holding the manage ability', function () {
    $resources = [CmsPageResource::class, CmsNavigationResource::class, CmsTagResource::class, CmsMediaAssetResource::class, CmsPreviewShareResource::class];

    cmsEditor('forumkit.cms.preview');
    foreach ($resources as $resource) {
        expect($resource::canViewAny())->toBeFalse($resource);
    }

    cmsEditor();
    foreach ($resources as $resource) {
        expect($resource::canViewAny())->toBeTrue($resource);
    }
    expect(CmsPreviewShareResource::canCreate())->toBeFalse();
});

it('registers the screens on the panel under the Content group', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getResources())->toContain(CmsPageResource::class, CmsNavigationResource::class, CmsTagResource::class, CmsMediaAssetResource::class, CmsPreviewShareResource::class)
        ->and(CmsPageResource::getNavigationGroup())->toBe('Content');
});

it('keeps the screens closed to a user without the ability', function () {
    actingAs(TestUser::make('Nobody'));

    Livewire::test(ListCmsPages::class)->assertForbidden();
    Livewire::test(ListCmsNavigations::class)->assertForbidden();
});

// ------------------------------------------------------------------ pages

it('lists pages with their state, and filters by status', function () {
    cmsEditor();
    $live = livePageWithBlocks();
    $draft = CmsPage::create(['slug' => 'wip', 'title' => 'Work in progress'])->refresh();

    Livewire::test(ListCmsPages::class)
        ->assertCanSeeTableRecords([$live, $draft])
        ->assertSee('Published')
        ->assertSee('Draft')
        ->filterTable('status', 'draft')
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$live]);
});

it('creates a page as an unpublished draft with its tags', function () {
    cmsEditor();
    $tag = CmsTag::create(['name' => 'News', 'slug' => 'news']);

    Livewire::test(CreateCmsPage::class)
        ->fillForm(['title' => 'Privacy', 'slug' => 'privacy', 'custom_route' => 'privacy', 'tag_ids' => [$tag->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    $page = CmsPage::where('slug', 'privacy')->sole();
    expect($page->status->value)->toBe('draft')
        ->and($page->custom_route)->toBe('/privacy')
        ->and($page->tags->pluck('slug')->all())->toBe(['news']);
});

it('checks a new page: title and slug required, slug unique, route characters', function () {
    cmsEditor();
    livePageWithBlocks('taken');

    Livewire::test(CreateCmsPage::class)
        ->fillForm(['title' => '', 'slug' => ''])
        ->call('create')
        ->assertHasFormErrors(['title' => 'required', 'slug' => 'required']);

    Livewire::test(CreateCmsPage::class)
        ->fillForm(['title' => 'X', 'slug' => 'taken', 'custom_route' => 'bad route!'])
        ->call('create')
        ->assertHasFormErrors(['slug', 'custom_route']);
});

it('opens a page on its working draft and saves edits to the draft, not the live page', function () {
    cmsEditor();
    $page = livePageWithBlocks();

    Livewire::test(EditCmsPage::class, ['record' => $page->getKey()])
        ->assertFormSet(['title' => 'About', 'slug' => 'about'])
        ->fillForm(['title' => 'About us', 'excerpt' => 'New excerpt'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->title)->toBe('About')
        ->and(CmsPageDraft::sole()->payload['page']['title'])->toBe('About us')
        ->and(CmsPageDraft::sole()->is_dirty)->toBeTrue();

    Livewire::test(EditCmsPage::class, ['record' => $page->getKey()])->assertFormSet(['title' => 'About us']);
});

it('publishes the draft from the editor and records a version', function () {
    $editor = cmsEditor();
    $page = livePageWithBlocks();
    app(PageDrafts::class)->saveDraftFields($page, ['title' => 'Published title']);

    Livewire::test(EditCmsPage::class, ['record' => $page->getKey()])
        ->callAction('publish', ['summary' => 'Big change'])
        ->assertNotified('Published');

    expect($page->refresh()->title)->toBe('Published title')
        ->and($page->versions()->first()->change_summary)->toBe('Big change')
        ->and($page->versions()->first()->created_by_id)->toBe($editor->id)
        ->and(CmsPageDraft::count())->toBe(0);
});

it('tells the editor why a publish was refused instead of failing', function () {
    cmsEditor();
    livePageWithBlocks('taken');
    $page = livePageWithBlocks('mine');
    app(PageDrafts::class)->saveDraftFields($page, ['slug' => 'taken']);

    Livewire::test(EditCmsPage::class, ['record' => $page->getKey()])
        ->callAction('publish')
        ->assertNotified('Cannot publish');

    expect($page->refresh()->slug)->toBe('mine');
});

it('schedules, cancels, discards and unpublishes', function () {
    cmsEditor();
    $page = livePageWithBlocks();
    app(PageDrafts::class)->saveDraftFields($page, ['title' => 'Later']);
    $component = Livewire::test(EditCmsPage::class, ['record' => $page->getKey()]);

    $component->callAction('schedule', ['publish_at' => now()->addDay()->toDateTimeString(), 'summary' => 'Soon']);
    expect(CmsPageDraft::sole()->scheduled_publish_at)->not->toBeNull()
        ->and(CmsPageDraft::sole()->scheduled_summary)->toBe('Soon');

    $component->callAction('cancel_schedule');
    expect(CmsPageDraft::sole()->scheduled_publish_at)->toBeNull();

    $component->callAction('discard_draft');
    expect(CmsPageDraft::count())->toBe(1)
        ->and(CmsPageDraft::sole()->is_dirty)->toBeFalse()
        ->and($page->refresh()->title)->toBe('About');

    $component->callAction('unpublish');
    expect($page->refresh()->status->value)->toBe('draft');
});

it('manages draft variants from the editor', function () {
    cmsEditor();
    $page = livePageWithBlocks();
    $component = Livewire::test(EditCmsPage::class, ['record' => $page->getKey()]);

    $component->callAction('new_variant', ['name' => 'Spring', 'from_live' => false]);
    expect(app(PageDrafts::class)->variantNames($page))->toBe(['Spring', 'Main']);

    $component->callAction('switch_variant', ['name' => 'Main']);
    expect(app(PageDrafts::class)->activeVariantName($page))->toBe('Main');

    $component->callAction('rename_variant', ['name' => 'Original']);
    $component->callAction('delete_variant', ['name' => 'Spring']);
    expect(app(PageDrafts::class)->variantNames($page))->toBe(['Original']);

    $component->callAction('new_variant', ['name' => 'original', 'from_live' => false])->assertNotified('Cannot change variants');
});

it('previews the draft: puts the page into the preview session and sends the editor to its address', function () {
    cmsEditor();
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);
    $page = livePageWithBlocks();
    app(PageDrafts::class)->saveDraftFields($page, ['slug' => 'about-new']);

    Livewire::test(EditCmsPage::class, ['record' => $page->getKey()])
        ->callAction('preview')
        ->assertRedirect(url('/pages/about-new'));

    expect(session(PreviewSession::SESSION_KEY)['pages'])->toBe([$page->id => true]);
});

it('creates a preview link for a variant from the editor', function () {
    $editor = cmsEditor();
    $page = livePageWithBlocks();
    app(PageDrafts::class)->ensureDraft($page);

    Livewire::test(EditCmsPage::class, ['record' => $page->getKey()])
        ->callAction('share_preview', ['variant' => 'Main', 'days' => 3, 'label' => 'For the board', 'include_session' => false])
        ->assertNotified('Preview link created');

    $share = CmsPreviewShare::sole();
    expect($share->label)->toBe('For the board')
        ->and($share->pages())->toBe([$page->id => 'Main'])
        ->and($share->created_by_id)->toBe($editor->id);
});

// ------------------------------------------------------------------ blocks and versions

it('adds, edits and removes staged blocks, keeping their content as JSON, and marks the draft dirty', function () {
    cmsEditor();
    $page = livePageWithBlocks();
    app(PageDrafts::class)->ensureDraft($page);
    $manager = fn () => Livewire::test(BlocksRelationManager::class, ['ownerRecord' => $page, 'pageClass' => EditCmsPage::class]);

    $manager()->assertCanSeeTableRecords($page->draftBlocks()->get());

    $manager()->callTableAction('create', data: ['type' => 'image', 'name' => 'Pic', 'sort_order' => 2, 'data_json' => '{"asset_id": 5}'])->assertHasNoTableActionErrors();
    $added = $page->draftBlocks()->where('name', 'Pic')->sole();
    expect($added->data)->toBe(['asset_id' => 5])
        ->and($added->is_draft)->toBeTrue()
        ->and($added->is_reusable)->toBeFalse()
        ->and(CmsPageDraft::sole()->is_dirty)->toBeTrue()
        ->and($page->blocks()->count())->toBe(1);

    $manager()->callTableAction('edit', $added, data: ['type' => 'image', 'name' => 'Pic 2', 'sort_order' => 3, 'data_json' => ''])->assertHasNoTableActionErrors();
    expect($added->refresh()->name)->toBe('Pic 2')->and($added->data)->toBeNull();

    $manager()->callTableAction('delete', $added);
    expect($page->draftBlocks()->count())->toBe(1);
});

it('rejects block content that is not JSON', function () {
    cmsEditor();
    $page = livePageWithBlocks();
    app(PageDrafts::class)->ensureDraft($page);

    Livewire::test(BlocksRelationManager::class, ['ownerRecord' => $page, 'pageClass' => EditCmsPage::class])
        ->callTableAction('create', data: ['type' => 'image', 'sort_order' => 0, 'data_json' => '{not json'])
        ->assertHasTableActionErrors(['data_json']);

    expect($page->draftBlocks()->count())->toBe(1);
});

it('shows a block of a type nobody registered, labelled as unknown, and lets it be edited', function () {
    cmsEditor();
    $page = livePageWithBlocks();
    app(PageDrafts::class)->ensureDraft($page);
    $odd = $page->draftBlocks()->create(['type' => 'old_site_type', 'name' => 'Odd', 'sort_order' => 9]);

    Livewire::test(BlocksRelationManager::class, ['ownerRecord' => $page, 'pageClass' => EditCmsPage::class])
        ->assertSee('Unknown type (Old Site Type)')
        ->callTableAction('edit', $odd, data: ['type' => 'old_site_type', 'name' => 'Odd 2', 'sort_order' => 9, 'data_json' => ''])
        ->assertHasNoTableActionErrors();

    expect($odd->refresh()->name)->toBe('Odd 2');
});

it('restores a recorded version into the draft from the versions list', function () {
    cmsEditor();
    $page = livePageWithBlocks();
    app(PageDrafts::class)->saveDraftFields($page, ['title' => 'Version one']);
    $one = app(PageDrafts::class)->publish($page);
    app(PageDrafts::class)->saveDraftFields($page, ['title' => 'Version two']);
    app(PageDrafts::class)->publish($page);

    Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $page, 'pageClass' => EditCmsPage::class])
        ->assertCanSeeTableRecords($page->versions)
        ->callTableAction('restore_version', $one)
        ->assertNotified('Version loaded into draft');

    expect(CmsPageDraft::sole()->payload['page']['title'])->toBe('Version one')
        ->and($page->refresh()->title)->toBe('Version two');
});

// ------------------------------------------------------------------ menus

it('creates a menu and lists it with its link count and state', function () {
    cmsEditor();

    Livewire::test(CreateCmsNavigation::class)
        ->fillForm(['name' => 'header', 'items' => [['label' => 'Home', 'type' => 'url', 'url' => '/', 'target' => '_self']]])
        ->call('create')
        ->assertHasNoFormErrors();

    $menu = CmsNavigation::where('name', 'header')->sole();
    Livewire::test(ListCmsNavigations::class)->assertCanSeeTableRecords([$menu])->assertSee('Published');

    Livewire::test(CreateCmsNavigation::class)->fillForm(['name' => 'header'])->call('create')->assertHasFormErrors(['name']);
});

it('edits a menu through its draft, publishes it, and applies a rename at once', function () {
    cmsEditor();
    $menu = CmsNavigation::create(['name' => 'footer', 'items' => [['label' => 'Old', 'url' => '/old']]]);

    $component = Livewire::test(EditCmsNavigation::class, ['record' => $menu->getKey()])
        ->assertFormSet(['name' => 'footer'])
        ->fillForm(['name' => 'footer2', 'items' => [['label' => 'New', 'type' => 'url', 'url' => '/new', 'target' => '_self']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($menu->refresh()->name)->toBe('footer2')
        ->and($menu->items[0]['label'])->toBe('Old')
        ->and(CmsNavigationDraft::sole()->items()[0]['label'])->toBe('New');

    $component->callAction('publish', ['summary' => 'Reworded'])->assertNotified('Published');

    expect($menu->refresh()->items[0]['label'])->toBe('New')
        ->and($menu->versions()->first()->change_summary)->toBe('Reworded');
});

it('schedules, discards and restores a menu from its versions', function () {
    cmsEditor();
    $menu = CmsNavigation::create(['name' => 'footer', 'items' => [['label' => 'One', 'url' => '/1']]]);
    app(NavigationDrafts::class)->saveDraft($menu, [['label' => 'Two', 'url' => '/2']]);
    $one = app(NavigationDrafts::class)->publish($menu);
    app(NavigationDrafts::class)->saveDraft($menu, [['label' => 'Three', 'url' => '/3']]);
    $component = Livewire::test(EditCmsNavigation::class, ['record' => $menu->getKey()]);

    $component->callAction('schedule', ['publish_at' => now()->addDay()->toDateTimeString()]);
    expect(CmsNavigationDraft::sole()->scheduled_publish_at)->not->toBeNull();
    $component->callAction('cancel_schedule');
    $component->callAction('discard_draft');
    expect($menu->refresh()->items[0]['label'])->toBe('Two');

    Livewire::test(MenuVersions::class, ['ownerRecord' => $menu, 'pageClass' => EditCmsNavigation::class])
        ->callTableAction('restore_version', $one);
    expect(CmsNavigationDraft::sole()->items()[0]['label'])->toBe('Two')
        ->and(CmsNavigationDraft::sole()->is_dirty)->toBeTrue();
});

it('previews a menu from its editor', function () {
    cmsEditor();
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);
    $menu = CmsNavigation::create(['name' => 'header', 'items' => []]);

    Livewire::test(EditCmsNavigation::class, ['record' => $menu->getKey()])->callAction('preview')->assertNotified('Previewing this menu');

    expect(session(PreviewSession::SESSION_KEY)['navigations'])->toBe(['header' => true]);
});

// ------------------------------------------------------------------ tags

it('creates, edits and deletes tags, deriving the slug from the name', function () {
    cmsEditor();

    Livewire::test(CreateCmsTag::class)->fillForm(['name' => 'Release Notes', 'slug' => 'release-notes'])->call('create')->assertHasNoFormErrors();
    $tag = CmsTag::where('slug', 'release-notes')->sole();

    Livewire::test(ListCmsTags::class)->assertCanSeeTableRecords([$tag]);
    Livewire::test(EditCmsTag::class, ['record' => $tag->getKey()])->fillForm(['name' => 'Notes'])->call('save')->assertHasNoFormErrors();
    expect($tag->refresh()->name)->toBe('Notes')->and($tag->slug)->toBe('notes');

    Livewire::test(CreateCmsTag::class)->fillForm(['name' => 'Dup', 'slug' => 'notes'])->call('create')->assertHasFormErrors(['slug']);
    Livewire::test(EditCmsTag::class, ['record' => $tag->getKey()])->callAction('delete');
    expect(CmsTag::count())->toBe(0);
});

// ------------------------------------------------------------------ media

it('uploads a file as a media asset with its details read from the file', function () {
    $editor = cmsEditor();
    Storage::fake('public');

    Livewire::test(CreateCmsMediaAsset::class)
        ->fillForm(['path' => UploadedFile::fake()->image('hero.png', 30, 10), 'alt_text' => 'A hero'])
        ->call('create')
        ->assertHasNoFormErrors();

    $asset = CmsMediaAsset::sole();
    expect($asset->alt_text)->toBe('A hero')
        ->and($asset->mime_type)->toBe('image/png')
        ->and($asset->width())->toBe(30)
        ->and($asset->uploaded_by_id)->toBe($editor->id)
        ->and(Storage::disk('public')->exists($asset->path))->toBeTrue()
        ->and($asset->path)->toStartWith('cms-media/');
});

it('bulk uploads several files, one record each', function () {
    cmsEditor();
    Storage::fake('public');

    Livewire::test(ListCmsMediaAssets::class)
        ->callAction('bulkUpload', [
            'folder_mode' => 'new',
            'new_subdirectory' => 'heroes/home',
            'paths' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png')],
        ])
        ->assertNotified('2 media assets uploaded');

    expect(CmsMediaAsset::count())->toBe(2)
        ->and(CmsMediaAsset::first()->path)->toStartWith('cms-media/heroes/home/');
});

it('lists media with how many blocks use each, and filters images from other files', function () {
    cmsEditor();
    $image = CmsMediaAsset::create(['path' => 'cms-media/a.png', 'filename' => 'a.png', 'mime_type' => 'image/png']);
    $doc = CmsMediaAsset::create(['path' => 'cms-media/b.pdf', 'filename' => 'b.pdf', 'mime_type' => 'application/pdf']);
    CmsBlock::create(['type' => 'image', 'data' => ['asset_id' => $image->id]]);

    Livewire::test(ListCmsMediaAssets::class)
        ->assertCanSeeTableRecords([$image, $doc])
        ->filterTable('kind', 'image')
        ->assertCanSeeTableRecords([$image])
        ->assertCanNotSeeTableRecords([$doc]);
});

it('asks for confirmation before deleting a media asset that is still used, and removes the file when it goes', function () {
    cmsEditor();
    Storage::fake('public');
    Storage::disk('public')->put('cms-media/used.png', 'x');
    $asset = CmsMediaAsset::create(['disk' => 'public', 'path' => 'cms-media/used.png', 'filename' => 'used.png', 'mime_type' => 'image/png']);
    CmsBlock::create(['type' => 'image', 'data' => ['asset_id' => $asset->id]]);

    Livewire::test(EditCmsMediaAsset::class, ['record' => $asset->getKey()])
        ->callAction('delete', ['confirm_in_use_delete' => false]);
    expect(CmsMediaAsset::count())->toBe(1)->and(Storage::disk('public')->exists('cms-media/used.png'))->toBeTrue();

    Livewire::test(EditCmsMediaAsset::class, ['record' => $asset->getKey()])
        ->callAction('delete', ['confirm_in_use_delete' => true]);
    expect(CmsMediaAsset::count())->toBe(0)->and(Storage::disk('public')->exists('cms-media/used.png'))->toBeFalse();
});

// ------------------------------------------------------------------ preview links

it('lists preview links and revokes one', function () {
    cmsEditor();
    $page = livePageWithBlocks();
    app(PageDrafts::class)->ensureDraft($page);
    $share = app(PreviewShares::class)->create(TestUser::make(), [], [$page->id => 'Main'], 3, 'Board');

    Livewire::test(ListCmsPreviewShares::class)
        ->assertCanSeeTableRecords([$share])
        ->assertSee('Board')
        ->assertSee('Active')
        ->callTableAction('revoke', $share)
        ->assertNotified('Link revoked');

    expect($share->refresh()->revoked_at)->not->toBeNull();

    Livewire::test(ListCmsPreviewShares::class)->assertSee('Revoked');
});
