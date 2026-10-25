<?php

declare(strict_types=1);

use Filagora\Cms\Http\Middleware\MarkPreviewResponsesUncacheable;
use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Models\CmsPreviewShare;
use Filagora\Cms\Preview\PreviewSession;
use Filagora\Cms\Preview\PreviewShares;
use Filagora\Cms\Services\CmsContent;
use Filagora\Cms\Services\NavigationDrafts;
use Filagora\Cms\Services\PageDrafts;
use Filagora\Cms\Support\NoPreview;
use Filagora\Cms\Tests\Support\TestUser;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Entitlements\Registry\EntitlementRegistry;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/**
 * A request with a session, as the preview reads it.
 */
function browser(): Request
{
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);
    app()->forgetInstance(PreviewSession::class);
    app()->forgetInstance(CmsContent::class);

    return $request;
}

function previewSession(): PreviewSession
{
    return app(PreviewSession::class);
}

function editor(string ...$keys): TestUser
{
    $user = TestUser::make('Editor');

    foreach ($keys ?: ['filagora.cms.preview'] as $key) {
        UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => $key, 'grant_type' => 'manual']);
    }

    actingAs($user);

    return $user;
}

function previewPage(array $attributes = []): CmsPage
{
    $page = CmsPage::create(array_merge(['slug' => 'about', 'title' => 'About', 'status' => 'published', 'published_at' => now()->subDay()], $attributes))->refresh();
    $page->blocks()->create(['type' => 'hero', 'name' => 'Top', 'sort_order' => 1]);

    return $page;
}

function previewMenu(string $name = 'Main'): CmsNavigation
{
    return CmsNavigation::create(['name' => $name, 'items' => [['label' => 'Live', 'url' => '/live']]])->refresh();
}

// ------------------------------------------------------------------ nothing previewed

it('previews nothing for an ordinary visitor, without touching the database', function () {
    browser();
    previewPage();
    previewMenu();

    DB::enableQueryLog();

    expect(previewSession()->isActive())->toBeFalse()
        ->and(previewSession()->items())->toBe([])
        ->and(previewSession()->pageForSlug('about'))->toBeNull()
        ->and(previewSession()->pageForCustomRoute('/about'))->toBeNull()
        ->and(previewSession()->draftItemsFor('Main'))->toBeNull()
        ->and(previewSession()->isShared())->toBeFalse()
        ->and(previewSession()->sharedUntil())->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});

it('works without a session at all', function () {
    app()->instance('request', Request::create('/'));
    app()->forgetInstance(PreviewSession::class);

    expect(previewSession()->isActive())->toBeFalse();
    previewSession()->clear();
    previewSession()->remove('page', '1');
});

it('keeps a do-nothing resolver for hosts that want none', function () {
    $none = new NoPreview;

    expect($none->pageForSlug('x'))->toBeNull()->and($none->pageForCustomRoute('/x'))->toBeNull()->and($none->draftItemsFor('Main'))->toBeNull();
});

// ------------------------------------------------------------------ an editor's preview

it('serves an editor the working draft of the pages and menus they added, and nothing else', function () {
    browser();
    editor();
    $page = previewPage();
    $other = previewPage(['slug' => 'other', 'title' => 'Other']);
    $menu = previewMenu();
    app(PageDrafts::class)->saveDraftFields($page, ['title' => 'Staged about', 'slug' => 'about-new', 'custom_route' => '/about-us']);
    app(PageDrafts::class)->saveDraftFields($other, ['title' => 'Staged other']);
    app(NavigationDrafts::class)->saveDraft($menu, [['label' => 'Staged', 'url' => '/staged']]);

    previewSession()->addPage($page);
    previewSession()->addNavigation($menu);

    expect(previewSession()->isActive())->toBeTrue()
        ->and(previewSession()->isShared())->toBeFalse()
        ->and(previewSession()->pageForSlug('about-new')?->title)->toBe('Staged about')
        ->and(previewSession()->pageForSlug('about'))->toBeNull()
        ->and(previewSession()->pageForSlug('other'))->toBeNull()
        ->and(previewSession()->pageForCustomRoute('about-us/')?->title)->toBe('Staged about')
        ->and(array_column(previewSession()->draftItemsFor('Main'), 'label'))->toBe(['Staged'])
        ->and(previewSession()->draftItemsFor('Footer'))->toBeNull();
});

