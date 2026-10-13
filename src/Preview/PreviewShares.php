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
 * draft. Nothing is made public: the recipient previews in their own browser session.
 */
class PreviewShares
{
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
     * The share for this token, if it is not revoked or expired.
     */
    public function findUsable(string $token): ?CmsPreviewShare
    {
        /** @var Builder<CmsPreviewShare> $shares */
        $shares = Models::class('preview_share')::query();

        return $shares->usable()->where('token', $token)->first();
    }

    /**
     * Opens a link: starts the preview in this browser session and counts the open. Null when
     * the token is unknown, revoked or expired.
     */
    public function open(string $token, PreviewSession $session): ?CmsPreviewShare
    {
        $share = $this->findUsable($token);

        if ($share === null) {
            return null;
        }

        $session->startShare($share);
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
            $preview = $page !== null && is_string($variant) ? $this->pageDrafts->previewPageVariant($page, $variant) : null;

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
