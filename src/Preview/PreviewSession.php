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
use Filagora\Cms\Support\Models;
use Illuminate\Database\Eloquent\Builder;

/**
 * Preview of unpublished changes across the real site, carried entirely by the address. A request
 * whose query holds the token of a usable preview link (`/privacy?preview=TOKEN`) is served the
 * draft menus and pages that link names; every other request, whoever makes it, is served the live
 * site. Nothing is stored in a session or a cookie, so a page's address with and without the token
 * is the draft and the original.
 *
 * A link names each menu and page either as a draft variant (pinned, so it does not change when an
 * editor switches the working draft) or as `true` (the working draft as it is now).
 */
class PreviewSession implements PreviewResolver, ResolvesPreviewedMenuTitles
{
    private const SHARE_ATTRIBUTE = 'cms_preview_share';

    private const MAX_TOKEN_LENGTH = 128;

    public function __construct(
        private readonly PageDrafts $pageDrafts,
        private readonly NavigationDrafts $navigationDrafts,
    ) {}

    /**
     * True when this request carries a usable preview token. Cheap for ordinary requests (no
     * query string value, no database); a token costs one lookup per request, so revoking or
     * expiring a link takes effect at once.
     */
    public function isActive(): bool
    {
        return $this->share() !== null;
    }

    /**
     * The raw token this request is previewing with, for rebuilding addresses.
     */
    public function token(): ?string
    {
        return $this->share()?->getRawOriginal('token');
    }

    public function expiresAt(): ?DateTimeInterface
    {
        return $this->share()?->expires_at;
    }

    /**
     * What this request is previewing, for a banner: each item's label and the variant shown.
     *
     * @return list<array{type: string, key: string, label: string, active: ?string}>
     */
    public function items(): array
    {
        if (! $this->isActive()) {
            return [];
        }

        $resolved = $this->resolved();
        $items = [];

        /** @var CmsNavigation $navigation */
        foreach (Models::class('navigation')::query()->whereIn('name', array_keys($resolved['navigations']))->orderBy('name')->get() as $navigation) {
            $variant = $resolved['navigations'][$navigation->name];
            $items[] = [
                'type' => 'menu',
                'key' => (string) $navigation->name,
                'label' => __('filagora-cms::messages.preview.menu_label', ['name' => $navigation->name]),
                'active' => is_string($variant) ? $variant : $this->navigationDrafts->activeVariantName($navigation),
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
            ];
        }

        return $items;
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
     * What the token names: menu name and page id to a variant name, or `true` for the working draft.
     *
     * @return array{navigations: array<string, true|string>, pages: array<int, true|string>}
     */
    private function resolved(): array
    {
        $share = $this->share();

        if ($share === null) {
            return ['navigations' => [], 'pages' => []];
        }

        $navigations = [];

        foreach ($share->navigations() as $name => $variant) {
            if (is_string($variant) || $variant === true) {
                $navigations[(string) $name] = $variant;
            }
        }

        $pages = [];

        foreach ($share->pages() as $id => $variant) {
            if (is_string($variant) || $variant === true) {
                $pages[(int) $id] = $variant;
            }
        }

        return ['navigations' => $navigations, 'pages' => $pages];
    }

    /**
     * The usable link whose token is in this request's query, looked up once per request.
     * Unknown, revoked and expired tokens, and anything that cannot be a token, resolve to
     * nothing, so the request is served live.
     */
    private function share(): ?CmsPreviewShare
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();
        $value = $request->query((string) config('filagora-cms.preview.query_parameter', 'preview'));

        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        if (! $request->attributes->has(self::SHARE_ATTRIBUTE)) {
            /** @var Builder<CmsPreviewShare> $shares */
            $shares = Models::class('preview_share')::query();
            $request->attributes->set(self::SHARE_ATTRIBUTE, $shares->usable()->where('token', $value)->first());
        }

        $share = $request->attributes->get(self::SHARE_ATTRIBUTE);

        return $share instanceof CmsPreviewShare ? $share : null;
    }
}
