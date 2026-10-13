<?php

declare(strict_types=1);

use Filagora\Cms\Events\CmsNavigationPublished;
use Filagora\Cms\Exceptions\DraftOperationFailed;
use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Models\CmsNavigationDraft;
use Filagora\Cms\Models\CmsNavigationDraftVariant;
use Filagora\Cms\Models\CmsNavigationVersion;
use Filagora\Cms\Services\CmsContent;
use Filagora\Cms\Services\NavigationDraftDiff;
use Filagora\Cms\Services\NavigationDrafts;
use Filagora\Cms\Tests\Support\TestUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

function menus(): NavigationDrafts
{
    return app(NavigationDrafts::class);
}

function siteMenu(string $name = 'Main'): CmsNavigation
{
    return CmsNavigation::create(['name' => $name, 'items' => [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'Support', 'url' => '#', 'children' => [['label' => 'Donate', 'url' => '/donate']]],
    ]])->refresh();
}

function labels(array $items): array
{
    return array_column($items, 'label');
}

// ------------------------------------------------------------------ seeding and editing

it('seeds the working draft from the live items, once, and names it Main', function () {
    $menu = siteMenu();
    $actor = TestUser::make();

    $draft = menus()->ensureDraft($menu, $actor);

    expect($draft->variant_name)->toBe('Main')
        ->and($draft->is_dirty)->toBeFalse()
        ->and($draft->updated_by_id)->toBe($actor->id)
        ->and($draft->base_version_id)->toBeNull()
        ->and(labels($draft->items()))->toBe(['Home', 'Support'])
        ->and(menus()->ensureDraft($menu)->id)->toBe($draft->id)
        ->and(CmsNavigationDraft::count())->toBe(1);
});

it('records the latest version as the draft\'s base', function () {
    $menu = siteMenu();
    menus()->saveDraft($menu, [['label' => 'One', 'url' => '/1']]);
    $version = menus()->publish($menu);
    menus()->discard($menu);

    expect(menus()->ensureDraft($menu)->base_version_id)->toBe($version->id);
});

it('saves edited items into the draft only, renumbering them, and leaves the live menu alone', function () {
    $menu = siteMenu();

    $draft = menus()->saveDraft($menu, [5 => ['label' => 'A', 'url' => '/a'], 9 => ['label' => 'B', 'url' => '/b']]);

    expect($draft->is_dirty)->toBeTrue()
        ->and(labels($draft->items()))->toBe(['A', 'B'])
        ->and(labels(menus()->editorItems($menu)))->toBe(['A', 'B'])
        ->and(labels($menu->refresh()->items))->toBe(['Home', 'Support']);
});

// ------------------------------------------------------------------ variants

it('keeps the current draft as a parked variant when a new one starts from a copy of it', function () {
    $menu = siteMenu();
    menus()->saveDraft($menu, [['label' => 'Draft A', 'url' => '/a']]);

    $b = menus()->createVariant($menu, 'B');
    menus()->saveDraft($menu, [['label' => 'Draft B', 'url' => '/b']]);

    expect($b->variant_name)->toBe('B')
        ->and(menus()->variantNames($menu))->toBe(['B', 'Main'])
        ->and(CmsNavigationDraftVariant::sole()->name)->toBe('Main')
        ->and(labels(CmsNavigationDraftVariant::sole()->snapshot['payload']['items']))->toBe(['Draft A'])
        ->and(labels(CmsNavigationDraft::sole()->items()))->toBe(['Draft B']);
});

it('starts a variant from the live menu when asked, parking the current draft', function () {
    $menu = siteMenu();
    menus()->saveDraft($menu, [['label' => 'Edited', 'url' => '/e']]);

    $fresh = menus()->createVariant($menu, 'Fresh', fromLive: true);

    expect(labels($fresh->items()))->toBe(['Home', 'Support'])
        ->and(CmsNavigationDraftVariant::sole()->name)->toBe('Main')
        ->and(labels(menus()->itemsForVariant($menu, 'Main')))->toBe(['Edited']);
});

it('creates a first variant when there is no draft yet', function () {
    expect(menus()->createVariant(siteMenu(), 'Solo')->variant_name)->toBe('Solo');
});