it('lets the readers serve the previewed page and menu through the real session', function () {
    browser();
    editor();
    $page = previewPage();
    $menu = previewMenu();
    app(PageDrafts::class)->saveDraftFields($page, ['title' => 'Staged about']);
    app(NavigationDrafts::class)->saveDraft($menu, [['label' => 'Staged', 'url' => '/staged']]);

    expect(app(CmsContent::class)->publishedPageBySlug('about')->title)->toBe('About')
        ->and(array_column(app(CmsContent::class)->navigationItems('Main'), 'label'))->toBe(['Live']);

    previewSession()->addPage($page);
    previewSession()->addNavigation($menu);
    app()->forgetInstance(CmsContent::class);

    expect(app(CmsContent::class)->publishedPageBySlug('about')->title)->toBe('Staged about')
        ->and(array_column(app(CmsContent::class)->navigationItems('Main'), 'label'))->toBe(['Staged']);
});

it('does not preview for someone who may not, even with a preview stored in their session', function () {
    browser();
    $page = previewPage();
    app(PageDrafts::class)->saveDraftFields($page, ['title' => 'Staged']);
    request()->session()->put(PreviewSession::SESSION_KEY, ['navigations' => [], 'pages' => [$page->id => true]]);

    actingAs(TestUser::make('Nobody'));
    expect(previewSession()->isActive())->toBeFalse()
        ->and(previewSession()->pageForSlug('about'))->toBeNull();

    editor('filagora.cms.manage');
    expect(previewSession()->isActive())->toBeTrue();
});

it('lists what is being previewed, with variants an editor can switch between', function () {
    browser();
    editor();
    $page = previewPage();
    $menu = previewMenu('Footer');
    app(PageDrafts::class)->createVariant($page, 'Spring');
    app(NavigationDrafts::class)->ensureDraft($menu);
    previewSession()->addPage($page);
    previewSession()->addNavigation($menu);

    $items = previewSession()->items();

    expect($items)->toHaveCount(2)
        ->and($items[0])->toBe(['type' => 'menu', 'key' => 'Footer', 'label' => 'Footer menu', 'active' => 'Main', 'variants' => ['Main']])
        ->and($items[1])->toBe(['type' => 'page', 'key' => (string) $page->id, 'label' => 'About', 'active' => 'Spring', 'variants' => ['Spring']]);
});

it('takes an item out of the preview and leaves it entirely', function () {
    browser();
    editor();
    $page = previewPage();
    $menu = previewMenu();
    previewSession()->addPage($page);
    previewSession()->addNavigation($menu);

    previewSession()->remove('menu', 'Main');
    expect(previewSession()->draftItemsFor('Main'))->toBeNull()
        ->and(previewSession()->isActive())->toBeTrue();

    previewSession()->remove('page', (string) $page->id);
    expect(previewSession()->isActive())->toBeFalse();

    previewSession()->addPage($page);
    previewSession()->clear();
    expect(previewSession()->isActive())->toBeFalse();
});

it('bundles the editor\'s own preview at each item\'s working variant, for sharing', function () {
    browser();
    editor();
    $page = previewPage();
    $menu = previewMenu();
    $noDraft = previewPage(['slug' => 'nodraft']);
    app(PageDrafts::class)->ensureDraft($page, null, 'Spring');
    app(NavigationDrafts::class)->ensureDraft($menu);
    previewSession()->addPage($page);
    previewSession()->addPage($noDraft);
    previewSession()->addNavigation($menu);

    expect(previewSession()->currentSelection())->toBe(['navigations' => ['Main' => 'Main'], 'pages' => [$page->id => 'Spring']]);

    actingAs(TestUser::make('Nobody'));
    expect(previewSession()->currentSelection())->toBe(['navigations' => [], 'pages' => []]);
});

// ------------------------------------------------------------------ shared links

it('creates a link pinned to named variants, with a long random token that is never serialised', function () {
    $actor = TestUser::make();
    $page = previewPage();
    $menu = previewMenu();
    app(PageDrafts::class)->ensureDraft($page);
    app(NavigationDrafts::class)->ensureDraft($menu);

    $share = app(PreviewShares::class)->create($actor, ['Main' => 'Main'], [$page->id => 'Main'], 7, '  For the board  ')->refresh();

    expect(strlen($share->getRawOriginal('token')))->toBe(48)
        ->and($share->toArray())->not->toHaveKey('token')
        ->and($share->label)->toBe('For the board')
        ->and($share->created_by_id)->toBe($actor->id)
        ->and($share->navigations())->toBe(['Main' => 'Main'])
        ->and($share->pages())->toBe([$page->id => 'Main'])
        ->and((int) round(now()->diffInDays($share->expires_at)))->toBe(7)
        ->and($share->open_count)->toBe(0)
        ->and(app(PreviewShares::class)->create($actor, ['Main' => 'Main'], [], 0, '   ')->label)->toBeNull()
        ->and(CmsPreviewShare::latest('id')->first()->expires_at->isFuture())->toBeTrue();
});

