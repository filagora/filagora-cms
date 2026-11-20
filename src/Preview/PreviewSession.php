<?php

declare(strict_types=1);

namespace Filagora\Cms\Preview;

use DateTimeInterface;
use Filagora\Cms\Contracts\PreviewResolver;
use Filagora\Cms\Contracts\ResolvesPreviewedMenuTitles;
use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Models\CmsPreviewShare;
use Filagora\Cms\Services\NavigationDrafts;
use Filagora\Cms\Services\PageDrafts;
use Filagora\Cms\Support\Abilities;
use Filagora\Cms\Support\Models;
use Illuminate\Contracts\Session\Session as SessionContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Preview of unpublished changes across the real site. A menu appears on every page and pages
 * link to each other, so previewing is one session holding any mix of draft menus and draft pages:
 * while it is on, those menus and pages are served from their drafts, and anything not included
 * shows its live version, as visitors see it.
 *
 * Two things can put a browser into a preview: a signed-in editor adding items (each served from
 * its working draft), or a shared preview link opened by anyone, which pins each item to a named
 * variant. Public visitors with neither never see a draft.
 */
class PreviewSession implements PreviewResolver, ResolvesPreviewedMenuTitles
{
    public const SESSION_KEY = 'cms_preview';

    public const SHARE_SESSION_KEY = 'cms_preview_share';

    private const SHARE_ATTRIBUTE = 'cms_preview_share';

    private const URL_SHARE_ATTRIBUTE = 'cms_preview_url_share';

    /**
     * The query value that shows the live site for one request, whatever else is being previewed.
     */
    public const LIVE = 'off';

    private const MAX_TOKEN_LENGTH = 128;

    public function __construct(
        private readonly PageDrafts $pageDrafts,
        private readonly NavigationDrafts $navigationDrafts,
    ) {}

    public function addNavigation(CmsNavigation $navigation): void
    {
        $stored = $this->stored();
        $stored['navigations'][(string) $navigation->name] = true;
        $this->session()?->put(self::SESSION_KEY, $stored);
    }

    public function addPage(CmsPage $page): void
    {
        $stored = $this->stored();
        $stored['pages'][(int) $page->id] = true;
        $this->session()?->put(self::SESSION_KEY, $stored);
    }

    /**
     * Take one item out of the preview. `$type` is `menu` or `page`; `$key` the menu name or page id.
     */
    public function remove(string $type, string $key): void
    {
        $stored = $this->stored();

        if ($type === 'menu') {
            unset($stored['navigations'][$key]);
        } elseif ($type === 'page') {
            unset($stored['pages'][(int) $key]);
        }

        $this->session()?->put(self::SESSION_KEY, $stored);
    }

    /**
     * Leave the preview, whatever started it.
     */
    public function clear(): void
    {
        $this->session()?->forget([self::SESSION_KEY, self::SHARE_SESSION_KEY]);
    }

    /**
     * Starts a shared preview in this browser session.
     */
    public function startShare(CmsPreviewShare $share): void
    {
        $this->session()?->put(self::SHARE_SESSION_KEY, $share->id);
    }

    /**
     * True when the preview in effect comes from a shared link rather than an editor's session.
     */
    public function isShared(): bool
    {
        return $this->resolved()['share'] !== null;
    }

    public function sharedUntil(): ?DateTimeInterface
    {
        return $this->resolved()['share']?->expires_at;
    }

    /**
     * True when the preview in effect comes from a token in the address (`?preview=TOKEN`), which
     * applies to that one request only: no session is started and nothing is remembered.
     */
    public function isFromUrl(): bool
    {
        return $this->urlShare() !== null;
    }

    /**
     * True when the address asks for the live site (`?preview=off`) for this request, so the
     * original can be compared with a preview without ending it.
     */
    public function isViewingLive(): bool
    {
        return $this->queryValue() === self::LIVE;
    }

    /**
     * True when this browser is previewing anything. Cheap for ordinary visitors (it only reads
     * the session, no queries); a shared link costs one lookup per request, so revoking or
     * expiring it takes effect at once.
     */
    public function isActive(): bool
    {
        return $this->resolved()['active'];
    }

