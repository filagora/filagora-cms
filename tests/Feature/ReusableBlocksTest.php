<?php

declare(strict_types=1);

use Filagora\Cms\Events\CmsBlockPublished;
use Filagora\Cms\Filament\Resources\CmsBlocks\CmsBlockResource;
use Filagora\Cms\Filament\Resources\CmsBlocks\Pages\CreateCmsBlock;
use Filagora\Cms\Filament\Resources\CmsBlocks\Pages\EditCmsBlock;
use Filagora\Cms\Filament\Resources\CmsBlocks\Pages\ListCmsBlocks;
use Filagora\Cms\Filament\Resources\CmsBlocks\RelationManagers\VersionsRelationManager;
use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Services\BlockDrafts;
use Filagora\Cms\Tests\Support\TestUser;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->editor = TestUser::make('Editor');
    UserEntitlementGrant::create(['user_id' => $this->editor->id, 'entitlement_key' => 'filagora.cms.manage', 'grant_type' => 'manual']);
    actingAs($this->editor);
});

function footerBlock(array $attributes = []): CmsBlock
{
    return CmsBlock::create(array_merge([
        'type' => 'cta_band', 'slug' => 'footer', 'name' => 'Footer', 'is_reusable' => true, 'is_published' => true, 'sort_order' => 1,
        'data' => ['title' => 'Live title', 'body' => '', 'theme' => 'dark', 'primary_label' => '', 'primary_url' => '', 'secondary_label' => '', 'secondary_url' => ''],
    ], $attributes))->refresh();
}

it('is open to editors only', function () {
    expect(CmsBlockResource::canViewAny())->toBeTrue();

    actingAs(TestUser::make('Nobody'));
    expect(CmsBlockResource::canViewAny())->toBeFalse();
    Livewire::test(ListCmsBlocks::class)->assertForbidden();
});

it('lists reusable blocks only, not blocks on pages or staged copies, and marks pending changes', function () {
    $block = footerBlock();
    $page = CmsPage::create(['slug' => 'p', 'title' => 'P']);
    $onPage = $page->blocks()->create(['type' => 'hero', 'name' => 'On page']);
    app(BlockDrafts::class)->saveDraft($block, ['name' => 'Footer v2']);
    $clean = footerBlock(['slug' => 'header', 'name' => 'Header']);

    Livewire::test(ListCmsBlocks::class)
        ->assertCanSeeTableRecords([$block, $clean])
        ->assertCanNotSeeTableRecords([$onPage])
        ->assertSee('Unpublished changes')
        ->assertSee('Call-to-action band');
});

it('creates a reusable block live and unpublished, through the form of its type', function () {
    Livewire::test(CreateCmsBlock::class)
        ->fillForm(['slug' => 'promo', 'name' => 'Promo', 'type' => 'cta_band', 'sort_order' => 0, 'data' => ['title' => ' Join ', 'primary_label' => 'Go', 'primary_url' => '/go']])
        ->call('create')
        ->assertHasNoFormErrors();

    $block = CmsBlock::where('slug', 'promo')->sole();
    expect($block->is_reusable)->toBeTrue()
        ->and($block->cms_page_id)->toBeNull()
        ->and($block->is_published)->toBeFalse()
        ->and($block->data['title'])->toBe('Join')
        ->and($block->data['primary_url'])->toBe('/go');
});

it('checks a new block: slug required, well formed and unique', function () {
    footerBlock();

    Livewire::test(CreateCmsBlock::class)->fillForm(['slug' => '', 'type' => 'divider', 'sort_order' => 0])->call('create')->assertHasFormErrors(['slug' => 'required']);
    Livewire::test(CreateCmsBlock::class)->fillForm(['slug' => 'has spaces', 'type' => 'divider', 'sort_order' => 0])->call('create')->assertHasFormErrors(['slug']);
    Livewire::test(CreateCmsBlock::class)->fillForm(['slug' => 'footer', 'type' => 'divider', 'sort_order' => 0])->call('create')->assertHasFormErrors(['slug']);
});

