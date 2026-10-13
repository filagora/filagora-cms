<?php

declare(strict_types=1);

use ForumKit\Cms\Events\CmsPagePublished;
use ForumKit\Cms\Exceptions\DraftOperationFailed;
use ForumKit\Cms\Models\CmsBlock;
use ForumKit\Cms\Models\CmsPage;
use ForumKit\Cms\Models\CmsPageDraft;
use ForumKit\Cms\Models\CmsPageDraftVariant;
use ForumKit\Cms\Models\CmsPageVersion;
use ForumKit\Cms\Models\CmsTag;
use ForumKit\Cms\Services\PageDrafts;
use ForumKit\Cms\Services\PageVersioning;
use ForumKit\Cms\Tests\Support\TestUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

function drafts(): PageDrafts
{
    return app(PageDrafts::class);
}

/**
 * A published page with two live blocks and one tag.
 */
function sitePage(array $attributes = []): CmsPage
{
    $page = CmsPage::create(array_merge([
        'slug' => 'about', 'title' => 'About', 'excerpt' => 'Live excerpt', 'status' => 'published', 'published_at' => now()->subDay(),
    ], $attributes))->refresh();
    $page->blocks()->create(['type' => 'hero', 'name' => 'Top', 'sort_order' => 1, 'is_published' => true, 'data' => ['title' => 'Hello']]);
    $page->blocks()->create(['type' => 'divider', 'name' => 'Line', 'sort_order' => 2, 'is_published' => true]);
    $page->tags()->attach(CmsTag::firstOrCreate(['slug' => 'news'], ['name' => 'News']));

    return $page->refresh();
}

function liveTitles(CmsPage $page): array
{
    return $page->blocks()->pluck('name')->all();
}

// ------------------------------------------------------------------ seeding and editing

it('seeds the working draft from live content: fields, tags, and a staged copy of each block', function () {
    $page = sitePage();
    $actor = TestUser::make();

    $draft = drafts()->ensureDraft($page, $actor);

    expect($draft->variant_name)->toBe('Main')
        ->and($draft->is_dirty)->toBeFalse()
        ->and($draft->updated_by_id)->toBe($actor->id)
        ->and($draft->base_version_id)->toBeNull()
        ->and($draft->payload['page'])->toBe(['slug' => 'about', 'custom_route' => null, 'title' => 'About', 'excerpt' => 'Live excerpt', 'seo_title' => null, 'seo_description' => null])
        ->and($draft->payload['tag_ids'])->toHaveCount(1);

    $staged = $page->draftBlocks()->get();
    expect($staged->pluck('name')->all())->toBe(['Top', 'Line'])
        ->and($staged->pluck('live_block_id')->all())->toBe($page->blocks()->pluck('id')->all())
        ->and($staged->every(fn (CmsBlock $block): bool => $block->is_draft && ! $block->is_published))->toBeTrue()
        ->and(liveTitles($page))->toBe(['Top', 'Line']);
});

it('returns the same draft when asked again, and names a new one when asked to', function () {
    $page = sitePage();
    $first = drafts()->ensureDraft($page, null, 'Spring');

    expect($first->variant_name)->toBe('Spring')
        ->and(drafts()->ensureDraft($page)->id)->toBe($first->id)
        ->and(CmsPageDraft::count())->toBe(1)
        ->and($page->draftBlocks()->count())->toBe(2);
});

it('records the latest version as the draft\'s base', function () {
    $page = sitePage();
    $version = app(PageVersioning::class)->createSnapshot($page);

    expect(drafts()->ensureDraft($page)->base_version_id)->toBe($version->id);
});