    /**
     * What this browser is previewing, for a banner. Each item carries its draft variants
     * (`active` is the one being shown) so an editor can switch between them; a shared link
     * offers none.
     *
     * @return list<array{type: string, key: string, label: string, active: ?string, variants: list<string>}>
     */
    public function items(): array
    {
        $resolved = $this->resolved();

        if (! $resolved['active']) {
            return [];
        }

        $items = [];

        /** @var CmsNavigation $navigation */
        foreach (Models::class('navigation')::query()->whereIn('name', array_keys($resolved['navigations']))->orderBy('name')->get() as $navigation) {
            $variant = $resolved['navigations'][$navigation->name];
            $items[] = [
                'type' => 'menu',
                'key' => (string) $navigation->name,
                'label' => __('filagora-cms::messages.preview.menu_label', ['name' => $navigation->name]),
                'active' => is_string($variant) ? $variant : $this->navigationDrafts->activeVariantName($navigation),
                'variants' => $resolved['share'] === null ? $this->navigationDrafts->variantNames($navigation) : [],
            ];
        }

        /** @var CmsPage $page */
        foreach (Models::class('page')::query()->whereIn('id', array_keys($resolved['pages']))->get(['id', 'title']) as $page) {
            $variant = $resolved['pages'][$page->id];
            $items[] = [
                'type' => 'page',
                'key' => (string) $page->id,
                'label' => (string) $page->title,
                'active' => is_string($variant) ? $variant : $this->pageDrafts->activeVariantName($page),
                'variants' => $resolved['share'] === null ? $this->pageDrafts->variantNames($page) : [],
            ];
        }

        return $items;
    }

    /**
     * The editor's own preview as `{navigations: {menu name: variant}, pages: {page id: variant}}`,
     * each at the variant that is the working draft now. Used to bundle it into a share.
     *
     * @return array{navigations: array<string, string>, pages: array<int, string>}
     */
    public function currentSelection(): array
    {
        $selection = ['navigations' => [], 'pages' => []];

        if (! $this->canPreview()) {
            return $selection;
        }

        $stored = $this->stored();

        /** @var CmsNavigation $navigation */
        foreach (Models::class('navigation')::query()->whereIn('name', array_keys($stored['navigations']))->get() as $navigation) {
            $variant = $this->navigationDrafts->activeVariantName($navigation);

            if ($variant !== null) {
                $selection['navigations'][$navigation->name] = $variant;
            }
        }

        /** @var CmsPage $page */
        foreach (Models::class('page')::query()->whereIn('id', array_keys($stored['pages']))->get() as $page) {
            $variant = $this->pageDrafts->activeVariantName($page);

            if ($variant !== null) {
                $selection['pages'][$page->id] = $variant;
            }
        }

        return $selection;
    }

    public function draftItemsFor(string $name): ?array
    {
        $resolved = $this->resolved();

        if (! array_key_exists($name, $resolved['navigations'])) {
            return null;
        }

        /** @var CmsNavigation|null $navigation */
        $navigation = Models::class('navigation')::query()->where('name', $name)->first();

        if ($navigation === null) {
            return null;
        }

        $variant = $resolved['navigations'][$name];

        return is_string($variant)
            ? $this->navigationDrafts->itemsForVariant($navigation, $variant)
            : $navigation->draft()->first()?->items();
    }

    /**
     * The heading the previewed draft of a menu stages; null when it is not being previewed or
     * stages none.
     */
    public function draftTitleFor(string $name): ?string
    {
        $resolved = $this->resolved();

        if (! array_key_exists($name, $resolved['navigations'])) {
            return null;
        }

        /** @var CmsNavigation|null $navigation */
        $navigation = Models::class('navigation')::query()->where('name', $name)->first();

        if ($navigation === null) {
            return null;
        }

        $variant = $resolved['navigations'][$name];

        if (is_string($variant)) {
            return $this->navigationDrafts->titleForVariant($navigation, $variant);
        }

        $draft = $navigation->draft()->first();

        return $draft?->stagesTitle() ? $draft->title() : null;
    }

    public function pageForSlug(string $slug): ?CmsPage
    {
        return $this->matchingPage(fn (CmsPage $page): bool => trim((string) $page->slug) === $slug);
    }

    public function pageForCustomRoute(string $customRoute): ?CmsPage
    {
        $wanted = '/'.trim($customRoute, '/');

        return $this->matchingPage(function (CmsPage $page) use ($wanted): bool {
            $route = trim((string) ($page->custom_route ?? ''));

            return $route !== '' && '/'.trim($route, '/') === $wanted;
        });
    }

    /**
     * @param  callable(CmsPage): bool  $matches
     */
    private function matchingPage(callable $matches): ?CmsPage
    {
        $pages = $this->resolved()['pages'];

        if ($pages === []) {
            return null;
        }

        /** @var CmsPage $page */
        foreach (Models::class('page')::query()->whereIn('id', array_keys($pages))->orderBy('id')->get() as $page) {
            $variant = $pages[$page->id];
            $preview = is_string($variant) ? $this->pageDrafts->previewPageVariant($page, $variant) : $this->pageDrafts->previewPage($page);

            if ($preview !== null && $matches($preview)) {
                return $preview;
            }
        }

        return null;
    }

