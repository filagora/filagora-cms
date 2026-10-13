<?php

declare(strict_types=1);

namespace ForumKit\Cms\Services;

use ForumKit\Cms\Events\CmsBlockPublished;
use ForumKit\Cms\Exceptions\DraftOperationFailed;
use ForumKit\Cms\Models\CmsBlock;
use ForumKit\Cms\Models\CmsBlockVersion;
use ForumKit\Cms\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Staged-edit workflow for standalone reusable blocks (a footer block, say). The staged copy is a
 * hidden draft row linked to the live block through `live_block_id`.
 */
class BlockDrafts
{
    private const STAGED_FIELDS = ['name', 'type', 'sort_order', 'is_published', 'data'];

    public function ensureDraft(CmsBlock $block): CmsBlock
    {
        /** @var CmsBlock|null $draft */
        $draft = $block->draftCopy()->first();

        if ($draft !== null) {
            return $draft;
        }

        /** @var CmsBlock */
        return Models::class('block')::withoutGlobalScopes()->create([
            'live_block_id' => $block->id,
            'is_draft' => true,
            'type' => $block->type,
            'name' => $block->name,
            'sort_order' => $block->sort_order,
            'is_reusable' => true,
            'is_published' => $block->is_published,
            'data' => $block->data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  Any of name, type, sort_order, is_published and data.
     */
    public function saveDraft(CmsBlock $block, array $data): CmsBlock
    {
        $draft = $this->ensureDraft($block);
        $draft->fill(Arr::only($data, self::STAGED_FIELDS));
        $draft->save();

        return $draft;
    }

    public function hasPendingChanges(CmsBlock $block): bool
    {
        /** @var CmsBlock|null $draft */
        $draft = $block->draftCopy()->first();

        if ($draft === null) {
            return false;
        }

        return $draft->type !== $block->type
            || $draft->name !== $block->name
            || (int) $draft->sort_order !== (int) $block->sort_order
            || (bool) $draft->is_published !== (bool) $block->is_published
            || $draft->data !== $block->data;
    }

    public function discard(CmsBlock $block): void
    {
        $this->staged()->where('live_block_id', $block->id)->where('is_draft', true)->delete();
    }

    /**
     * Applies the staged copy to the live block and records a version.
     *
     * @throws DraftOperationFailed When there is no staged copy.
     */
    public function publish(CmsBlock $block, ?Authenticatable $actor = null, ?string $summary = null): CmsBlockVersion
    {
        $version = DB::transaction(function () use ($block, $actor, $summary): CmsBlockVersion {
            /** @var CmsBlock|null $draft */
            $draft = $block->draftCopy()->lockForUpdate()->first();

            if ($draft === null) {
                throw DraftOperationFailed::conflict('There is no draft to publish.');
            }

            $block->forceFill([
                'type' => $draft->type,
                'name' => $draft->name,
                'sort_order' => $draft->sort_order,
                'is_published' => $draft->is_published,
                'data' => $draft->data,
            ])->save();

            $this->staged()->whereKey($draft->id)->delete();

            return $this->createVersion($block->fresh() ?? $block, $actor, $summary ?: 'Published');
        });

        CmsBlockPublished::dispatch($block->fresh() ?? $block, $version, $actor);

        return $version;
    }

    public function createVersion(CmsBlock $block, ?Authenticatable $actor = null, ?string $summary = null): CmsBlockVersion
    {
        $versions = Models::class('block_version');
        $next = ((int) $versions::query()->where('cms_block_id', $block->id)->lockForUpdate()->max('version_number')) + 1;

        /** @var CmsBlockVersion */
        return $versions::query()->create([
            'cms_block_id' => $block->id,
            'version_number' => $next,
            'snapshot' => [
                'slug' => $block->slug,
                'type' => $block->type,
                'name' => $block->name,
                'sort_order' => (int) $block->sort_order,
                'is_published' => (bool) $block->is_published,
                'data' => $block->data,
            ],
            'change_summary' => $summary,
            'created_by_id' => $actor?->getAuthIdentifier(),
            'created_at' => now(),
        ]);
    }

    /**
     * Loads a recorded version into the staged copy without touching the live block.
     *
     * @throws DraftOperationFailed When the version belongs to another block.
     */
    public function restoreVersionToDraft(CmsBlock $block, CmsBlockVersion $version): CmsBlock
    {
        if ((int) $version->cms_block_id !== (int) $block->id) {
            throw DraftOperationFailed::notFound('That version belongs to another block.');
        }

        $snapshot = $version->snapshot;

        return $this->saveDraft($block, [
            'type' => $snapshot['type'] ?? $block->type,
            'name' => $snapshot['name'] ?? null,
            'sort_order' => $snapshot['sort_order'] ?? 0,
            'is_published' => (bool) ($snapshot['is_published'] ?? false),
            'data' => $snapshot['data'] ?? null,
        ]);
    }

    /**
     * @return Builder<CmsBlock>
     */
    private function staged(): Builder
    {
        /** @var Builder<CmsBlock> */
        return Models::class('block')::withoutGlobalScopes();
    }
}