it('refuses an empty share, and a variant that does not exist', function () {
    $actor = TestUser::make();
    $page = previewPage();
    $menu = previewMenu();
    app(PageDrafts::class)->ensureDraft($page);
    $shares = app(PreviewShares::class);

    expect(fn () => $shares->create($actor, [], [], 3))->toThrow(ValidationException::class)
        ->and(fn () => $shares->create($actor, ['Main' => 'Main'], [], 3))->toThrow(ValidationException::class)
        ->and(fn () => $shares->create($actor, ['Ghost' => 'Main'], [], 3))->toThrow(ValidationException::class)
        ->and(fn () => $shares->create($actor, [], [$page->id => 'Nope'], 3))->toThrow(ValidationException::class)
        ->and(fn () => $shares->create($actor, [], [99999 => 'Main'], 3))->toThrow(ValidationException::class)
        ->and(CmsPreviewShare::count())->toBe(0);
});

function sharedLink(?TestUser $actor = null): array
{
    $actor ??= TestUser::make();
    $page = previewPage();
    $menu = previewMenu();
    $drafts = app(PageDrafts::class);
    $drafts->saveDraftFields($page, ['title' => 'Main draft']);
    $drafts->createVariant($page, 'Spring');
    $drafts->saveDraftFields($page, ['title' => 'Spring draft', 'slug' => 'spring']);
    app(NavigationDrafts::class)->saveDraft($menu, [['label' => 'Staged', 'url' => '/staged']]);

    $share = app(PreviewShares::class)->create($actor, ['Main' => 'Main'], [$page->id => 'Main'], 3);

    return [$share, $page, $menu];
}

it('opens a link: starts the preview for whoever holds it, counts the open, leaves updated_at alone', function () {
    [$share, $page] = sharedLink();
    $stamp = $share->refresh()->updated_at;
    $token = $share->getRawOriginal('token');
    $this->travel(5)->minutes();
    browser();

    $opened = app(PreviewShares::class)->open($token, previewSession());
    $fresh = $share->refresh();

    expect($opened?->id)->toBe($share->id)
        ->and($fresh->open_count)->toBe(1)
        ->and($fresh->last_opened_at)->not->toBeNull()
        ->and($fresh->updated_at->equalTo($stamp))->toBeTrue()
        ->and(previewSession()->isActive())->toBeTrue()
        ->and(previewSession()->isShared())->toBeTrue()
        ->and(previewSession()->sharedUntil()->getTimestamp())->toBe($share->expires_at->getTimestamp());

    app(PreviewShares::class)->open($token, previewSession());
    expect($share->refresh()->open_count)->toBe(2);
});

it('shows a link\'s holder the pinned variants, not the current working draft', function () {
    [$share, $page] = sharedLink();
    browser();
    app(PreviewShares::class)->open($share->getRawOriginal('token'), previewSession());

    $viaShare = previewSession()->pageForSlug('about');

    expect($viaShare?->title)->toBe('Main draft')
        ->and(previewSession()->pageForSlug('spring'))->toBeNull()
        ->and(array_column(previewSession()->draftItemsFor('Main'), 'label'))->toBe(['Staged'])
        ->and(previewSession()->items())->toBe([
            ['type' => 'menu', 'key' => 'Main', 'label' => 'Main menu', 'active' => 'Main', 'variants' => []],
            ['type' => 'page', 'key' => (string) $page->id, 'label' => 'About', 'active' => 'Main', 'variants' => []],
        ]);
});

