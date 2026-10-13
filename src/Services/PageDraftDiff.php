<?php

declare(strict_types=1);

namespace ForumKit\Cms\Services;

use ForumKit\Cms\Models\CmsBlock;
use ForumKit\Cms\Models\CmsPage;
use ForumKit\Cms\Support\Models;
use Illuminate\Support\Str;

/**
 * Describes what publishing would change: the working draft compared with live content.
 */
class PageDraftDiff
{
    private const FIELD_LABELS = [
        'title' => 'Title',
        'slug' => 'Slug',
        'custom_route' => 'Custom route',
        'excerpt' => 'Excerpt',
        'seo_title' => 'SEO title',
        'seo_description' => 'SEO description',
    ];

    /**
     * @return array{
     *     fields: list<array{label: string, before: string, after: string}>,
     *     tags: array{added: list<string>, removed: list<string>},
     *     blocks: list<array{status: string, label: string, details: list<string>}>,
     *     count: int
     * }
     */
    public function forPage(CmsPage $page): array
    {
        $draft = $page->draft()->first();

        if ($draft === null) {
            return ['fields' => [], 'tags' => ['added' => [], 'removed' => []], 'blocks' => [], 'count' => 0];
        }

        $fields = $this->fieldChanges($page, $draft->payload['page'] ?? []);
        $tags = $this->tagChanges($page, $draft->payload['tag_ids'] ?? []);
        $blocks = $this->blockChanges($page);

        return [
            'fields' => $fields,
            'tags' => $tags,
            'blocks' => $blocks,
            'count' => count($fields) + count($tags['added']) + count($tags['removed']) + count($blocks),
        ];
    }

    /**
     * "New", "Edited" or null for a staged block, relative to its live counterpart.
     */
    public function blockStatus(CmsBlock $stagedBlock, ?CmsBlock $live): ?string
    {
        if ($live === null) {
            return 'New';
        }

        return $this->blockDifferences($stagedBlock, $live) === [] ? null : 'Edited';
    }

    /**
     * @param  array<string, mixed>  $staged
     * @return list<array{label: string, before: string, after: string}>
     */
    private function fieldChanges(CmsPage $page, array $staged): array
    {
        $changes = [];

        foreach (self::FIELD_LABELS as $field => $label) {
            if (! array_key_exists($field, $staged)) {
                continue;
            }

            $before = trim((string) ($page->{$field} ?? ''));
            $after = trim((string) ($staged[$field] ?? ''));

            if ($before !== $after) {
                $changes[] = ['label' => $label, 'before' => $this->excerpt($before), 'after' => $this->excerpt($after)];
            }
        }

        return $changes;
    }

    /**
     * @param  list<int|string>  $stagedIds
     * @return array{added: list<string>, removed: list<string>}
     */
    private function tagChanges(CmsPage $page, array $stagedIds): array
    {
        $tags = Models::class('tag');
        $liveIds = $page->tags()->pluck($tags::make()->getQualifiedKeyName())->map(fn ($id): int => (int) $id)->all();
        $stagedIds = array_map('intval', $stagedIds);
        $names = fn (array $ids): array => array_values($tags::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all());

        return [
            'added' => $names(array_values(array_diff($stagedIds, $liveIds))),
            'removed' => $names(array_values(array_diff($liveIds, $stagedIds))),
        ];
    }

    /**
     * @return list<array{status: string, label: string, details: list<string>}>
     */
    private function blockChanges(CmsPage $page): array
    {
        $live = $page->blocks()->get()->keyBy('id');
        $changes = [];
        $kept = [];

        foreach ($page->draftBlocks()->get() as $stagedBlock) {
            $liveBlock = $live->get($stagedBlock->live_block_id);

            if ($liveBlock === null) {
                $changes[] = ['status' => 'Added', 'label' => $this->blockLabel($stagedBlock), 'details' => []];

                continue;
            }

            $kept[] = $liveBlock->id;
            $details = $this->blockDifferences($stagedBlock, $liveBlock);

            if ($details !== []) {
                $changes[] = ['status' => 'Edited', 'label' => $this->blockLabel($stagedBlock), 'details' => $details];
            }
        }

        foreach ($live as $liveBlock) {
            if (! in_array($liveBlock->id, $kept, true)) {
                $changes[] = ['status' => 'Removed', 'label' => $this->blockLabel($liveBlock), 'details' => []];
            }
        }

        return $changes;
    }

    /**
     * @return list<string>
     */
    private function blockDifferences(CmsBlock $stagedBlock, CmsBlock $live): array
    {
        $details = [];

        if ($stagedBlock->type !== $live->type) {
            $details[] = 'Type changed';
        }

        if ((string) $stagedBlock->name !== (string) $live->name) {
            $details[] = 'Name changed';
        }

        if ((int) $stagedBlock->sort_order !== (int) $live->sort_order) {
            $details[] = 'Display order changed ('.(int) $live->sort_order.' → '.(int) $stagedBlock->sort_order.')';
        }

        $before = is_array($live->data) ? $live->data : [];
        $after = is_array($stagedBlock->data) ? $stagedBlock->data : [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $details[] = Str::headline((string) $key).' changed';
            }
        }

        return $details;
    }

    private function blockLabel(CmsBlock $block): string
    {
        $type = Str::headline($block->type);

        return filled($block->name) ? $block->name.' ('.$type.')' : $type;
    }

    private function excerpt(string $value): string
    {
        return $value === '' ? '(empty)' : Str::limit($value, 80);
    }
}