it('swaps a parked variant in and parks the working draft in its place', function () {
    $menu = siteMenu();
    menus()->saveDraft($menu, [['label' => 'Draft A', 'url' => '/a']]);
    menus()->createVariant($menu, 'B', fromLive: true);
    menus()->saveDraft($menu, [['label' => 'Draft B', 'url' => '/b']]);

    $main = menus()->switchVariant($menu, 'Main');

    expect(labels($main->items()))->toBe(['Draft A'])
        ->and($main->is_dirty)->toBeTrue()
        ->and(menus()->variantNames($menu))->toBe(['Main', 'B'])
        ->and(labels(menus()->itemsForVariant($menu, 'B')))->toBe(['Draft B'])
        ->and(menus()->switchVariant($menu, 'Main')->id)->toBe($main->id);
});

it('refuses to switch away from, or branch, a scheduled draft', function () {
    $menu = siteMenu();
    menus()->ensureDraft($menu);
    menus()->createVariant($menu, 'B');
    menus()->schedule($menu, now()->addDay());

    expect(fn () => menus()->switchVariant($menu, 'Main'))->toThrow(ValidationException::class)
        ->and(fn () => menus()->createVariant($menu, 'C'))->toThrow(ValidationException::class);

    menus()->cancelSchedule($menu);
    expect(menus()->switchVariant($menu, 'Main')->variant_name)->toBe('Main');
});

it('refuses a variant that does not exist', function () {
    $menu = siteMenu();
    menus()->ensureDraft($menu);

    try {
        menus()->switchVariant($menu, 'Nope');
        $this->fail('should have thrown');
    } catch (DraftOperationFailed $e) {
        expect($e->status)->toBe(404);
    }
});

it('checks variant names, renames, and deletes only parked variants', function () {
    $menu = siteMenu();
    menus()->ensureDraft($menu);

    foreach (['', '  ', str_repeat('x', 101), 'MAIN'] as $bad) {
        expect(fn () => menus()->createVariant($menu, $bad))->toThrow(ValidationException::class);
    }

    menus()->createVariant($menu, 'B');
    menus()->renameVariant($menu, 'B', 'Bee');
    menus()->renameVariant($menu, 'Main', 'Original');

    expect(menus()->variantNames($menu))->toBe(['Bee', 'Original'])
        ->and(fn () => menus()->renameVariant($menu, 'Bee', 'original'))->toThrow(ValidationException::class)
        ->and(fn () => menus()->renameVariant($menu, 'Ghost', 'Spook'))->toThrow(DraftOperationFailed::class)
        ->and(fn () => menus()->deleteVariant($menu, 'Bee'))->toThrow(ValidationException::class)
        ->and(fn () => menus()->deleteVariant($menu, 'Ghost'))->toThrow(DraftOperationFailed::class);

    menus()->deleteVariant($menu, 'Original');
    expect(menus()->variantNames($menu))->toBe(['Bee']);
});

it('returns the items of any variant by name and null for a stranger', function () {
    $menu = siteMenu();
    menus()->saveDraft($menu, [['label' => 'A', 'url' => '/a']]);
    menus()->createVariant($menu, 'B');
    menus()->saveDraft($menu, [['label' => 'B1', 'url' => '/b']]);

    expect(labels(menus()->itemsForVariant($menu, 'B')))->toBe(['B1'])
        ->and(labels(menus()->itemsForVariant($menu, 'Main')))->toBe(['A'])
        ->and(menus()->itemsForVariant($menu, 'Nope'))->toBeNull();
});

// ------------------------------------------------------------------ discard, stale, restore

it('discards the working draft and keeps the parked variants', function () {
    $menu = siteMenu();
    menus()->ensureDraft($menu);
    menus()->createVariant($menu, 'B');

    menus()->discard($menu);

    expect(CmsNavigationDraft::count())->toBe(0)
        ->and(CmsNavigationDraftVariant::count())->toBe(1);
});