it('saves edits into the draft only, marking it dirty, and leaves live content alone', function () {
    $page = sitePage();
    $tag = CmsTag::create(['name' => 'Other', 'slug' => 'other']);

    $draft = drafts()->saveDraftFields($page, ['title' => 'New title', 'junk' => 'ignored', 'tag_ids' => ['3', 3, $tag->id, $tag->id]]);

    expect($draft->is_dirty)->toBeTrue()
        ->and($draft->payload['page']['title'])->toBe('New title')
        ->and($draft->payload['page'])->not->toHaveKey('junk')
        ->and($draft->payload['tag_ids'])->toBe([3, $tag->id])
        ->and($page->refresh()->title)->toBe('About')
        ->and(drafts()->formState($page)['title'])->toBe('New title')
        ->and(drafts()->formState($page)['tag_ids'])->toBe([3, $tag->id]);
});

it('marks a draft dirty by page id', function () {
    $page = sitePage();
    drafts()->ensureDraft($page);

    drafts()->markDirty($page->id);

    expect(CmsPageDraft::sole()->is_dirty)->toBeTrue();
});

// ------------------------------------------------------------------ variants

it('keeps the current draft as a parked variant when a new one starts from a copy of it', function () {
    $page = sitePage();
    drafts()->saveDraftFields($page, ['title' => 'Draft A']);

    $b = drafts()->createVariant($page, 'B');
    drafts()->saveDraftFields($page, ['title' => 'Draft B']);

    expect($b->variant_name)->toBe('B')
        ->and(drafts()->variantNames($page))->toBe(['B', 'Main'])
        ->and(drafts()->activeVariantName($page))->toBe('B')
        ->and(CmsPageDraftVariant::sole()->name)->toBe('Main')
        ->and(CmsPageDraftVariant::sole()->snapshot['payload']['page']['title'])->toBe('Draft A')
        ->and(CmsPageDraft::sole()->payload['page']['title'])->toBe('Draft B');
});

it('starts a variant from the live page when asked, parking the current draft', function () {
    $page = sitePage();
    drafts()->saveDraftFields($page, ['title' => 'Edited']);
    $page->draftBlocks()->where('name', 'Line')->delete();

    $fresh = drafts()->createVariant($page, 'Fresh', fromLive: true);

    expect($fresh->payload['page']['title'])->toBe('About')
        ->and($page->draftBlocks()->count())->toBe(2)
        ->and(CmsPageDraftVariant::sole()->name)->toBe('Main')
        ->and(count(CmsPageDraftVariant::sole()->snapshot['blocks']))->toBe(1);
});

it('creates a first variant when there is no draft yet', function () {
    $page = sitePage();

    expect(drafts()->createVariant($page, 'Solo')->variant_name)->toBe('Solo')
        ->and(drafts()->variantNames($page))->toBe(['Solo']);
});

it('swaps a parked variant in, with its blocks, and parks the working draft in its place', function () {
    $page = sitePage();
    drafts()->saveDraftFields($page, ['title' => 'Draft A']);
    $page->draftBlocks()->where('name', 'Line')->delete();
    drafts()->createVariant($page, 'B', fromLive: true);
    drafts()->saveDraftFields($page, ['title' => 'Draft B']);

    $main = drafts()->switchVariant($page, 'Main');

    expect($main->payload['page']['title'])->toBe('Draft A')
        ->and($page->draftBlocks()->pluck('name')->all())->toBe(['Top'])
        ->and(drafts()->variantNames($page))->toBe(['Main', 'B'])
        ->and(CmsPageDraftVariant::sole()->name)->toBe('B')
        ->and(drafts()->switchVariant($page, 'Main')->id)->toBe($main->id);
});

it('refuses to switch away from, or branch, a draft that has a schedule', function () {
    $page = sitePage();
    drafts()->ensureDraft($page);
    drafts()->createVariant($page, 'B');
    drafts()->schedule($page, now()->addDay());

    expect(fn () => drafts()->switchVariant($page, 'Main'))->toThrow(ValidationException::class)
        ->and(fn () => drafts()->createVariant($page, 'C'))->toThrow(ValidationException::class);

    drafts()->cancelSchedule($page);
    expect(drafts()->switchVariant($page, 'Main')->variant_name)->toBe('Main');
});

