<?php

declare(strict_types=1);

namespace ForumKit\Cms\Services;

use ForumKit\Cms\BlockTypes\BlockTypeRegistry;
use ForumKit\Cms\Models\CmsBlock;
use ForumKit\Cms\Models\CmsMediaAsset;
use ForumKit\Cms\Models\CmsPage;
use ForumKit\Cms\Support\Models;

/**
 * Which live blocks use a media asset, found through the media paths each block type declares.
 */
class MediaAssetUsage
{
    public function __construct(private readonly BlockTypeRegistry $types) {}

    /**
     * @return list<array{block_id: int, block_type: string, block_name: string|null, block_slug: string|null, is_reusable: bool, page_id: int|null, page_title: string|null, page_slug: string|null}>
     */
    public function forAsset(CmsMediaAsset|int $asset): array
    {
        $assetId = $asset instanceof CmsMediaAsset ? (int) $asset->id : $asset;

        if ($assetId <= 0) {
            return [];
        }

        $typeKeys = array_keys(array_filter($this->types->all(), static fn ($type): bool => $type->mediaPaths !== []));

        if ($typeKeys === []) {
            return [];
        }

        $entries = [];

        /** @var CmsBlock $block */
        foreach (Models::class('block')::query()->with('page:id,title,slug')->whereIn('type', $typeKeys)->get() as $block) {
            if (! $this->types->usesAsset($block->type, is_array($block->data) ? $block->data : [], $assetId)) {
                continue;
            }

            $page = $block->page;

            $entries[] = [
                'block_id' => (int) $block->id,
                'block_type' => $block->type,
                'block_name' => $block->name,
                'block_slug' => $block->slug,
                'is_reusable' => (bool) $block->is_reusable,
                'page_id' => $page instanceof CmsPage ? (int) $page->id : null,
                'page_title' => $page instanceof CmsPage ? $page->title : null,
                'page_slug' => $page instanceof CmsPage ? $page->slug : null,
            ];
        }

        return $entries;
    }

    public function countForAsset(CmsMediaAsset|int $asset): int
    {
        return count($this->forAsset($asset));
    }
}
