<?php

declare(strict_types=1);

use Filagora\Cms\Http\Middleware\CarryPreviewToken;
use Filagora\Cms\Http\Middleware\MarkPreviewResponsesUncacheable;
use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Models\CmsPreviewShare;
use Filagora\Cms\Preview\PreviewSession;
use Filagora\Cms\Preview\PreviewShares;
use Filagora\Cms\Preview\PreviewUrl;
use Filagora\Cms\Services\CmsContent;
use Filagora\Cms\Services\NavigationDrafts;
use Filagora\Cms\Services\PageDrafts;
use Filagora\Cms\Support\NoPreview;
use Filagora\Cms\Tests\Support\TestUser;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Entitlements\Registry\EntitlementRegistry;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/**
 * The request the resolver reads: a preview is carried by the address alone.
 */
function requestAt(string $uri = '/'): Request
{
    $request = Request::create($uri);
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

function tokenOf(CmsPreviewShare $share): string
{
    return $share->getRawOriginal('token');
}

// ------------------------------------------------------------------ nothing previewed

it('previews nothing for an ordinary request, without touching the database', function () {
    requestAt('/about?page=2');
    previewPage();
    previewMenu();

    DB::enableQueryLog();

    expect(previewSession()->isActive())->toBeFalse()
        ->and(previewSession()->token())->toBeNull()
        ->and(previewSession()->pageForSlug('about'))->toBeNull()
        ->and(previewSession()->pageForCustomRoute('/about'))->toBeNull()
        ->and(previewSession()->draftItemsFor('Main'))->toBeNull()
        ->and(previewSession()->items())->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

it('works with no session or cookie at all', function () {
    [$share] = sharedLink();
    $request = requestAt('/about?preview='.tokenOf($share));

    expect($request->hasSession())->toBeFalse()
        ->and(previewSession()->isActive())->toBeTrue();
});

it('keeps a do-nothing resolver for hosts that want none', function () {
    $none = new NoPreview;

    expect($none->pageForSlug('x'))->toBeNull()->and($none->pageForCustomRoute('/x'))->toBeNull()->and($none->draftItemsFor('Main'))->toBeNull();
});

// ------------------------------------------------------------------ links

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

it('shows the pinned drafts for a token in the address, and the live site without it', function () {
    [$share, $page] = sharedLink();

    requestAt('/about?preview='.tokenOf($share));
    expect(previewSession()->isActive())->toBeTrue()
        ->and(previewSession()->token())->toBe(tokenOf($share))
        ->and(previewSession()->expiresAt()?->getTimestamp())->toBe($share->expires_at->getTimestamp())
        ->and(previewSession()->pageForSlug('about')?->title)->toBe('Main draft')
        ->and(previewSession()->pageForSlug('spring'))->toBeNull()
        ->and(previewSession()->draftItemsFor('Main'))->toBe([['label' => 'Staged', 'url' => '/staged']])
        ->and(previewSession()->items())->toBe([
            ['type' => 'menu', 'key' => 'Main', 'label' => 'Main menu', 'active' => 'Main'],
            ['type' => 'page', 'key' => (string) $page->id, 'label' => 'About', 'active' => 'Main'],
        ]);

    requestAt('/about');
    expect(previewSession()->isActive())->toBeFalse()
        ->and(previewSession()->pageForSlug('about'))->toBeNull()
        ->and(previewSession()->draftItemsFor('Main'))->toBeNull();
});

it('keeps a pinned variant pinned while editors switch the working draft', function () {
    [$share, $page] = sharedLink();
    app(PageDrafts::class)->switchVariant($page, 'Spring');

    requestAt('/about?preview='.tokenOf($share));

    expect(previewSession()->pageForSlug('about')?->title)->toBe('Main draft');
});

it('serves the live site for an unknown, revoked or expired token, or one that cannot be a token', function () {
    [$share] = sharedLink();
    $token = tokenOf($share);

    foreach (['/about?preview=not-a-real-token', '/about?preview='.str_repeat('a', 500), '/about?preview[]='.$token, '/about?preview='] as $uri) {
        requestAt($uri);
        expect(previewSession()->isActive())->toBeFalse();
    }

    $this->travel(4)->days();
    requestAt('/about?preview='.$token);
    expect(previewSession()->isActive())->toBeFalse();

    $this->travelBack();
    app(PreviewShares::class)->revoke($share);
    requestAt('/about?preview='.$token);
    expect(previewSession()->isActive())->toBeFalse();
});

it('looks a token up once per request however often it is asked', function () {
    [$share] = sharedLink();
    requestAt('/about?preview='.tokenOf($share));

    DB::enableQueryLog();
    previewSession()->isActive();
    previewSession()->isActive();
    previewSession()->pageForSlug('spring');
    previewSession()->token();

    $lookups = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'filagora_cms_preview_shares'))->count();
    expect($lookups)->toBe(1);
});

it('uses the configured query parameter', function () {
    config(['filagora-cms.preview.query_parameter' => 'draft']);
    [$share] = sharedLink();

    requestAt('/about?preview='.tokenOf($share));
    expect(previewSession()->isActive())->toBeFalse();

    requestAt('/about?draft='.tokenOf($share));
    expect(previewSession()->isActive())->toBeTrue();
});

// ------------------------------------------------------------------ an editor's link to the working draft

it('makes a link to the working draft that follows the editor\'s changes, and reuses it', function () {
    $actor = TestUser::make();
    $page = previewPage();
    $menu = previewMenu();
    $drafts = app(PageDrafts::class);
    $drafts->saveDraftFields($page, ['title' => 'First edit']);
    app(NavigationDrafts::class)->saveDraft($menu, [['label' => 'Staged', 'url' => '/staged']]);

    $share = app(PreviewShares::class)->workingDraft($actor, ['Main'], [$page->id]);

    expect($share->label)->toBe('Editor preview')
        ->and($share->created_by_id)->toBe($actor->id)
        ->and($share->pages())->toBe([$page->id => true])
        ->and($share->navigations())->toBe(['Main' => true])
        ->and($share->expires_at->isFuture())->toBeTrue()
        ->and(app(PreviewShares::class)->workingDraft($actor, ['Main'], [$page->id])->id)->toBe($share->id)
        ->and(app(PreviewShares::class)->workingDraft($actor, [], [$page->id])->id)->not->toBe($share->id)
        ->and(app(PreviewShares::class)->workingDraft(TestUser::make('Other'), ['Main'], [$page->id])->id)->not->toBe($share->id);

    requestAt('/about?preview='.tokenOf($share));
    expect(previewSession()->pageForSlug('about')?->title)->toBe('First edit');

    $drafts->saveDraftFields($page, ['title' => 'Second edit']);
    requestAt('/about?preview='.tokenOf($share));
    expect(previewSession()->pageForSlug('about')?->title)->toBe('Second edit')
        ->and(previewSession()->items()[1]['active'])->toBe('Main');
});

it('does not reuse a working-draft link that has been revoked or has expired', function () {
    $actor = TestUser::make();
    $page = previewPage();
    app(PageDrafts::class)->ensureDraft($page);
    $shares = app(PreviewShares::class);

    $first = $shares->workingDraft($actor, [], [$page->id]);
    $shares->revoke($first);
    $second = $shares->workingDraft($actor, [], [$page->id]);

    $this->travel(2)->days();

    expect($second->id)->not->toBe($first->id)
        ->and($shares->workingDraft($actor, [], [$page->id])->id)->not->toBe($second->id);
});

// ------------------------------------------------------------------ opening a link

it('opens a link: counts the open, remembers nothing, leaves updated_at alone', function () {
    [$share] = sharedLink();
    $stamp = $share->refresh()->updated_at;
    $this->travel(5)->minutes();

    $opened = app(PreviewShares::class)->open(tokenOf($share));
    $fresh = $share->refresh();

    expect($opened?->id)->toBe($share->id)
        ->and($fresh->open_count)->toBe(1)
        ->and($fresh->last_opened_at)->not->toBeNull()
        ->and($fresh->updated_at->equalTo($stamp))->toBeTrue()
        ->and(app(PreviewShares::class)->open('unknown'))->toBeNull();

    requestAt('/about?preview='.tokenOf($share));
    previewSession()->isActive();
    expect($share->refresh()->open_count)->toBe(1);
});

it('puts a token into an address, ahead of any fragment, and recognises one', function () {
    expect(PreviewUrl::with('/about', 'abc'))->toBe('/about?preview=abc')
        ->and(PreviewUrl::with('/about?page=2', 'abc'))->toBe('/about?page=2&preview=abc')
        ->and(PreviewUrl::with('/about#top', 'abc'))->toBe('/about?preview=abc#top')
        ->and(PreviewUrl::with('/about?preview=old', 'abc'))->toBe('/about?preview=old')
        ->and(PreviewUrl::with('https://example.test/a?x=1#y', 'a b'))->toBe('https://example.test/a?x=1&preview=a%20b#y')
        ->and(PreviewUrl::has('/about?a=1&amp;preview=x'))->toBeTrue()
        ->and(PreviewUrl::has('/about?a=1'))->toBeFalse();

    config(['filagora-cms.preview.query_parameter' => 'draft']);
    expect(PreviewUrl::with('/about', 'abc'))->toBe('/about?draft=abc');
});

// ------------------------------------------------------------------ middleware and abilities

it('marks responses uncacheable, noindex and referrer-free only for a request with a token', function () {
    [$share] = sharedLink();
    $next = fn (Request $request): Response => new Response('ok');

    requestAt('/about?preview='.tokenOf($share));
    $marked = app(MarkPreviewResponsesUncacheable::class)->handle(request(), $next);

    expect($marked->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($marked->headers->get('Cache-Control'))->toContain('no-store')
        ->and($marked->headers->get('Cache-Control'))->toContain('private')
        ->and($marked->headers->get('Referrer-Policy'))->toBe('no-referrer');

    requestAt('/about?preview=wrong');
    $plain = app(MarkPreviewResponsesUncacheable::class)->handle(request(), $next);

    expect($plain->headers->get('X-Robots-Tag'))->toBeNull()
        ->and($plain->headers->get('Referrer-Policy'))->toBeNull();
});

it('keeps the token on links to other pages of the site, and on redirects within it', function () {
    [$share] = sharedLink();
    $token = tokenOf($share);
    $html = <<<'HTML'
<nav>
<a href="/pages/one">One</a>
<a class="x" href='/pages/two?page=2#top'>Two</a>
<a href="/pages/three?a=1&amp;b=2">Three</a>
<a href="http://localhost/pages/four">Four</a>
<a href="pages/five">Five</a>
<a href="#top">Anchor</a>
<a href="https://other.example/page">Elsewhere</a>
<a href="//cdn.example/x">Protocol relative</a>
<a href="mailto:a@example.test">Mail</a>
<a href="/files/report.pdf">File</a>
<a href="/pages/six?preview=old">Has one</a>
<a href="/staff/dashboard">Staff</a>
<a href="/logout">Sign out</a>
</nav>
HTML;
    config(['filagora-cms.preview.carry_token_except' => ['/staff', '/logout']]);
    $respond = fn (Request $request): Response => new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

    requestAt('/about?preview='.$token);
    $carried = app(CarryPreviewToken::class)->handle(request(), $respond)->getContent();

    expect($carried)
        ->toContain('<a href="/pages/one?preview='.$token.'">')
        ->toContain("<a class=\"x\" href='/pages/two?page=2&amp;preview=".$token."#top'>")
        ->toContain('<a href="/pages/three?a=1&amp;b=2&amp;preview='.$token.'">')
        ->toContain('<a href="http://localhost/pages/four?preview='.$token.'">')
        ->toContain('<a href="pages/five?preview='.$token.'">')
        ->toContain('<a href="#top">')
        ->toContain('<a href="https://other.example/page">')
        ->toContain('<a href="//cdn.example/x">')
        ->toContain('<a href="mailto:a@example.test">')
        ->toContain('<a href="/files/report.pdf">')
        ->toContain('<a href="/pages/six?preview=old">')
        ->toContain('<a href="/staff/dashboard">')
        ->toContain('<a href="/logout">');

    $redirect = app(CarryPreviewToken::class)->handle(request(), fn (Request $request) => new RedirectResponse('/pages/moved'));
    $away = app(CarryPreviewToken::class)->handle(request(), fn (Request $request) => new RedirectResponse('https://other.example/x'));

    expect($redirect->headers->get('Location'))->toBe('/pages/moved?preview='.$token)
        ->and($away->headers->get('Location'))->toBe('https://other.example/x');
});

it('leaves every response alone when the request has no valid token, or the response is not HTML', function () {
    [$share] = sharedLink();
    $html = '<a href="/pages/one">One</a>';
    $respond = fn (Request $request): Response => new Response($html, 200, ['Content-Type' => 'text/html']);

    foreach (['/about', '/about?preview=wrong'] as $uri) {
        requestAt($uri);
        expect(app(CarryPreviewToken::class)->handle(request(), $respond)->getContent())->toBe($html);
    }

    requestAt('/about?preview='.tokenOf($share));
    $json = app(CarryPreviewToken::class)->handle(request(), fn (Request $request) => new Response($html, 200, ['Content-Type' => 'application/json']));

    expect($json->getContent())->toBe($html);
});

it('puts those middleware in the web group, unless switched off', function () {
    $groups = app(Router::class)->getMiddlewareGroups();

    expect($groups['web'] ?? [])->toContain(MarkPreviewResponsesUncacheable::class)
        ->toContain(CarryPreviewToken::class)
        ->and(config('filagora-cms.preview.uncacheable_middleware'))->toBeTrue()
        ->and(config('filagora-cms.preview.carry_token'))->toBeTrue();
});

it('keeps those middleware when the kernel syncs its own groups to the router', function () {
    app(HttpKernel::class)->appendMiddlewareToGroup('web', 'unrelated');

    expect(app(Router::class)->getMiddlewareGroups()['web'] ?? [])->toContain(MarkPreviewResponsesUncacheable::class)->toContain(CarryPreviewToken::class);
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

it('lands a visitor on a working-draft page too', function () {
    $actor = TestUser::make();
    $page = previewPage();
    app(PageDrafts::class)->saveDraftFields($page, ['title' => 'Working copy']);

    $share = app(PreviewShares::class)->workingDraft($actor, [], [$page->id]);

    expect(app(PreviewShares::class)->landingPage($share)?->title)->toBe('Working copy');
});

it('finds only usable links by token', function () {
    [$share] = sharedLink();
    $token = $share->getRawOriginal('token');

    expect(app(PreviewShares::class)->findUsable($token)?->id)->toBe($share->id)
        ->and(app(PreviewShares::class)->findUsable('x'))->toBeNull();

    $share->update(['revoked_at' => now()]);
    expect(app(PreviewShares::class)->findUsable($token))->toBeNull();
});

it('declares the two abilities as entitlements and gates them', function () {
    $keys = array_map(fn ($definition) => $definition->key, array_values(app(EntitlementRegistry::class)->definitions()));

    expect($keys)->toContain('filagora.cms.manage', 'filagora.cms.preview');

    $user = editor('filagora.cms.preview');
    expect(Gate::forUser($user)->allows('filagora.cms.preview'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('filagora.cms.manage'))->toBeFalse();
});