it('refuses to switch to a variant that does not exist', function () {
    $page = sitePage();
    drafts()->ensureDraft($page);

    try {
        drafts()->switchVariant($page, 'Nope');
        $this->fail('should have thrown');
    } catch (DraftOperationFailed $e) {
        expect($e->status)->toBe(404);
    }
});

it('checks variant names: present, short, and unique ignoring case', function () {
    $page = sitePage();
    drafts()->ensureDraft($page);

    foreach (['', '   ', str_repeat('x', 101), 'main', ' MAIN '] as $bad) {
        expect(fn () => drafts()->createVariant($page, $bad))->toThrow(ValidationException::class);
    }

    drafts()->createVariant($page, str_repeat('x', 100));
    expect(drafts()->variantNames($page))->toHaveCount(2);
});

it('renames the working draft and parked variants, keeping names unique', function () {
    $page = sitePage();
    drafts()->ensureDraft($page);
    drafts()->createVariant($page, 'B');

    drafts()->renameVariant($page, 'B', 'Bee');
    drafts()->renameVariant($page, 'Main', 'Original');

    expect(drafts()->variantNames($page))->toBe(['Bee', 'Original'])
        ->and(fn () => drafts()->renameVariant($page, 'Bee', 'original'))->toThrow(ValidationException::class)
        ->and(fn () => drafts()->renameVariant($page, 'Ghost', 'Spook'))->toThrow(DraftOperationFailed::class);

    drafts()->renameVariant($page, 'Bee', 'Bee');
    expect(drafts()->activeVariantName($page))->toBe('Bee');
});

it('deletes a parked variant but not the working draft', function () {
    $page = sitePage();
    drafts()->ensureDraft($page);
    drafts()->createVariant($page, 'B');

    expect(fn () => drafts()->deleteVariant($page, 'B'))->toThrow(ValidationException::class)
        ->and(fn () => drafts()->deleteVariant($page, 'Ghost'))->toThrow(DraftOperationFailed::class);

    drafts()->deleteVariant($page, 'Main');
    expect(drafts()->variantNames($page))->toBe(['B']);
});

it('seeds the next free Main name when Main is parked', function () {
    $page = sitePage();
    drafts()->ensureDraft($page);
    drafts()->createVariant($page, 'B');
    drafts()->discard($page);

    expect(drafts()->ensureDraft($page)->variant_name)->toBe('Main 2');
});

// ------------------------------------------------------------------ discard and stale

it('discards the working draft and its staged blocks, keeping parked variants', function () {
    $page = sitePage();
    drafts()->ensureDraft($page);
    drafts()->createVariant($page, 'B');

    drafts()->discard($page);

    expect(CmsPageDraft::count())->toBe(0)
        ->and($page->draftBlocks()->count())->toBe(0)
        ->and(CmsPageDraftVariant::count())->toBe(1)
        ->and(liveTitles($page))->toBe(['Top', 'Line']);
});

it('says a draft is stale once the page has been published since it began', function () {
    $page = sitePage();
    drafts()->ensureDraft($page);

    expect(drafts()->isStale($page))->toBeFalse();

    app(PageVersioning::class)->createSnapshot($page);
    expect(drafts()->isStale($page))->toBeTrue();

    expect(drafts()->isStale(sitePage(['slug' => 'other'])))->toBeFalse();
});

// ------------------------------------------------------------------ publish

