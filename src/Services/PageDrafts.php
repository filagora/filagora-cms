<?php

declare(strict_types=1);

namespace Filagora\Cms\Services;

use DateTimeInterface;
use Filagora\Cms\Enums\CmsPageStatus;
use Filagora\Cms\Events\CmsPagePublished;
use Filagora\Cms\Exceptions\DraftOperationFailed;
use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Models\CmsPageDraft;
use Filagora\Cms\Models\CmsPageDraftVariant;
use Filagora\Cms\Models\CmsPageVersion;
use Filagora\Cms\Services\Concerns\NamesDraftVariants;
use Filagora\Cms\Support\Models;
use Filagora\Core\Support\UserModel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staged-edit workflow for pages: edits accumulate in a working draft (a payload row plus staged
 * block rows) and reach live content only on publish. A page has one working draft; other named
 * variants are parked beside it and swapped in with `switchVariant`.
 */
class PageDrafts
{
    use NamesDraftVariants;

    /** The page fields a draft carries. */
    public const DRAFT_FIELDS = [
        'slug',
        'custom_route',
        'title',
        'excerpt',
        'seo_title',
        'seo_description',
    ];

    private static bool $publishing = false;

    public function __construct(private readonly PageVersioning $versioning) {}

    /**
     * True while a draft is being applied, so listeners reacting to each saved row (search
     * indexing, for example) can wait for the `CmsPagePublished` event instead.
     */
    public static function isPublishing(): bool
    {
        return self::$publishing;
    }