    /**
     * What is being previewed, and by which route. The address comes first and applies to this
     * request only: `?preview=off` shows the live site, and `?preview=TOKEN` shows a usable share's
     * pinned variants. Otherwise an editor's own session (items at their working drafts, `true`)
     * or a shared link opened earlier (items pinned to a variant name); the editor's session wins
     * when both exist.
     *
     * @return array{active: bool, share: CmsPreviewShare|null, navigations: array<string, true|string>, pages: array<int, true|string>}
     */
    private function resolved(): array
    {
        if ($this->isViewingLive()) {
            return ['active' => false, 'share' => null, 'navigations' => [], 'pages' => []];
        }

        $stored = $this->stored();

        if ($this->urlShare() === null && ($stored['navigations'] !== [] || $stored['pages'] !== []) && $this->canPreview()) {
            return ['active' => true, 'share' => null, 'navigations' => $stored['navigations'], 'pages' => $stored['pages']];
        }

        $share = $this->urlShare() ?? $this->share();

        if ($share === null) {
            return ['active' => false, 'share' => null, 'navigations' => [], 'pages' => []];
        }

        $pages = [];

        foreach ($share->pages() as $id => $variant) {
            if (is_string($variant)) {
                $pages[(int) $id] = $variant;
            }
        }

        return [
            'active' => true,
            'share' => $share,
            'navigations' => array_filter($share->navigations(), 'is_string'),
            'pages' => $pages,
        ];
    }

    /**
     * The usable share named by the address, looked up once per request. Unknown, revoked and
     * expired tokens (and anything that cannot be a token) resolve to nothing, so the request is
     * served live.
     */
    private function urlShare(): ?CmsPreviewShare
    {
        $token = $this->queryValue();

        if ($token === null || $token === self::LIVE || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        $attributes = request()->attributes;

        if (! $attributes->has(self::URL_SHARE_ATTRIBUTE)) {
            /** @var Builder<CmsPreviewShare> $shares */
            $shares = Models::class('preview_share')::query();
            $attributes->set(self::URL_SHARE_ATTRIBUTE, $shares->usable()->where('token', $token)->first());
        }

        $share = $attributes->get(self::URL_SHARE_ATTRIBUTE);

        return $share instanceof CmsPreviewShare ? $share : null;
    }

    private function queryValue(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $value = request()->query((string) config('filagora-cms.preview.query_parameter', 'preview'));

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function share(): ?CmsPreviewShare
    {
        $session = $this->session();

        if ($session === null) {
            return null;
        }

        $id = $session->get(self::SHARE_SESSION_KEY);

        if (! is_int($id)) {
            return null;
        }

        $attributes = request()->attributes;

        if (! $attributes->has(self::SHARE_ATTRIBUTE)) {
            /** @var Builder<CmsPreviewShare> $shares */
            $shares = Models::class('preview_share')::query();
            $attributes->set(self::SHARE_ATTRIBUTE, $shares->usable()->find($id));
        }

        $share = $attributes->get(self::SHARE_ATTRIBUTE);

        return $share instanceof CmsPreviewShare ? $share : null;
    }

    /**
     * @return array{navigations: array<string, true>, pages: array<int, true>}
     */
    private function stored(): array
    {
        $empty = ['navigations' => [], 'pages' => []];

        $session = $this->session();

        if ($session === null) {
            return $empty;
        }

        $stored = $session->get(self::SESSION_KEY, []);

        if (! is_array($stored)) {
            return $empty;
        }

        return [
            'navigations' => is_array($stored['navigations'] ?? null) ? $stored['navigations'] : [],
            'pages' => is_array($stored['pages'] ?? null) ? $stored['pages'] : [],
        ];
    }

    /**
     * The request's session when it has one (a web request), otherwise the application's session
     * store, which is what a Livewire call from a panel writes to. Null when neither exists.
     */
    private function session(): ?SessionContract
    {
        if (app()->bound('request') && request()->hasSession()) {
            return request()->session();
        }

        return app()->bound('session.store') ? app('session.store') : null;
    }

    private function canPreview(): bool
    {
        $user = auth()->user();

        return $user !== null
            && (Gate::forUser($user)->allows(Abilities::MANAGE) || Gate::forUser($user)->allows(Abilities::PREVIEW));
    }
}