it('publishes the draft: fields, tags, and blocks added, edited and removed; then clears the draft', function () {
    Event::fake([CmsPagePublished::class]);
    $page = sitePage(['status' => 'draft', 'published_at' => null]);
    $tag = CmsTag::create(['name' => 'Fresh', 'slug' => 'fresh']);
    $actor = TestUser::make();
    drafts()->saveDraftFields($page, ['title' => 'Published title', 'custom_route' => 'about-us', 'tag_ids' => [$tag->id]], $actor);
    $page->draftBlocks()->where('name', 'Top')->first()->update(['name' => 'Top edited', 'data' => ['title' => 'Changed']]);
    $page->draftBlocks()->where('name', 'Line')->delete();
    $page->draftBlocks()->create(['type' => 'image', 'name' => 'Added', 'sort_order' => 3, 'data' => ['asset_id' => 1]]);

    $version = drafts()->publish($page, $actor, 'Big update');
    $page->refresh();

    expect($page->title)->toBe('Published title')
        ->and($page->custom_route)->toBe('/about-us')
        ->and($page->status->value)->toBe('published')
        ->and($page->published_at)->not->toBeNull()
        ->and($page->tags->pluck('slug')->all())->toBe(['fresh'])
        ->and($page->blocks()->pluck('name')->all())->toBe(['Top edited', 'Added'])
        ->and($page->blocks()->first()->data)->toBe(['title' => 'Changed'])
        ->and($page->draftBlocks()->count())->toBe(0)
        ->and(CmsPageDraft::count())->toBe(0)
        ->and($version->change_summary)->toBe('Big update')
        ->and($version->version_number)->toBe(1)
        ->and($version->created_by_id)->toBe($actor->id)
        ->and(CmsBlock::count())->toBe(2);
    Event::assertDispatched(CmsPagePublished::class, fn (CmsPagePublished $e) => $e->page->is($page) && $e->version->is($version) && $e->actor?->is($actor));
});

it('keeps an existing publication time and stamps a future one', function () {
    $kept = sitePage(['slug' => 'kept', 'published_at' => now()->subYear()]);
    drafts()->saveDraftFields($kept, ['title' => 'x']);
    drafts()->publish($kept);

    $future = sitePage(['slug' => 'future', 'published_at' => now()->addWeek()]);
    drafts()->saveDraftFields($future, ['title' => 'x']);
    drafts()->publish($future);

    expect($kept->refresh()->published_at->isBefore(now()->subMonths(11)))->toBeTrue()
        ->and($future->refresh()->published_at->isFuture())->toBeFalse();
});

it('defaults the version summary to Published', function () {
    $page = sitePage();
    drafts()->saveDraftFields($page, ['title' => 'x']);

    expect(drafts()->publish($page)->change_summary)->toBe('Published');
});

it('records a snapshot of the page, its tags by name and its blocks in order', function () {
    $page = sitePage();
    drafts()->saveDraftFields($page, ['title' => 'Snap']);

    $snapshot = drafts()->publish($page)->snapshot;

    expect(array_keys($snapshot))->toBe(['page', 'tags', 'blocks'])
        ->and(array_keys($snapshot['page']))->toBe(['id', 'slug', 'title', 'excerpt', 'status', 'published_at', 'seo_title', 'seo_description'])
        ->and($snapshot['page']['title'])->toBe('Snap')
        ->and($snapshot['page']['status'])->toBe('published')
        ->and($snapshot['tags'][0]['slug'])->toBe('news')
        ->and(array_column($snapshot['blocks'], 'name'))->toBe(['Top', 'Line'])
        ->and(array_keys($snapshot['blocks'][0]))->toBe(['id', 'type', 'name', 'sort_order', 'data']);
});

it('numbers versions one after another per page', function () {
    $page = sitePage();
    $service = app(PageVersioning::class);

    expect($service->createSnapshot($page)->version_number)->toBe(1)
        ->and($service->createSnapshot($page, null, '  spaced  ')->change_summary)->toBe('spaced')
        ->and($service->createSnapshot($page)->version_number)->toBe(3)
        ->and($service->createSnapshot(sitePage(['slug' => 'other']))->version_number)->toBe(1);
});

it('refuses to publish when there is no draft', function () {
    try {
        drafts()->publish(sitePage());
        $this->fail('should have thrown');
    } catch (DraftOperationFailed $e) {
        expect($e->status)->toBe(409);
    }
});