it('saves edits to the staged copy only, and applies a slug change at once', function () {
    $block = footerBlock();

    Livewire::test(EditCmsBlock::class, ['record' => $block->getKey()])
        ->assertFormSet(['slug' => 'footer', 'data.title' => 'Live title'])
        ->fillForm(['slug' => 'site-footer', 'name' => 'Footer v2', 'data' => ['title' => 'Staged title', 'theme' => 'warm']])
        ->call('save')
        ->assertHasNoFormErrors();

    $staged = $block->draftCopy()->first();
    expect($block->refresh()->slug)->toBe('site-footer')
        ->and($block->name)->toBe('Footer')
        ->and($block->data['title'])->toBe('Live title')
        ->and($staged->name)->toBe('Footer v2')
        ->and($staged->data['title'])->toBe('Staged title')
        ->and($staged->data['theme'])->toBe('warm');

    Livewire::test(EditCmsBlock::class, ['record' => $block->getKey()])->assertFormSet(['name' => 'Footer v2', 'data.title' => 'Staged title']);
});

it('publishes the staged copy, records a version, and reads as live afterwards', function () {
    Event::fake([CmsBlockPublished::class]);
    $block = footerBlock();
    app(BlockDrafts::class)->saveDraft($block, ['name' => 'Footer v2', 'data' => ['title' => 'Staged title']]);

    Livewire::test(EditCmsBlock::class, ['record' => $block->getKey()])
        ->callAction('publish', ['summary' => 'Reworded'])
        ->assertNotified('Published');

    expect($block->refresh()->name)->toBe('Footer v2')
        ->and($block->data['title'])->toBe('Staged title')
        ->and($block->draftCopy()->exists())->toBeFalse()
        ->and($block->versions()->first()->change_summary)->toBe('Reworded')
        ->and($block->versions()->first()->created_by_id)->toBe($this->editor->id);
    Event::assertDispatched(CmsBlockPublished::class);
});

it('offers publish and discard only while there is a staged copy, and discards it', function () {
    $block = footerBlock();
    $component = Livewire::test(EditCmsBlock::class, ['record' => $block->getKey()]);

    $component->assertActionHidden('publish')->assertActionHidden('discard_draft');

    app(BlockDrafts::class)->saveDraft($block, ['name' => 'Staged']);
    $component = Livewire::test(EditCmsBlock::class, ['record' => $block->getKey()]);
    $component->assertActionVisible('publish')->callAction('discard_draft');

    expect($block->draftCopy()->exists())->toBeFalse()
        ->and($block->refresh()->name)->toBe('Footer');
});

it('deletes the block together with its staged copy, leaving nothing behind', function () {
    $block = footerBlock();
    app(BlockDrafts::class)->saveDraft($block, ['name' => 'Staged']);

    Livewire::test(EditCmsBlock::class, ['record' => $block->getKey()])->callAction('delete');

    expect(CmsBlock::withoutGlobalScopes()->count())->toBe(0);
});

it('restores a recorded version into the staged copy from the versions list', function () {
    $block = footerBlock();
    $service = app(BlockDrafts::class);
    $one = $service->createVersion($block, null, 'Initial');
    $service->saveDraft($block, ['name' => 'Two', 'data' => ['title' => 'Two']]);
    $service->publish($block);

    Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $block->refresh(), 'pageClass' => EditCmsBlock::class])
        ->assertCanSeeTableRecords($block->versions)
        ->callTableAction('restore_version', $one);

    $staged = $block->draftCopy()->first();
    expect($staged->name)->toBe('Footer')
        ->and($staged->data['title'])->toBe('Live title')
        ->and($block->refresh()->name)->toBe('Two');
});

it('loads a block of a type the registry does not know as JSON, so it can still be edited', function () {
    $odd = footerBlock(['type' => 'old_site_type', 'slug' => 'odd', 'data' => ['a' => 1]]);

    Livewire::test(EditCmsBlock::class, ['record' => $odd->getKey()])
        ->assertFormSet(['type' => 'old_site_type'])
        ->assertSee('Content (JSON)');
});
