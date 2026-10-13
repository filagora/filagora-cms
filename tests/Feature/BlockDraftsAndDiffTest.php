<?php

declare(strict_types=1);

use Filagora\Cms\Events\CmsBlockPublished;
use Filagora\Cms\Exceptions\DraftOperationFailed;
use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Models\CmsBlockVersion;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Models\CmsTag;
use Filagora\Cms\Services\BlockDrafts;
use Filagora\Cms\Services\PageDraftDiff;
use Filagora\Cms\Services\PageDrafts;
use Filagora\Cms\Tests\Support\TestUser;
use Illuminate\Support\Facades\Event;

function reusable(array $attributes = []): CmsBlock
{
    return CmsBlock::create(array_merge(['type' => 'cta_band', 'slug' => 'footer', 'name' => 'Footer', 'is_reusable' => true, 'is_published' => true, 'sort_order' => 1, 'data' => ['text' => 'Live']], $attributes))->refresh();
}

// ------------------------------------------------------------------ reusable block drafts

it('stages a copy of a reusable block, hidden from live reads, once', function () {
    $block = reusable();

    $draft = app(BlockDrafts::class)->ensureDraft($block);

    expect($draft->is_draft)->toBeTrue()
        ->and($draft->live_block_id)->toBe($block->id)
        ->and($draft->is_reusable)->toBeTrue()
        ->and($draft->slug)->toBeNull()
        ->and($draft->data)->toBe(['text' => 'Live'])
        ->and(CmsBlock::count())->toBe(1)
        ->and(app(BlockDrafts::class)->ensureDraft($block)->id)->toBe($draft->id)
        ->and(CmsBlock::withoutGlobalScopes()->count())->toBe(2);
});

it('saves only the stageable fields into the staged copy, and says when it differs from live', function () {
    $block = reusable();
    $service = app(BlockDrafts::class);

    expect($service->hasPendingChanges($block))->toBeFalse();

    $service->ensureDraft($block);
    expect($service->hasPendingChanges($block))->toBeFalse();

    $draft = $service->saveDraft($block, ['name' => 'New name', 'slug' => 'ignored', 'is_reusable' => false, 'data' => ['text' => 'Staged']]);

    expect($draft->name)->toBe('New name')
        ->and($draft->slug)->toBeNull()
        ->and($draft->is_reusable)->toBeTrue()
        ->and($service->hasPendingChanges($block))->toBeTrue()
        ->and($block->refresh()->name)->toBe('Footer');

    foreach ([['type' => 'divider'], ['sort_order' => 9], ['is_published' => false]] as $change) {
        $service->discard($block);
        $service->saveDraft($block, $change);
        expect($service->hasPendingChanges($block))->toBeTrue(json_encode($change));
    }
});

it('discards the staged copy', function () {
    $block = reusable();
    app(BlockDrafts::class)->saveDraft($block, ['name' => 'x']);

    app(BlockDrafts::class)->discard($block);

    expect(CmsBlock::withoutGlobalScopes()->count())->toBe(1)
        ->and(app(BlockDrafts::class)->hasPendingChanges($block))->toBeFalse();
});

it('publishes the staged copy onto the live block, records a version and announces it', function () {
    Event::fake([CmsBlockPublished::class]);
    $block = reusable();
    $actor = TestUser::make();
    app(BlockDrafts::class)->saveDraft($block, ['name' => 'Footer v2', 'data' => ['text' => 'New'], 'is_published' => false]);

    $version = app(BlockDrafts::class)->publish($block, $actor, 'Reworded');

    $block->refresh();
    expect($block->name)->toBe('Footer v2')
        ->and($block->data)->toBe(['text' => 'New'])
        ->and($block->is_published)->toBeFalse()
        ->and($block->slug)->toBe('footer')
        ->and(CmsBlock::withoutGlobalScopes()->count())->toBe(1)
        ->and($version->version_number)->toBe(1)
        ->and($version->change_summary)->toBe('Reworded')
        ->and($version->created_by_id)->toBe($actor->id)
        ->and($version->snapshot)->toBe(['slug' => 'footer', 'type' => 'cta_band', 'name' => 'Footer v2', 'sort_order' => 1, 'is_published' => false, 'data' => ['text' => 'New']]);
    Event::assertDispatched(CmsBlockPublished::class, fn (CmsBlockPublished $e) => $e->block->is($block) && $e->version->is($version));

    app(BlockDrafts::class)->saveDraft($block, ['name' => 'v3']);
    expect(app(BlockDrafts::class)->publish($block)->change_summary)->toBe('Published')
        ->and($block->versions()->first()->version_number)->toBe(2);
});

it('refuses to publish a block with nothing staged', function () {
    try {
        app(BlockDrafts::class)->publish(reusable());
        $this->fail('should have thrown');
    } catch (DraftOperationFailed $e) {
        expect($e->status)->toBe(409);
    }
});

