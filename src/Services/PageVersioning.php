<?php

declare(strict_types=1);

namespace ForumKit\Cms\Services;

use ForumKit\Cms\Models\CmsPage;
use ForumKit\Cms\Models\CmsPageVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Records a numbered snapshot of a page: its fields, tags and live blocks. The snapshot's shape
 * is stored data and matches the site this package was extracted from, so old versions restore.
 */
class PageVersioning
{
    public function createSnapshot(CmsPage $page, ?Authenticatable $actor = null, ?string $summary = null): CmsPageVersion
    {
        return DB::transaction(function () use ($page, $actor, $summary): CmsPageVersion {
            $page->loadMissing([
                'tags' => fn ($query) => $query->orderBy('name'),
                'blocks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            ]);

            $nextVersion = ((int) $page->versions()->lockForUpdate()->max('version_number')) + 1;

            /** @var CmsPageVersion */
            return $page->versions()->create([
                'version_number' => $nextVersion,
                'snapshot' => $this->snapshot($page),
                'change_summary' => $summary !== null ? trim($summary) : null,
                'created_by_id' => $actor?->getAuthIdentifier(),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CmsPage $page): array
    {
        return [
            'page' => [
                'id' => (int) $page->id,
                'slug' => (string) $page->slug,
                'title' => (string) $page->title,
                'excerpt' => $page->excerpt,
                'status' => $page->status->value,
                'published_at' => $page->published_at?->toAtomString(),
                'seo_title' => $page->seo_title,
                'seo_description' => $page->seo_description,
            ],
            'tags' => $page->tags
                ->map(fn ($tag): array => ['id' => (int) $tag->id, 'name' => (string) $tag->name, 'slug' => (string) $tag->slug])
                ->values()
                ->all(),
            'blocks' => $page->blocks
                ->map(fn ($block): array => [
                    'id' => (int) $block->id,
                    'type' => (string) $block->type,
                    'name' => $block->name,
                    'sort_order' => (int) $block->sort_order,
                    'data' => $block->data,
                ])
                ->values()
                ->all(),
        ];
    }
}
