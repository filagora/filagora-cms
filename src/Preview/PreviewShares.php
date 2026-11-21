<?php

declare(strict_types=1);

namespace Filagora\Cms\Preview;

use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Models\CmsPreviewShare;
use Filagora\Cms\Services\NavigationDrafts;
use Filagora\Cms\Services\PageDrafts;
use Filagora\Cms\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates, opens and revokes shareable preview links. A link pins each menu and page to a named
 * draft variant, so what the recipient sees does not change when an editor switches the working
 * draft. Nothing is made public: the recipient previews only at addresses that carry the token.
 */
class PreviewShares
{
    public const EDITOR_LABEL = 'Editor preview';

    public function __construct(
        private readonly PageDrafts $pageDrafts,
        private readonly NavigationDrafts $navigationDrafts,
    ) {}

    /**
     * @param  array<string, string>  $navigations  menu name => variant name
     * @param  array<int, string>  $pages  page id => variant name
     * @param  int  $days  How long the link works (at least one).
     *
     * @throws ValidationException When nothing is chosen or a variant does not exist.
     */
    public function create(Authenticatable $actor, array $navigations, array $pages, int $days, ?string $label = null): CmsPreviewShare
    {
        if ($navigations === [] && $pages === []) {
            throw ValidationException::withMessages(['items' => 'Choose at least one draft to share.']);
        }

        $this->assertVariantsExist($navigations, $pages);

        /** @var CmsPreviewShare */
        return Models::class('preview_share')::query()->create([
            'token' => Str::random(48),
            'label' => $label !== null && trim($label) !== '' ? trim($label) : null,
            'items' => ['navigations' => $navigations, 'pages' => $pages],
            'expires_at' => now()->addDays(max(1, $days)),
            'created_by_id' => $actor->getAuthIdentifier(),
        ]);
    }

    public function revoke(CmsPreviewShare $share): void
    {
        if ($share->revoked_at === null) {
            $share->update(['revoked_at' => now()]);
        }
    }

    /**
     * A link to the working draft of the given menus and pages, as it is now and as it changes,
     * for an editor's Preview button. A usable link the same editor already made for the same
     * items is reused, so clicking Preview repeatedly does not pile up links.
     *
     * @param  list<string>  $navigations  menu names
     * @param  list<int>  $pages  page ids
     * @param  int  $hours  How long a new link works (at least one).
     */
    public function workingDraft(Authenticatable $actor, array $navigations = [], array $pages = [], int $hours = 24): CmsPreviewShare
    {
        $items = [
            'navigations' => array_fill_keys($navigations, true),
            'pages' => array_fill_keys($pages, true),
        ];

        /** @var Builder<CmsPreviewShare> $shares */
        $shares = Models::class('preview_share')::query();

        /** @var CmsPreviewShare $share */
        foreach ($shares->usable()->where('created_by_id', $actor->getAuthIdentifier())->where('label', self::EDITOR_LABEL)->latest('id')->limit(20)->get() as $share) {
            if ($share->items === $items) {
                return $share;
            }
        }

        /** @var CmsPreviewShare */
        return Models::class('preview_share')::query()->create([
            'token' => Str::random(48),
            'label' => self::EDITOR_LABEL,
            'items' => $items,
            'expires_at' => now()->addHours(max(1, $hours)),
            'created_by_id' => $actor->getAuthIdentifier(),
        ]);
    }

    /**
     * The share for this token, if it is not revoked or expired.
     */
    public function findUsable(string $token): ?CmsPreviewShare
    {
        /** @var Builder<CmsPreviewShare> $shares */
        $shares = Models::class('preview_share')::query();

        return $shares->usable()->where('token', $token)->first();
    }

    /**
     * Opens a link: counts the open and returns the share, for the host to send the visitor to
     * the previewed address (see `landingPage()` and `PreviewUrl::with()`). Null when the token
     * is unknown, revoked or expired. Nothing is remembered about the visitor.
     */
    public function open(string $token): ?CmsPreviewShare
    {
        $share = $this->findUsable($token);

        if ($share === null) {
            return null;
        }

        $this->recordOpen($share);

        return $share;
    }

    /**
     * Records an open of the link without touching `updated_at`.
     */
    public function recordOpen(CmsPreviewShare $share): void
    {
        Models::class('preview_share')::query()->whereKey($share->id)->toBase()->update([
            'last_opened_at' => now(),
            'open_count' => $share->open_count + 1,
        ]);
    }

    /**
     * The first shared page as it will look, for choosing where to land a visitor; null when the
     * share holds no page that still has its variant.
     */
    public function landingPage(CmsPreviewShare $share): ?CmsPage
    {
        foreach ($share->pages() as $id => $variant) {
            /** @var CmsPage|null $page */
            $page = Models::class('page')::query()->find((int) $id);
            $preview = match (true) {
                $page === null => null,
                is_string($variant) => $this->pageDrafts->previewPageVariant($page, $variant),
                $variant === true => $this->pageDrafts->previewPage($page),
                default => null,
            };

            if ($preview !== null) {
                return $preview;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $navigations
     * @param  array<int, string>  $pages
     */
    private function assertVariantsExist(array $navigations, array $pages): void
    {
        foreach ($navigations as $name => $variant) {
            /** @var CmsNavigation|null $navigation */
            $navigation = Models::class('navigation')::query()->where('name', $name)->first();

            if ($navigation === null || $this->navigationDrafts->itemsForVariant($navigation, $variant) === null) {
                throw ValidationException::withMessages(['items' => "The {$name} menu has no draft variant called {$variant}."]);
            }
        }

        foreach ($pages as $id => $variant) {
            /** @var CmsPage|null $page */
            $page = Models::class('page')::query()->find($id);

            if ($page === null || $this->pageDrafts->previewPageVariant($page, $variant) === null) {
                throw ValidationException::withMessages(['items' => "Page {$id} has no draft variant called {$variant}."]);
            }
        }
    }
}