it('loads a recorded version into the staged copy, not the live block', function () {
    $block = reusable();
    $service = app(BlockDrafts::class);
    $one = $service->createVersion($block, null, 'Initial');
    $service->saveDraft($block, ['name' => 'Two', 'data' => ['text' => 'Two']]);
    $service->publish($block);

    $draft = $service->restoreVersionToDraft($block->refresh(), $one);

    expect($draft->name)->toBe('Footer')
        ->and($draft->data)->toBe(['text' => 'Live'])
        ->and($block->name)->toBe('Two');

    $foreign = CmsBlockVersion::create(['cms_block_id' => reusable(['slug' => 'other'])->id, 'version_number' => 1, 'snapshot' => []]);
    expect(fn () => $service->restoreVersionToDraft($block, $foreign))->toThrow(DraftOperationFailed::class);
});

// ------------------------------------------------------------------ the diff

function diffPage(): CmsPage
{
    $page = CmsPage::create(['slug' => 'd', 'title' => 'Title', 'excerpt' => 'Short', 'status' => 'published', 'published_at' => now()->subDay()])->refresh();
    $page->blocks()->create(['type' => 'hero', 'name' => 'Top', 'sort_order' => 1, 'data' => ['title' => 'A', 'sub' => 'B']]);
    $page->blocks()->create(['type' => 'divider', 'name' => 'Line', 'sort_order' => 2]);
    $page->tags()->attach(CmsTag::create(['name' => 'Old', 'slug' => 'old']));

    return $page->refresh();
}

it('reports nothing when there is no draft or the draft matches live', function () {
    $page = diffPage();
    $diff = app(PageDraftDiff::class);

    expect($diff->forPage($page))->toBe(['fields' => [], 'tags' => ['added' => [], 'removed' => []], 'blocks' => [], 'count' => 0]);

    app(PageDrafts::class)->ensureDraft($page);
    expect($diff->forPage($page)['count'])->toBe(0);
});

it('lists changed fields with before and after, shortening long text and marking empties', function () {
    $page = diffPage();
    app(PageDrafts::class)->saveDraftFields($page, ['title' => ' Title ', 'excerpt' => '', 'seo_title' => str_repeat('x', 200), 'slug' => 'd2']);

    $fields = app(PageDraftDiff::class)->forPage($page)['fields'];

    expect(array_column($fields, 'label'))->toBe(['Slug', 'Excerpt', 'SEO title'])
        ->and($fields[0])->toBe(['label' => 'Slug', 'before' => 'd', 'after' => 'd2'])
        ->and($fields[1])->toBe(['label' => 'Excerpt', 'before' => 'Short', 'after' => '(empty)'])
        ->and($fields[2]['before'])->toBe('(empty)')
        ->and($fields[2]['after'])->toBe(str_repeat('x', 80).'...');
});

it('lists tags added and removed by name', function () {
    $page = diffPage();
    $new = CmsTag::create(['name' => 'Zed', 'slug' => 'zed']);
    $other = CmsTag::create(['name' => 'Alpha', 'slug' => 'alpha']);
    app(PageDrafts::class)->saveDraftFields($page, ['tag_ids' => [$new->id, $other->id]]);

    $tags = app(PageDraftDiff::class)->forPage($page)['tags'];

    expect($tags)->toBe(['added' => ['Alpha', 'Zed'], 'removed' => ['Old']]);
});

it('lists blocks added, edited (saying what changed) and removed', function () {
    $page = diffPage();
    app(PageDrafts::class)->ensureDraft($page);
    $top = $page->draftBlocks()->where('name', 'Top')->first();
    $top->update(['name' => 'Top 2', 'sort_order' => 5, 'type' => 'image', 'data' => ['title' => 'A', 'sub' => 'C', 'extra' => 1]]);
    $page->draftBlocks()->where('name', 'Line')->delete();
    $page->draftBlocks()->create(['type' => 'embed', 'name' => 'Video', 'sort_order' => 3]);

    $diff = app(PageDraftDiff::class)->forPage($page);

    expect($diff['blocks'])->toBe([
        ['status' => 'Added', 'label' => 'Video (Embed)', 'details' => []],
        ['status' => 'Edited', 'label' => 'Top 2 (Image)', 'details' => ['Type changed', 'Name changed', 'Display order changed (1 → 5)', 'Sub changed', 'Extra changed']],
        ['status' => 'Removed', 'label' => 'Line (Divider)', 'details' => []],
    ])->and($diff['count'])->toBe(3);
});

it('labels a staged block New or Edited against its live counterpart, or nothing when unchanged', function () {
    $page = diffPage();
    app(PageDrafts::class)->ensureDraft($page);
    $staged = $page->draftBlocks()->get();
    $live = $page->blocks()->get()->keyBy('id');
    $diff = app(PageDraftDiff::class);

    expect($diff->blockStatus($staged[0], $live->get($staged[0]->live_block_id)))->toBeNull();

    $staged[0]->name = 'Changed';
    expect($diff->blockStatus($staged[0], $live->get($staged[0]->live_block_id)))->toBe('Edited')
        ->and($diff->blockStatus($staged[0], null))->toBe('New');
});

it('counts every change', function () {
    $page = diffPage();
    app(PageDrafts::class)->saveDraftFields($page, ['title' => 'New title', 'excerpt' => 'Other', 'tag_ids' => []]);

    expect(app(PageDraftDiff::class)->forPage($page)['count'])->toBe(3);
});
