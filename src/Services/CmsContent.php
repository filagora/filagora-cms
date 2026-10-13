<?php

declare(strict_types=1);

namespace Filagora\Cms\Services;

use Filagora\Cms\Contracts\PreviewResolver;
use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Models\CmsMediaAsset;
use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Navigation\NavigationItems;
use Filagora\Cms\Support\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What a visitor may read: published pages, menus, reusable blocks and media. A request that is
 * previewing unpublished changes is served those instead (through the `PreviewResolver`).
 * Menus are cached under `cms:navigation:{name}` for `filagora-cms.navigation_cache_seconds`
 * and cleared when the menu is saved.
 */
class CmsContent
{
    /** @var array<string, CmsNavigation|null> */
    private array $navigations = [];

    /** @var array<string, CmsMediaAsset|null> */
    private array $mediaByPath = [];

    public function __construct(
        private readonly PreviewResolver $preview,
        private readonly NavigationItems $navigationItems,
    ) {}

    /**
     * Published pages, newest first, optionally only those with a tag.
     *
     * @return Collection<int, CmsPage>
     */
    public function publishedPages(?string $tagSlug = null): Collection
    {
        /** @var Collection<int, CmsPage> */
        return $this->pageQuery()
            ->with(['tags:id,name,slug'])
            ->when($tagSlug, fn (Builder $query) => $query->whereHas('tags', fn (Builder $tags) => $tags->where('slug', $tagSlug)))
            ->published()
            ->orderByDesc('published_at')
            ->get();
    }

    /**
     * The page at a slug if it is published; a draft page is returned only when its preview token
     * is given.
     */
    public function publishedPageBySlug(string $slug, ?string $previewToken = null): ?CmsPage
    {
        return $this->preview->pageForSlug($slug) ?? $this->readablePage('slug', $slug, $previewToken);
    }

    /**
     * The page at a custom route (`/about`), with the same rules as by slug.
     */
    public function publishedPageByCustomRoute(string $customRoute, ?string $previewToken = null): ?CmsPage
    {
        return $this->preview->pageForCustomRoute($customRoute) ?? $this->readablePage('custom_route', $customRoute, $previewToken);
    }

    public function navigation(string $name): ?CmsNavigation
    {
        $draftItems = $this->preview->draftItemsFor($name);

        if ($draftItems !== null) {
            $previewed = $this->navigationQuery()->where('name', $name)->first();

            if ($previewed instanceof CmsNavigation) {
                $previewed->items = $draftItems;

                return $this->visibleNavigation($previewed);
            }
        }

        if (! array_key_exists($name, $this->navigations)) {
            $cached = Cache::remember(
                CmsNavigation::CACHE_PREFIX.$name,
                now()->addSeconds((int) config('filagora-cms.navigation_cache_seconds', 300)),
                fn (): ?array => $this->serialize($this->navigationQuery()->where('name', $name)->first()),
            );

            $this->navigations[$name] = is_array($cached) ? $this->hydrate($cached) : null;
        }

        return $this->visibleNavigation($this->navigations[$name]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function navigationItems(string $name): array
    {
        $items = $this->navigation($name)?->items;

        return is_array($items) ? array_values($items) : [];
    }

    public function publishedReusableBlock(string $slug): ?CmsBlock
    {
        /** @var CmsBlock|null */
        return Models::class('block')::query()
            ->where('slug', $slug)
            ->where('is_reusable', true)
            ->where('is_published', true)
            ->first();
    }

    public function mediaAsset(string $path): ?CmsMediaAsset
    {
        $path = trim($path);

        return $path === '' ? null : ($this->mediaAssets([$path])[$path] ?? null);
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<string, CmsMediaAsset>
     */
    public function mediaAssets(array $paths): array
    {
        $paths = array_values(array_unique(array_filter(
            array_map(static fn (mixed $path): string => trim((string) $path), $paths),
            static fn (string $path): bool => $path !== '',
        )));

        $missing = array_values(array_filter($paths, fn (string $path): bool => ! array_key_exists($path, $this->mediaByPath)));

        if ($missing !== []) {
            $found = Models::class('media_asset')::query()->whereIn('path', $missing)->get()->keyBy('path');

            foreach ($missing as $path) {
                $asset = $found->get($path);
                $this->mediaByPath[$path] = $asset instanceof CmsMediaAsset ? $asset : null;
            }
        }

        return array_filter(
            array_intersect_key($this->mediaByPath, array_flip($paths)),
            static fn (?CmsMediaAsset $asset): bool => $asset instanceof CmsMediaAsset,
        );
    }

    /**
     * The public URL for each path that has a file behind it.
     *
     * @param  array<int, string>  $paths
     * @return array<string, string>
     */
    public function mediaUrls(array $paths): array
    {
        $urls = [];

        foreach ($this->mediaAssets($paths) as $path => $asset) {
            $url = $asset->publicUrl();

            if (is_string($url) && $url !== '') {
                $urls[$path] = $url;
            }
        }

        return $urls;
    }

    private function readablePage(string $column, string $value, ?string $previewToken): ?CmsPage
    {
        /** @var CmsPage|null */
        return $this->pageQuery()
            ->with(['blocks', 'tags:id,name,slug'])
            ->where($column, $value)
            ->where(function (Builder $query) use ($previewToken): void {
                $query->where(function (Builder $published): void {
                    $published->where('status', 'published')
                        ->whereNotNull('published_at')
                        ->where('published_at', '<=', now());
                });

                if ($previewToken !== null) {
                    $query->orWhere('preview_token', $previewToken);
                }
            })
            ->first();
    }

    /**
     * @return Builder<CmsPage>
     */
    private function pageQuery(): Builder
    {
        /** @var Builder<CmsPage> */
        return Models::class('page')::query();
    }

    /**
     * @return Builder<CmsNavigation>
     */
    private function navigationQuery(): Builder
    {
        /** @var Builder<CmsNavigation> */
        return Models::class('navigation')::query();
    }

    private function visibleNavigation(?CmsNavigation $navigation): ?CmsNavigation
    {
        if ($navigation === null) {
            return null;
        }

        $visible = clone $navigation;
        $visible->items = $this->navigationItems->normalize($visible->items);

        return $visible;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serialize(?CmsNavigation $navigation): ?array
    {
        if ($navigation === null) {
            return null;
        }

        return [
            'id' => (int) $navigation->id,
            'name' => (string) $navigation->name,
            'items' => is_array($navigation->items) ? $navigation->items : [],
            'created_at' => $navigation->created_at?->toISOString(),
            'updated_at' => $navigation->updated_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hydrate(array $payload): ?CmsNavigation
    {
        $id = (int) ($payload['id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));

        if ($id <= 0 || $name === '') {
            return null;
        }

        $class = Models::class('navigation');

        /** @var CmsNavigation */
        return (new $class)->newFromBuilder([
            'id' => $id,
            'name' => $name,
            'items' => json_encode(is_array($payload['items'] ?? null) ? $payload['items'] : []),
            'created_at' => $this->timestamp($payload['created_at'] ?? null),
            'updated_at' => $this->timestamp($payload['updated_at'] ?? null),
        ]);
    }

    private function timestamp(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value)->toDateTimeString();
    }
}