it('refuses to publish a slug or custom route another page already uses, and changes nothing', function () {
    sitePage(['slug' => 'taken', 'custom_route' => '/taken']);
    $page = sitePage(['slug' => 'mine']);
    drafts()->saveDraftFields($page, ['slug' => 'taken', 'custom_route' => 'taken/', 'title' => 'Changed']);

    try {
        drafts()->publish($page);
        $this->fail('should have thrown');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['slug', 'custom_route']);
    }

    expect($page->refresh()->slug)->toBe('mine')
        ->and($page->title)->toBe('About')
        ->and(CmsPageDraft::where('cms_page_id', $page->id)->exists())->toBeTrue()
        ->and(CmsPageVersion::count())->toBe(0);
});

it('tells listeners a publish is under way, and stops saying so even after a failure', function () {
    $page = sitePage();
    drafts()->saveDraftFields($page, ['title' => 'x']);
    $seen = null;
    Event::listen('eloquent.saved: '.CmsPage::class, function () use (&$seen): void {
        $seen = PageDrafts::isPublishing();
    });

    drafts()->publish($page);

    expect($seen)->toBeTrue()
        ->and(PageDrafts::isPublishing())->toBeFalse();

    try {
        drafts()->publish($page);
    } catch (DraftOperationFailed) {
    }

    expect(PageDrafts::isPublishing())->toBeFalse();
});

// ------------------------------------------------------------------ restore

it('loads a recorded version into the draft, keeping the variant name and leaving live content alone', function () {
    $page = sitePage();
    drafts()->saveDraftFields($page, ['title' => 'Version one']);
    $one = drafts()->publish($page);
    drafts()->saveDraftFields($page, ['title' => 'Version two']);
    $page->draftBlocks()->delete();
    drafts()->publish($page);
    expect($page->blocks()->count())->toBe(0);
    drafts()->createVariant($page, 'Rework');

    $draft = drafts()->restoreVersionToDraft($page, $one, TestUser::make());

    expect($draft->variant_name)->toBe('Rework')
        ->and($draft->payload['page']['title'])->toBe('Version one')
        ->and($draft->is_dirty)->toBeTrue()
        ->and($draft->payload['tag_ids'])->toHaveCount(1)
        ->and($page->draftBlocks()->pluck('name')->all())->toBe(['Top', 'Line'])
        ->and($page->refresh()->title)->toBe('Version two')
        ->and($page->blocks()->count())->toBe(0);
});

it('refuses to restore another page\'s version', function () {
    $mine = sitePage();
    $other = sitePage(['slug' => 'other']);
    $foreign = app(PageVersioning::class)->createSnapshot($other);

    expect(fn () => drafts()->restoreVersionToDraft($mine, $foreign))->toThrow(DraftOperationFailed::class);
});

// ------------------------------------------------------------------ schedule

it('publishes drafts whose time has come, as the person who scheduled them', function () {
    Event::fake([CmsPagePublished::class]);
    $due = sitePage(['slug' => 'due']);
    $later = sitePage(['slug' => 'later']);
    $unscheduled = sitePage(['slug' => 'unscheduled']);
    $actor = TestUser::make();
    drafts()->saveDraftFields($due, ['title' => 'Due now']);
    drafts()->saveDraftFields($later, ['title' => 'Later']);
    drafts()->saveDraftFields($unscheduled, ['title' => 'Unscheduled']);
    drafts()->schedule($due, now()->subMinute(), $actor);
    drafts()->schedule($later, now()->addHour(), $actor, 'Soon');

    expect(drafts()->publishDue())->toBe(1)
        ->and($due->refresh()->title)->toBe('Due now')
        ->and($later->refresh()->title)->toBe('About')
        ->and($unscheduled->refresh()->title)->toBe('About')
        ->and($due->versions()->first()->change_summary)->toBe('Scheduled publish')
        ->and($due->versions()->first()->created_by_id)->toBe($actor->id)
        ->and(CmsPageDraft::where('cms_page_id', $later->id)->first()->scheduled_summary)->toBe('Soon');
});