    /**
     * The page's working draft, seeded from live content on first use. A new draft takes
     * `$variantName`, or the first free "Main".
     */
    public function ensureDraft(CmsPage $page, ?Authenticatable $actor = null, ?string $variantName = null): CmsPageDraft
    {
        return DB::transaction(function () use ($page, $actor, $variantName): CmsPageDraft {
            $existing = $this->drafts()->where('cms_page_id', $page->id)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            /** @var CmsPageDraft $draft */
            $draft = $this->drafts()->create([
                'cms_page_id' => $page->id,
                'variant_name' => $variantName ?? $this->freeVariantName($this->parkedVariantNames($page)),
                'payload' => $this->livePayload($page),
                'base_version_id' => $this->latestVersionId($page),
                'is_dirty' => false,
                'updated_by_id' => $actor?->getAuthIdentifier(),
            ]);

            foreach ($page->blocks()->get() as $block) {
                $page->draftBlocks()->create([
                    'live_block_id' => $block->id,
                    'type' => $block->type,
                    'name' => $block->name,
                    'sort_order' => $block->sort_order,
                    'is_reusable' => false,
                    'is_published' => false,
                    'data' => $block->data,
                ]);
            }

            $page->unsetRelation('draft');

            return $draft;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Any of the draft fields, and `tag_ids`.
     */
    public function saveDraftFields(CmsPage $page, array $data, ?Authenticatable $actor = null): CmsPageDraft
    {
        $draft = $this->ensureDraft($page, $actor);
        $payload = $draft->payload;

        foreach (self::DRAFT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $payload['page'][$field] = $data[$field];
            }
        }

        if (array_key_exists('tag_ids', $data)) {
            $payload['tag_ids'] = array_values(array_unique(array_map('intval', (array) $data['tag_ids'])));
        }

        $draft->update(['payload' => $payload, 'is_dirty' => true, 'updated_by_id' => $actor?->getAuthIdentifier()]);

        return $draft;
    }

    /**
     * Form state for an editor: the draft's values, with `tag_ids`.
     *
     * @return array<string, mixed>
     */
    public function formState(CmsPage $page, ?Authenticatable $actor = null): array
    {
        $payload = $this->ensureDraft($page, $actor)->payload;

        return array_merge($payload['page'] ?? [], ['tag_ids' => $payload['tag_ids'] ?? []]);
    }

    public function markDirty(int $pageId): void
    {
        $this->drafts()->where('cms_page_id', $pageId)->update(['is_dirty' => true]);
    }

    // ------------------------------------------------------------------ variants

    /**
     * The working draft's variant name, or null when the page has no draft.
     */
    public function activeVariantName(CmsPage $page): ?string
    {
        $name = $this->drafts()->where('cms_page_id', $page->id)->value('variant_name');

        return $name !== null ? (string) $name : null;
    }

    /**
     * Every variant name: the working draft first, then the parked ones.
     *
     * @return list<string>
     */
    public function variantNames(CmsPage $page): array
    {
        $active = $this->activeVariantName($page);

        return array_values(array_filter([$active, ...$this->parkedVariantNames($page)], fn (?string $name): bool => $name !== null));
    }

    /**
     * Makes a parked variant the working draft, parking the current one in its place.
     *
     * @throws ValidationException When the working draft has a scheduled publish.
     * @throws DraftOperationFailed When there is no such variant.
     */
    public function switchVariant(CmsPage $page, string $name, ?Authenticatable $actor = null): CmsPageDraft
    {
        return DB::transaction(function () use ($page, $name, $actor): CmsPageDraft {
            $current = $this->drafts()->where('cms_page_id', $page->id)->lockForUpdate()->first();

            if ($current !== null && $current->variant_name === $name) {
                return $current;
            }

            $target = $this->variants()->where('cms_page_id', $page->id)->where('name', $name)->first();

            if ($target === null) {
                throw DraftOperationFailed::notFound('There is no such draft variant.');
            }

            if ($current !== null) {
                $this->refuseWhileScheduled($current->scheduled_publish_at !== null);
                $this->parkActive($page, $current, $actor);
            }

            $draft = $this->loadVariant($page, $target, $actor);
            $target->delete();
            $page->unsetRelation('draft');

            return $draft;
        });
    }

    /**
     * Starts a new variant and makes it the working draft. The current draft stays available as
     * a parked variant; the new one starts as a copy of it or, with `$fromLive`, of the live page.
     *
     * @throws ValidationException
     */
    public function createVariant(CmsPage $page, string $name, bool $fromLive = false, ?Authenticatable $actor = null): CmsPageDraft
    {
        return DB::transaction(function () use ($page, $name, $fromLive, $actor): CmsPageDraft {
            $name = $this->validatedVariantName($name, $this->variantNames($page));
            $current = $this->drafts()->where('cms_page_id', $page->id)->lockForUpdate()->first();

            if ($current === null) {
                return $this->ensureDraft($page, $actor, $name);
            }

            $this->refuseWhileScheduled($current->scheduled_publish_at !== null);

            if ($fromLive) {
                $this->parkActive($page, $current, $actor);

                return $this->ensureDraft($page, $actor, $name);
            }

            $this->storeVariant($page, $current, $actor);
            $current->update(['variant_name' => $name, 'updated_by_id' => $actor?->getAuthIdentifier()]);
            $page->unsetRelation('draft');

            return $current;
        });
    }

    /**
     * @throws ValidationException
     * @throws DraftOperationFailed
     */
    public function renameVariant(CmsPage $page, string $from, string $to): void
    {
        DB::transaction(function () use ($page, $from, $to): void {
            $to = $this->validatedVariantName($to, array_values(array_diff($this->variantNames($page), [$from])));
            $draft = $this->drafts()->where('cms_page_id', $page->id)->where('variant_name', $from)->first();

            if ($draft !== null) {
                $draft->update(['variant_name' => $to]);
                $page->unsetRelation('draft');

                return;
            }

            $updated = $this->variants()->where('cms_page_id', $page->id)->where('name', $from)->update(['name' => $to]);

            if ($updated === 0) {
                throw DraftOperationFailed::notFound('There is no such draft variant.');
            }
        });
    }

    /**
     * Deletes a parked variant. The working draft is removed with `discard()`.
     *
     * @throws ValidationException When it is the working draft.
     * @throws DraftOperationFailed
     */
    public function deleteVariant(CmsPage $page, string $name): void
    {
        if ($this->activeVariantName($page) === $name) {
            throw ValidationException::withMessages(['name' => 'This is the working draft. Switch to another variant first, or discard the draft.']);
        }

        if ($this->variants()->where('cms_page_id', $page->id)->where('name', $name)->delete() === 0) {
            throw DraftOperationFailed::notFound('There is no such draft variant.');
        }
    }

    // ------------------------------------------------------------------ discard, stale, restore

    /**
     * Drops the working draft and its staged blocks. Parked variants stay.
     */
    public function discard(CmsPage $page): void
    {
        DB::transaction(function () use ($page): void {
            $this->deleteStagedBlocks($page);
            $this->drafts()->where('cms_page_id', $page->id)->delete();
            $page->unsetRelation('draft');
        });
    }

    /**
     * True when the page has been published since this draft was started.
     */
    public function isStale(CmsPage $page): bool
    {
        $draft = $this->drafts()->where('cms_page_id', $page->id)->first();

        return $draft !== null && $draft->base_version_id !== $this->latestVersionId($page);
    }

    /**
     * Loads a recorded version into the working draft without touching live content.
     *
     * @throws DraftOperationFailed When the version belongs to another page.
     */
    public function restoreVersionToDraft(CmsPage $page, CmsPageVersion $version, ?Authenticatable $actor = null): CmsPageDraft
    {
        if ((int) $version->cms_page_id !== (int) $page->id) {
            throw DraftOperationFailed::notFound('That version belongs to another page.');
        }

        return DB::transaction(function () use ($page, $version, $actor): CmsPageDraft {
            $variantName = $this->activeVariantName($page);
            $this->discard($page);
            $draft = $this->ensureDraft($page, $actor, $variantName);
            $snapshot = $version->snapshot;
            $pageSnapshot = is_array($snapshot['page'] ?? null) ? $snapshot['page'] : [];
            $payload = $draft->payload;

            foreach (self::DRAFT_FIELDS as $field) {
                if (array_key_exists($field, $pageSnapshot)) {
                    $payload['page'][$field] = $pageSnapshot[$field];
                }
            }

            $slugs = array_values(array_filter(array_column((array) ($snapshot['tags'] ?? []), 'slug')));
            $tagModel = Models::class('tag');
            $payload['tag_ids'] = $tagModel::query()->whereIn('slug', $slugs)->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $liveIds = $page->blocks()->pluck('id')->all();
            $page->draftBlocks()->delete();

            foreach ($snapshot['blocks'] ?? [] as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $page->draftBlocks()->create([
                    'live_block_id' => in_array($block['id'] ?? null, $liveIds, true) ? $block['id'] : null,
                    'type' => (string) ($block['type'] ?? ''),
                    'name' => isset($block['name']) ? (string) $block['name'] : null,
                    'sort_order' => (int) ($block['sort_order'] ?? 0),
                    'is_reusable' => false,
                    'is_published' => false,
                    'data' => $block['data'] ?? null,
                ]);
            }

            $draft->update(['payload' => $payload, 'is_dirty' => true, 'updated_by_id' => $actor?->getAuthIdentifier()]);

            return $draft;
        });
    }

    // ------------------------------------------------------------------ publish and schedule

    /**
     * Applies the working draft to the live page, records a version, and announces it.
     *
     * @throws ValidationException When another page already uses the draft's slug or custom route.
     * @throws DraftOperationFailed When there is no draft.
     */
    public function publish(CmsPage $page, ?Authenticatable $actor = null, ?string $summary = null): CmsPageVersion
    {
        $this->assertSlugsAvailable($page);
        self::$publishing = true;

        try {
            $version = DB::transaction(function () use ($page, $actor, $summary): CmsPageVersion {
                $draft = $this->drafts()->where('cms_page_id', $page->id)->lockForUpdate()->first();

                if ($draft === null) {
                    throw DraftOperationFailed::conflict('There is no draft to publish.');
                }

                $page->fill(Arr::only($draft->payload['page'] ?? [], self::DRAFT_FIELDS));
                $page->status = CmsPageStatus::PUBLISHED;

                if ($page->published_at === null || $page->published_at->isFuture()) {
                    $page->published_at = now();
                }

                $page->save();
                $page->tags()->sync($draft->payload['tag_ids'] ?? []);
                $this->applyStagedBlocks($page);
                $this->deleteStagedBlocks($page);
                $draft->delete();

                $page->unsetRelation('blocks');
                $page->unsetRelation('draft');

                return $this->versioning->createSnapshot($page->fresh() ?? $page, $actor, $summary ?: 'Published');
            });
        } finally {
            self::$publishing = false;
        }

        CmsPagePublished::dispatch($page->fresh() ?? $page, $version, $actor);

        return $version;
    }

    /**
     * Sets the working draft to publish itself at `$at` (see `publishDue()`).
     */
    public function schedule(CmsPage $page, DateTimeInterface $at, ?Authenticatable $actor = null, ?string $summary = null): CmsPageDraft
    {
        $draft = $this->ensureDraft($page, $actor);
        $draft->update([
            'scheduled_publish_at' => $at,
            'scheduled_summary' => $summary,
            'scheduled_by_id' => $actor?->getAuthIdentifier(),
        ]);

        return $draft;
    }

    public function cancelSchedule(CmsPage $page): void
    {
        $this->drafts()->where('cms_page_id', $page->id)->update([
            'scheduled_publish_at' => null,
            'scheduled_summary' => null,
            'scheduled_by_id' => null,
        ]);
    }

    /**
     * Publishes every draft whose schedule has arrived and returns how many went out. A draft
     * that can no longer be published (its slug was taken meanwhile) has its schedule cleared and
     * the problem reported, so one bad draft never blocks the rest.
     */
    public function publishDue(): int
    {
        $published = 0;
        $userModel = UserModel::class();

        $this->drafts()
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', now())
            ->with('page')
            ->get()
            ->each(function (CmsPageDraft $draft) use (&$published, $userModel): void {
                $actor = $draft->scheduled_by_id ? $userModel::query()->find($draft->scheduled_by_id) : null;
                $page = $draft->page;

                if (! $page instanceof CmsPage) {
                    return;
                }

                try {
                    $this->publish($page, $actor instanceof Authenticatable ? $actor : null, $draft->scheduled_summary ?: 'Scheduled publish');
                    $published++;
                } catch (ValidationException $e) {
                    $draft->update(['scheduled_publish_at' => null]);
                    report($e);
                }
            });

        return $published;
    }

    // ------------------------------------------------------------------ preview

    /**
     * An unsaved in-memory copy of the page as it would look once the working draft is published.
     */
    public function previewPage(CmsPage $page): CmsPage
    {
        $draft = $this->drafts()->where('cms_page_id', $page->id)->first();

        if ($draft === null) {
            return $page->loadMissing(['blocks', 'tags:id,name,slug']);
        }

        return $this->buildPreview($page, $draft->payload, $page->draftBlocks()->get());
    }

    /**
     * The page as it would look if the named variant were published, whether it is the working
     * draft or parked; null when the page has no such variant.
     */
    public function previewPageVariant(CmsPage $page, string $name): ?CmsPage
    {
        if ($this->activeVariantName($page) === $name) {
            return $this->previewPage($page);
        }

        $variant = $this->variants()->where('cms_page_id', $page->id)->where('name', $name)->first();

        if ($variant === null) {
            return null;
        }

        $blockModel = Models::class('block');
        $staged = array_filter((array) ($variant->snapshot['blocks'] ?? []), 'is_array');
        usort($staged, static fn (array $a, array $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));

        /** @var Collection<int, CmsBlock> $blocks */
        $blocks = new Collection;

        foreach ($staged as $block) {
            /** @var CmsBlock $model */
            $model = (new $blockModel)->forceFill([
                'cms_page_id' => $page->id,
                'type' => (string) ($block['type'] ?? ''),
                'name' => isset($block['name']) ? (string) $block['name'] : null,
                'sort_order' => (int) ($block['sort_order'] ?? 0),
                'is_reusable' => false,
                'is_published' => false,
                'data' => $block['data'] ?? null,
            ]);
            $blocks->push($model);
        }

        return $this->buildPreview($page, $variant->snapshot['payload'] ?? [], $blocks);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, CmsBlock>  $blocks
     */
    private function buildPreview(CmsPage $page, array $payload, Collection $blocks): CmsPage
    {
        $preview = $page->replicate();
        $preview->forceFill(['id' => $page->id])->fill(Arr::only($payload['page'] ?? [], self::DRAFT_FIELDS));
        $preview->exists = true;
        $preview->syncOriginal();
        $preview->status = CmsPageStatus::PUBLISHED;
        $preview->published_at = $page->published_at ?? now();
        $preview->setRelation('blocks', $blocks);
        $tagModel = Models::class('tag');
        $preview->setRelation('tags', $tagModel::query()->whereIn('id', $payload['tag_ids'] ?? [])->get(['id', 'name', 'slug']));

        return $preview;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @return list<string>
     */
    private function parkedVariantNames(CmsPage $page): array
    {
        return $this->variants()->where('cms_page_id', $page->id)->orderBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->all();
    }

    /**
     * Saves the working draft (fields and blocks) as a parked variant, replacing any of that name.
     */
    private function storeVariant(CmsPage $page, CmsPageDraft $draft, ?Authenticatable $actor): void
    {
        $this->variants()->updateOrCreate(
            ['cms_page_id' => $page->id, 'name' => $draft->variant_name],
            [
                'updated_by_id' => $actor?->getAuthIdentifier(),
                'snapshot' => [
                    'payload' => $draft->payload,
                    'base_version_id' => $draft->base_version_id,
                    'is_dirty' => $draft->is_dirty,
                    'blocks' => $page->draftBlocks()->get()->map(fn (CmsBlock $block): array => [
                        'live_block_id' => $block->live_block_id,
                        'type' => $block->type,
                        'name' => $block->name,
                        'sort_order' => $block->sort_order,
                        'data' => $block->data,
                    ])->all(),
                ],
            ],
        );
    }

    /**
     * Parks the working draft and removes it, leaving the page with no working draft.
     */
    private function parkActive(CmsPage $page, CmsPageDraft $draft, ?Authenticatable $actor): void
    {
        $this->storeVariant($page, $draft, $actor);
        $this->deleteStagedBlocks($page);
        $draft->delete();
    }

    /**
     * Turns a parked variant back into the working draft and its staged blocks.
     */
    private function loadVariant(CmsPage $page, CmsPageDraftVariant $variant, ?Authenticatable $actor): CmsPageDraft
    {
        $snapshot = $variant->snapshot;
        $liveIds = $page->blocks()->pluck('id')->all();

        /** @var CmsPageDraft $draft */
        $draft = $this->drafts()->create([
            'cms_page_id' => $page->id,
            'variant_name' => $variant->name,
            'payload' => $snapshot['payload'] ?? [],
            'base_version_id' => $snapshot['base_version_id'] ?? null,
            'is_dirty' => (bool) ($snapshot['is_dirty'] ?? false),
            'updated_by_id' => $actor?->getAuthIdentifier(),
        ]);

        foreach ($snapshot['blocks'] ?? [] as $block) {
            $page->draftBlocks()->create([
                'live_block_id' => in_array($block['live_block_id'] ?? null, $liveIds, true) ? $block['live_block_id'] : null,
                'type' => (string) ($block['type'] ?? ''),
                'name' => isset($block['name']) ? (string) $block['name'] : null,
                'sort_order' => (int) ($block['sort_order'] ?? 0),
                'is_reusable' => false,
                'is_published' => false,
                'data' => $block['data'] ?? null,
            ]);
        }

        return $draft;
    }

    /**
     * @return array<string, mixed>
     */
    private function livePayload(CmsPage $page): array
    {
        return [
            'page' => array_combine(self::DRAFT_FIELDS, array_map(fn (string $field): mixed => $page->{$field}, self::DRAFT_FIELDS)),
            'tag_ids' => $page->tags()->pluck(Models::class('tag')::make()->getQualifiedKeyName())->map(fn ($id): int => (int) $id)->all(),
        ];
    }

    private function latestVersionId(CmsPage $page): ?int
    {
        $id = $this->versions()->where('cms_page_id', $page->id)->max('id');

        return $id !== null ? (int) $id : null;
    }

    private function deleteStagedBlocks(CmsPage $page): void
    {
        Models::class('block')::withoutGlobalScopes()
            ->where('cms_page_id', $page->id)
            ->where('is_draft', true)
            ->delete();
    }

    /**
     * Writes the staged blocks onto the live ones: a staged copy updates the block it was copied
     * from, one with no live counterpart is added, and a live block with no staged copy is removed.
     */
    private function applyStagedBlocks(CmsPage $page): void
    {
        $staged = $page->draftBlocks()->get();
        $live = $page->blocks()->get()->keyBy('id');
        $keep = [];

        foreach ($staged as $stagedBlock) {
            $attributes = [
                'type' => $stagedBlock->type,
                'name' => $stagedBlock->name,
                'sort_order' => $stagedBlock->sort_order,
                'data' => $stagedBlock->data,
            ];
            $target = $live->get($stagedBlock->live_block_id);

            if ($target !== null) {
                $target->update($attributes);
            } else {
                $target = $page->blocks()->create($attributes + ['is_reusable' => false, 'is_published' => false]);
            }

            $keep[] = $target->id;
        }

        foreach ($live as $block) {
            if (! in_array($block->id, $keep, true)) {
                $block->delete();
            }
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertSlugsAvailable(CmsPage $page): void
    {
        $fields = $this->drafts()->where('cms_page_id', $page->id)->first()?->payload['page'] ?? [];
        $messages = [];
        $slug = $fields['slug'] ?? null;
        $pages = Models::class('page');

        if (is_string($slug) && $pages::query()->where('slug', $slug)->whereKeyNot($page->id)->exists()) {
            $messages['slug'] = 'Another page already uses this slug.';
        }

        $rawRoute = trim((string) ($fields['custom_route'] ?? ''));

        if ($rawRoute !== '' && $pages::query()->where('custom_route', '/'.trim($rawRoute, '/'))->whereKeyNot($page->id)->exists()) {
            $messages['custom_route'] = 'Another page already uses this custom route.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @return Builder<CmsPageDraft>
     */
    private function drafts(): Builder
    {
        /** @var Builder<CmsPageDraft> */
        return Models::class('page_draft')::query();
    }

    /**
     * @return Builder<CmsPageDraftVariant>
     */
    private function variants(): Builder
    {
        /** @var Builder<CmsPageDraftVariant> */
        return Models::class('page_draft_variant')::query();
    }

    /**
     * @return Builder<CmsPageVersion>
     */
    private function versions(): Builder
    {
        /** @var Builder<CmsPageVersion> */
        return Models::class('page_version')::query();
    }
}