it('says a draft is stale once the menu was published since it began', function () {
    $menu = siteMenu();
    menus()->ensureDraft($menu);

    expect(menus()->isStale($menu))->toBeFalse();

    CmsNavigationVersion::create(['cms_navigation_id' => $menu->id, 'version_number' => 1, 'snapshot' => []]);
    expect(menus()->isStale($menu))->toBeTrue();
});

it('loads a recorded version into the draft, keeping the variant name and the live menu', function () {
    $menu = siteMenu();
    menus()->saveDraft($menu, [['label' => 'V1', 'url' => '/1']]);
    $one = menus()->publish($menu);
    menus()->saveDraft($menu, [['label' => 'V2', 'url' => '/2']]);
    menus()->publish($menu);
    menus()->ensureDraft($menu->refresh(), null, 'Rework');

    $draft = menus()->restoreVersionToDraft($menu, $one);

    expect($draft->variant_name)->toBe('Rework')
        ->and(labels($draft->items()))->toBe(['V1'])
        ->and($draft->is_dirty)->toBeTrue()
        ->and(labels($menu->refresh()->items))->toBe(['V2']);

    $other = siteMenu('Other');
    $foreign = CmsNavigationVersion::create(['cms_navigation_id' => $other->id, 'version_number' => 1, 'snapshot' => ['items' => []]]);
    expect(fn () => menus()->restoreVersionToDraft($menu, $foreign))->toThrow(DraftOperationFailed::class);
});

// ------------------------------------------------------------------ publish

it('publishes the draft onto the live menu, records a version with name and items, and announces it', function () {
    Event::fake([CmsNavigationPublished::class]);
    $menu = siteMenu();
    $actor = TestUser::make();
    menus()->saveDraft($menu, [['label' => 'New', 'url' => '/new']], $actor);

    $version = menus()->publish($menu, $actor, 'Rework');

    expect(labels($menu->refresh()->items))->toBe(['New'])
        ->and(CmsNavigationDraft::count())->toBe(0)
        ->and($version->version_number)->toBe(1)
        ->and($version->change_summary)->toBe('Rework')
        ->and($version->created_by_id)->toBe($actor->id)
        ->and(array_keys($version->snapshot))->toBe(['name', 'items'])
        ->and($version->snapshot['name'])->toBe('Main')
        ->and(labels($version->snapshot['items']))->toBe(['New']);
    Event::assertDispatched(CmsNavigationPublished::class, fn (CmsNavigationPublished $e) => $e->navigation->is($menu) && $e->version->is($version) && $e->actor?->is($actor));

    menus()->saveDraft($menu, [['label' => 'Newer', 'url' => '/n']]);
    expect(menus()->publish($menu)->change_summary)->toBe('Published')
        ->and($menu->versions()->count())->toBe(2);
});

it('clears the menu\'s cached items when it is published, so readers see the change', function () {
    $menu = siteMenu();
    app()->forgetInstance(CmsContent::class);
    expect(labels(app(CmsContent::class)->navigationItems('Main')))->toBe(['Home', 'Support']);
    expect(Cache::has('cms:navigation:Main'))->toBeTrue();

    menus()->saveDraft($menu, [['label' => 'After', 'url' => '/after']]);
    menus()->publish($menu);

    app()->forgetInstance(CmsContent::class);
    expect(labels(app(CmsContent::class)->navigationItems('Main')))->toBe(['After']);
});

it('refuses to publish when there is no draft', function () {
    try {
        menus()->publish(siteMenu());
        $this->fail('should have thrown');
    } catch (DraftOperationFailed $e) {
        expect($e->status)->toBe(409);
    }
});

// ------------------------------------------------------------------ schedule

it('publishes the menus whose time has come and only those, as the person who scheduled them', function () {
    $due = siteMenu('Due');
    $later = siteMenu('Later');
    $actor = TestUser::make();
    menus()->saveDraft($due, [['label' => 'Due now', 'url' => '/d']]);
    menus()->saveDraft($later, [['label' => 'Later', 'url' => '/l']]);
    menus()->schedule($due, now()->subMinute(), $actor);
    menus()->schedule($later, now()->addHour(), $actor, 'Soon');

    expect(menus()->publishDue())->toBe(1)
        ->and(labels($due->refresh()->items))->toBe(['Due now'])
        ->and(labels($later->refresh()->items))->toBe(['Home', 'Support'])
        ->and($due->versions()->first()->change_summary)->toBe('Scheduled publish')
        ->and($due->versions()->first()->created_by_id)->toBe($actor->id)
        ->and(CmsNavigationDraft::where('cms_navigation_id', $later->id)->first()->scheduled_summary)->toBe('Soon');
});