it('uses the scheduled summary when there is one', function () {
    $page = sitePage();
    drafts()->saveDraftFields($page, ['title' => 'x']);
    drafts()->schedule($page, now()->subSecond(), null, 'Spring launch');

    drafts()->publishDue();

    expect($page->versions()->first()->change_summary)->toBe('Spring launch');
});

it('clears the schedule of a draft that can no longer be published and carries on with the rest', function () {
    sitePage(['slug' => 'taken']);
    $bad = sitePage(['slug' => 'bad']);
    $good = sitePage(['slug' => 'good']);
    drafts()->saveDraftFields($bad, ['slug' => 'taken']);
    drafts()->saveDraftFields($good, ['title' => 'Fine']);
    drafts()->schedule($bad, now()->subMinute());
    drafts()->schedule($good, now()->subMinute());

    expect(drafts()->publishDue())->toBe(1)
        ->and($good->refresh()->title)->toBe('Fine')
        ->and(CmsPageDraft::where('cms_page_id', $bad->id)->first()->scheduled_publish_at)->toBeNull();
});

it('lets the command publish what is due and say how many', function () {
    $page = sitePage();
    drafts()->saveDraftFields($page, ['title' => 'x']);
    drafts()->schedule($page, now()->subMinute());

    $this->artisan('forumkit:cms:publish-due')->expectsOutputToContain('Published 1 scheduled CMS page(s).')->assertExitCode(0);
    $this->artisan('forumkit:cms:publish-due')->expectsOutputToContain('Published 0 scheduled CMS page(s).')->assertExitCode(0);
});

it('cancels a schedule', function () {
    $page = sitePage();
    drafts()->schedule($page, now()->addDay(), TestUser::make(), 'Later');

    drafts()->cancelSchedule($page);

    $draft = CmsPageDraft::sole();
    expect($draft->scheduled_publish_at)->toBeNull()->and($draft->scheduled_summary)->toBeNull()->and($draft->scheduled_by_id)->toBeNull();
});

// ------------------------------------------------------------------ preview

it('previews the live page when there is no draft', function () {
    $page = sitePage();

    $preview = drafts()->previewPage($page);

    expect($preview->title)->toBe('About')
        ->and($preview->relationLoaded('blocks'))->toBeTrue()
        ->and($preview->blocks)->toHaveCount(2);
});

it('previews the draft in memory as published, without saving anything', function () {
    $page = sitePage(['status' => 'draft', 'published_at' => null]);
    drafts()->saveDraftFields($page, ['title' => 'Staged', 'tag_ids' => []]);
    $page->draftBlocks()->where('name', 'Line')->delete();

    $preview = drafts()->previewPage($page);

    expect($preview->title)->toBe('Staged')
        ->and($preview->id)->toBe($page->id)
        ->and($preview->status->value)->toBe('published')
        ->and($preview->published_at)->not->toBeNull()
        ->and($preview->blocks->pluck('name')->all())->toBe(['Top'])
        ->and($preview->tags)->toHaveCount(0)
        ->and($page->refresh()->title)->toBe('About')
        ->and($page->status->value)->toBe('draft')
        ->and(liveTitles($page))->toBe(['Top', 'Line']);
});

it('previews any variant by name, working or parked, and null for a stranger', function () {
    $page = sitePage();
    drafts()->saveDraftFields($page, ['title' => 'Draft A']);
    $page->draftBlocks()->where('name', 'Line')->delete();
    drafts()->createVariant($page, 'B', fromLive: true);
    drafts()->saveDraftFields($page, ['title' => 'Draft B']);

    $parked = drafts()->previewPageVariant($page, 'Main');
    $working = drafts()->previewPageVariant($page, 'B');

    expect($parked->title)->toBe('Draft A')
        ->and($parked->blocks->pluck('name')->all())->toBe(['Top'])
        ->and($working->title)->toBe('Draft B')
        ->and($working->blocks)->toHaveCount(2)
        ->and(drafts()->previewPageVariant($page, 'Nope'))->toBeNull();
});