it('ends a link the moment it is revoked or expires', function () {
    [$share] = sharedLink();
    browser();
    app(PreviewShares::class)->open($share->getRawOriginal('token'), previewSession());
    expect(previewSession()->isActive())->toBeTrue();

    app(PreviewShares::class)->revoke($share);
    $revokedAt = $share->refresh()->revoked_at;
    app(PreviewShares::class)->revoke($share);
    browser();
    request()->session()->put(PreviewSession::SHARE_SESSION_KEY, $share->id);

    expect(previewSession()->isActive())->toBeFalse()
        ->and($share->refresh()->revoked_at->equalTo($revokedAt))->toBeTrue()
        ->and(app(PreviewShares::class)->open($share->getRawOriginal('token'), previewSession()))->toBeNull();

    $expiring = app(PreviewShares::class)->create(TestUser::make(), ['Main' => 'Main'], [], 1);
    $expiring->update(['expires_at' => now()->subSecond()]);
    expect(app(PreviewShares::class)->open($expiring->getRawOriginal('token'), previewSession()))->toBeNull()
        ->and(app(PreviewShares::class)->open('no-such-token', previewSession()))->toBeNull();
});

it('looks the link up once per request however often it is asked', function () {
    [$share] = sharedLink();
    browser();
    app(PreviewShares::class)->open($share->getRawOriginal('token'), previewSession());
    DB::enableQueryLog();

    previewSession()->isActive();
    previewSession()->isActive();
    previewSession()->isShared();

    $lookups = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'filagora_cms_preview_shares'))->count();
    expect($lookups)->toBe(1);
});

it('lets an editor\'s own preview win over a shared link in the same browser', function () {
    [$share, $page] = sharedLink();
    browser();
    app(PreviewShares::class)->open($share->getRawOriginal('token'), previewSession());
    editor();
    previewSession()->addPage($page);

    expect(previewSession()->isShared())->toBeFalse()
        ->and(previewSession()->pageForSlug('spring')?->title)->toBe('Spring draft');
});

it('says where a visitor to a link should land: the first shared page that still has its variant', function () {
    [$share, $page] = sharedLink();
    $shares = app(PreviewShares::class);

    expect($shares->landingPage($share)?->title)->toBe('Main draft');

    $menuOnly = $shares->create(TestUser::make(), ['Main' => 'Main'], [], 1);
    expect($shares->landingPage($menuOnly))->toBeNull();

    app(PageDrafts::class)->renameVariant($page, 'Main', 'Gone');
    expect($shares->landingPage($share))->toBeNull();
});

it('finds only usable links by token', function () {
    [$share] = sharedLink();
    $token = $share->getRawOriginal('token');

    expect(app(PreviewShares::class)->findUsable($token)?->id)->toBe($share->id)
        ->and(app(PreviewShares::class)->findUsable('x'))->toBeNull();

    $share->update(['revoked_at' => now()]);
    expect(app(PreviewShares::class)->findUsable($token))->toBeNull();
});

// ------------------------------------------------------------------ middleware and abilities

it('marks responses uncacheable and noindex only while a preview is in effect', function () {
    browser();
    editor();
    $page = previewPage();
    $middleware = new MarkPreviewResponsesUncacheable(previewSession());
    $next = fn () => new Response('ok');

    $quiet = $middleware->handle(Request::create('/'), $next);
    expect($quiet->headers->has('X-Robots-Tag'))->toBeFalse();

    previewSession()->addPage($page);
    $marked = $middleware->handle(Request::create('/'), $next);

    expect($marked->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($marked->headers->get('Cache-Control'))->toContain('no-store')
        ->and($marked->headers->get('Cache-Control'))->toContain('private');
});

it('puts that middleware in the web group, unless switched off', function () {
    $groups = app(Router::class)->getMiddlewareGroups();

    expect($groups['web'] ?? [])->toContain(MarkPreviewResponsesUncacheable::class)
        ->and(config('filagora-cms.preview.uncacheable_middleware'))->toBeTrue();
});

it('keeps that middleware when the kernel syncs its own groups to the router', function () {
    app(HttpKernel::class)->appendMiddlewareToGroup('web', 'unrelated');

    expect(app(Router::class)->getMiddlewareGroups()['web'] ?? [])->toContain(MarkPreviewResponsesUncacheable::class);
});

it('declares the two abilities as entitlements and gates them', function () {
    $keys = array_map(fn ($definition) => $definition->key, array_values(app(EntitlementRegistry::class)->definitions()));

    expect($keys)->toContain('filagora.cms.manage', 'filagora.cms.preview');

    $user = editor('filagora.cms.preview');
    expect(Gate::forUser($user)->allows('filagora.cms.preview'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('filagora.cms.manage'))->toBeFalse();
});