it('does not let one failing menu stop the others', function () {
    $bad = siteMenu('Bad');
    $good = siteMenu('Good');
    menus()->saveDraft($bad, [['label' => 'Bad', 'url' => '/b']]);
    menus()->saveDraft($good, [['label' => 'Good', 'url' => '/g']]);
    menus()->schedule($bad, now()->subMinutes(2));
    menus()->schedule($good, now()->subMinute());
    Event::listen(CmsNavigationPublished::class, function (CmsNavigationPublished $event): void {
        if ($event->navigation->name === 'Bad') {
            throw new RuntimeException('boom');
        }
    });

    expect(menus()->publishDue())->toBe(1)
        ->and(labels($good->refresh()->items))->toBe(['Good']);
});

it('cancels a schedule', function () {
    $menu = siteMenu();
    menus()->schedule($menu, now()->addDay(), TestUser::make(), 'Later');

    menus()->cancelSchedule($menu);

    $draft = CmsNavigationDraft::sole();
    expect($draft->scheduled_publish_at)->toBeNull()->and($draft->scheduled_summary)->toBeNull()->and($draft->scheduled_by_id)->toBeNull();
});

it('lets the command publish due pages and menus and say how many of each', function () {
    $menu = siteMenu();
    menus()->saveDraft($menu, [['label' => 'x', 'url' => '/x']]);
    menus()->schedule($menu, now()->subMinute());

    $this->artisan('filagora:cms:publish-due')
        ->expectsOutputToContain('Published 0 scheduled CMS page(s).')
        ->expectsOutputToContain('Published 1 scheduled navigation menu(s).')
        ->assertExitCode(0);
});

// ------------------------------------------------------------------ the diff

it('reports nothing without a draft, or when the draft matches live', function () {
    $menu = siteMenu();
    $diff = app(NavigationDraftDiff::class);

    expect($diff->forNavigation($menu))->toBe(['changes' => [], 'count' => 0]);

    menus()->ensureDraft($menu);
    expect($diff->forNavigation($menu)['count'])->toBe(0);
});

it('lists items added, changed (saying what) and removed, matched by label path', function () {
    $menu = siteMenu();
    menus()->saveDraft($menu, [
        ['label' => 'Support', 'url' => '#', 'entitlements' => ['b', 'a'], 'target' => '_blank', 'children' => [['label' => 'Donate', 'url' => '/give'], ['label' => 'Help', 'url' => '/help']]],
        ['label' => 'Blog', 'url' => '/blog'],
    ]);

    $diff = app(NavigationDraftDiff::class)->forNavigation($menu);

    expect($diff['changes'])->toBe([
        ['status' => 'Changed', 'label' => 'Support', 'details' => ['target: _self → _blank', 'visible for: (none) → a, b', 'position 2 → 1']],
        ['status' => 'Changed', 'label' => 'Support › Donate', 'details' => ['URL: /donate → /give']],
        ['status' => 'Added', 'label' => 'Support › Help', 'details' => []],
        ['status' => 'Added', 'label' => 'Blog', 'details' => []],
        ['status' => 'Removed', 'label' => 'Home', 'details' => []],
    ])->and($diff['count'])->toBe(5);
});

it('tells two items with the same label under one parent apart, and names an untitled one', function () {
    $menu = CmsNavigation::create(['name' => 'Dupes', 'items' => [['label' => 'Same', 'url' => '/1']]]);
    menus()->saveDraft($menu, [['label' => 'Same', 'url' => '/1'], ['label' => 'Same', 'url' => '/2'], ['label' => ' ', 'url' => '/3']]);

    $labels = array_column(app(NavigationDraftDiff::class)->forNavigation($menu)['changes'], 'label');

    expect($labels)->toBe(['Same #2', '(untitled)']);
});
