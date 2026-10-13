<?php

declare(strict_types=1);

namespace ForumKit\Cms\Services;

use DateTimeInterface;
use ForumKit\Cms\Events\CmsNavigationPublished;
use ForumKit\Cms\Exceptions\DraftOperationFailed;
use ForumKit\Cms\Models\CmsNavigation;
use ForumKit\Cms\Models\CmsNavigationDraft;
use ForumKit\Cms\Models\CmsNavigationDraftVariant;
use ForumKit\Cms\Models\CmsNavigationVersion;
use ForumKit\Cms\Services\Concerns\NamesDraftVariants;
use ForumKit\Cms\Support\Models;
use ForumKit\Core\Support\UserModel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Staged-edit workflow for menus, the same shape as `PageDrafts`: edits accumulate in one working
 * draft per menu (its items) and reach the live menu only on publish, which records a version.
 * The menu's name is an identifier the site relies on, so it is never staged.
 */
class NavigationDrafts
{
    use NamesDraftVariants;

    /**
     * The menu's working draft, seeded from the live items on first use. A new draft takes
     * `$variantName`, or the first free "Main".
     */
    public function ensureDraft(CmsNavigation $navigation, ?Authenticatable $actor = null, ?string $variantName = null): CmsNavigationDraft
    {
        return DB::transaction(function () use ($navigation, $actor, $variantName): CmsNavigationDraft {
            $existing = $this->drafts()->where('cms_navigation_id', $navigation->id)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            /** @var CmsNavigationDraft $draft */
            $draft = $this->drafts()->create([
                'cms_navigation_id' => $navigation->id,
                'variant_name' => $variantName ?? $this->freeVariantName($this->parkedVariantNames($navigation)),
                'payload' => ['items' => $this->liveItems($navigation)],
                'base_version_id' => $this->latestVersionId($navigation),
                'is_dirty' => false,
                'updated_by_id' => $actor?->getAuthIdentifier(),
            ]);
            $navigation->unsetRelation('draft');

            return $draft;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function saveDraft(CmsNavigation $navigation, array $items, ?Authenticatable $actor = null): CmsNavigationDraft
    {
        $draft = $this->ensureDraft($navigation, $actor);
        $draft->update(['payload' => ['items' => array_values($items)], 'is_dirty' => true, 'updated_by_id' => $actor?->getAuthIdentifier()]);
        $navigation->unsetRelation('draft');

        return $draft;
    }

    /**
     * Items for an editor: the working draft's.
     *
     * @return list<array<string, mixed>>
     */
    public function editorItems(CmsNavigation $navigation, ?Authenticatable $actor = null): array
    {
        return $this->ensureDraft($navigation, $actor)->items();
    }

    // ------------------------------------------------------------------ variants

    public function activeVariantName(CmsNavigation $navigation): ?string
    {
        $name = $this->drafts()->where('cms_navigation_id', $navigation->id)->value('variant_name');

        return $name !== null ? (string) $name : null;
    }

    /**
     * Every variant name: the working draft first, then the parked ones.
     *
     * @return list<string>
     */
    public function variantNames(CmsNavigation $navigation): array
    {
        $active = $this->activeVariantName($navigation);

        return array_values(array_filter([$active, ...$this->parkedVariantNames($navigation)], fn (?string $name): bool => $name !== null));
    }

    /**
     * Makes a parked variant the working draft, parking the current one in its place.
     *
     * @throws ValidationException When the working draft has a scheduled publish.
     * @throws DraftOperationFailed When there is no such variant.
     */
    public function switchVariant(CmsNavigation $navigation, string $name, ?Authenticatable $actor = null): CmsNavigationDraft
    {
        return DB::transaction(function () use ($navigation, $name, $actor): CmsNavigationDraft {
            $current = $this->drafts()->where('cms_navigation_id', $navigation->id)->lockForUpdate()->first();

            if ($current !== null && $current->variant_name === $name) {
                return $current;
            }

            $target = $this->variants()->where('cms_navigation_id', $navigation->id)->where('name', $name)->first();

            if ($target === null) {
                throw DraftOperationFailed::notFound('There is no such draft variant.');
            }

            if ($current !== null) {
                $this->refuseWhileScheduled($current->scheduled_publish_at !== null);
                $this->storeVariant($navigation, $current, $actor);
                $current->delete();
            }

            $snapshot = $target->snapshot;

            /** @var CmsNavigationDraft $draft */
            $draft = $this->drafts()->create([
                'cms_navigation_id' => $navigation->id,
                'variant_name' => $target->name,
                'payload' => $snapshot['payload'] ?? ['items' => []],
                'base_version_id' => $snapshot['base_version_id'] ?? null,
                'is_dirty' => (bool) ($snapshot['is_dirty'] ?? false),
                'updated_by_id' => $actor?->getAuthIdentifier(),
            ]);
            $target->delete();
            $navigation->unsetRelation('draft');

            return $draft;
        });
    }

    /**
     * Starts a new variant and makes it the working draft. The current draft stays available as a
     * parked variant; the new one starts as a copy of it or, with `$fromLive`, of the live menu.
     *
     * @throws ValidationException
     */
    public function createVariant(CmsNavigation $navigation, string $name, bool $fromLive = false, ?Authenticatable $actor = null): CmsNavigationDraft
    {
        return DB::transaction(function () use ($navigation, $name, $fromLive, $actor): CmsNavigationDraft {
            $name = $this->validatedVariantName($name, $this->variantNames($navigation));
            $current = $this->drafts()->where('cms_navigation_id', $navigation->id)->lockForUpdate()->first();

            if ($current === null) {
                return $this->ensureDraft($navigation, $actor, $name);
            }

            $this->refuseWhileScheduled($current->scheduled_publish_at !== null);
            $this->storeVariant($navigation, $current, $actor);

            if ($fromLive) {
                $current->delete();
                $navigation->unsetRelation('draft');

                return $this->ensureDraft($navigation, $actor, $name);
            }

            $current->update(['variant_name' => $name, 'updated_by_id' => $actor?->getAuthIdentifier()]);
            $navigation->unsetRelation('draft');

            return $current;
        });
    }

    /**
     * @throws ValidationException
     * @throws DraftOperationFailed
     */
    public function renameVariant(CmsNavigation $navigation, string $from, string $to): void
    {
        DB::transaction(function () use ($navigation, $from, $to): void {
            $to = $this->validatedVariantName($to, array_values(array_diff($this->variantNames($navigation), [$from])));
            $draft = $this->drafts()->where('cms_navigation_id', $navigation->id)->where('variant_name', $from)->first();

            if ($draft !== null) {
                $draft->update(['variant_name' => $to]);
                $navigation->unsetRelation('draft');

                return;
            }

            $updated = $this->variants()->where('cms_navigation_id', $navigation->id)->where('name', $from)->update(['name' => $to]);

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
    public function deleteVariant(CmsNavigation $navigation, string $name): void
    {
        if ($this->activeVariantName($navigation) === $name) {
            throw ValidationException::withMessages(['name' => 'This is the working draft. Switch to another variant first, or discard the draft.']);
        }

        if ($this->variants()->where('cms_navigation_id', $navigation->id)->where('name', $name)->delete() === 0) {
            throw DraftOperationFailed::notFound('There is no such draft variant.');
        }
    }

    /**
     * The items the named variant would publish, working or parked; null when there is no such variant.
     *
     * @return list<array<string, mixed>>|null
     */
    public function itemsForVariant(CmsNavigation $navigation, string $name): ?array
    {
        $draft = $this->drafts()->where('cms_navigation_id', $navigation->id)->where('variant_name', $name)->first();

        if ($draft !== null) {
            return $draft->items();
        }

        $variant = $this->variants()->where('cms_navigation_id', $navigation->id)->where('name', $name)->first();
        $items = $variant?->snapshot['payload']['items'] ?? null;

        return is_array($items) ? array_values($items) : null;
    }

    // ------------------------------------------------------------------ discard, stale, restore

    /**
     * Drops the working draft. Parked variants stay.
     */
    public function discard(CmsNavigation $navigation): void
    {
        $this->drafts()->where('cms_navigation_id', $navigation->id)->delete();
        $navigation->unsetRelation('draft');
    }

    /**
     * True when the menu has been published since this draft was started.
     */
    public function isStale(CmsNavigation $navigation): bool
    {
        $draft = $this->drafts()->where('cms_navigation_id', $navigation->id)->first();

        return $draft !== null && $draft->base_version_id !== $this->latestVersionId($navigation);
    }

    /**
     * Loads a recorded version into the working draft without touching the live menu.
     *
     * @throws DraftOperationFailed When the version belongs to another menu.
     */
    public function restoreVersionToDraft(CmsNavigation $navigation, CmsNavigationVersion $version, ?Authenticatable $actor = null): CmsNavigationDraft
    {
        if ((int) $version->cms_navigation_id !== (int) $navigation->id) {
            throw DraftOperationFailed::notFound('That version belongs to another menu.');
        }

        return DB::transaction(function () use ($navigation, $version, $actor): CmsNavigationDraft {
            $variantName = $this->activeVariantName($navigation);
            $this->discard($navigation);
            $draft = $this->ensureDraft($navigation, $actor, $variantName);
            $items = is_array($version->snapshot['items'] ?? null) ? array_values($version->snapshot['items']) : [];
            $draft->update(['payload' => ['items' => $items], 'is_dirty' => true, 'updated_by_id' => $actor?->getAuthIdentifier()]);

            return $draft;
        });
    }

    // ------------------------------------------------------------------ publish and schedule

    /**
     * Applies the working draft to the live menu (saving it clears the menu's cache), records a
     * version and announces it.
     *
     * @throws DraftOperationFailed When there is no draft.
     */
    public function publish(CmsNavigation $navigation, ?Authenticatable $actor = null, ?string $summary = null): CmsNavigationVersion
    {
        $version = DB::transaction(function () use ($navigation, $actor, $summary): CmsNavigationVersion {
            $draft = $this->drafts()->where('cms_navigation_id', $navigation->id)->lockForUpdate()->first();

            if ($draft === null) {
                throw DraftOperationFailed::conflict('There is no draft to publish.');
            }

            $navigation->items = $draft->items();
            $navigation->save();
            $draft->delete();
            $navigation->unsetRelation('draft');

            return $this->createVersion($navigation->fresh() ?? $navigation, $actor, $summary ?: 'Published');
        });

        CmsNavigationPublished::dispatch($navigation->fresh() ?? $navigation, $version, $actor);

        return $version;
    }

    public function schedule(CmsNavigation $navigation, DateTimeInterface $at, ?Authenticatable $actor = null, ?string $summary = null): CmsNavigationDraft
    {
        $draft = $this->ensureDraft($navigation, $actor);
        $draft->update([
            'scheduled_publish_at' => $at,
            'scheduled_summary' => $summary,
            'scheduled_by_id' => $actor?->getAuthIdentifier(),
        ]);

        return $draft;
    }

    public function cancelSchedule(CmsNavigation $navigation): void
    {
        $this->drafts()->where('cms_navigation_id', $navigation->id)->update([
            'scheduled_publish_at' => null,
            'scheduled_summary' => null,
            'scheduled_by_id' => null,
        ]);
    }

    /**
     * Publishes every draft whose schedule has arrived and returns how many went out. A draft
     * that fails has its schedule cleared (so it does not retry every minute) and the failure
     * reported; the rest still go out.
     */
    public function publishDue(): int
    {
        $published = 0;
        $userModel = UserModel::class();

        $this->drafts()
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', now())
            ->with('navigation')
            ->get()
            ->each(function (CmsNavigationDraft $draft) use (&$published, $userModel): void {
                $actor = $draft->scheduled_by_id ? $userModel::query()->find($draft->scheduled_by_id) : null;
                $navigation = $draft->navigation;

                if (! $navigation instanceof CmsNavigation) {
                    return;
                }

                try {
                    $this->publish($navigation, $actor instanceof Authenticatable ? $actor : null, $draft->scheduled_summary ?: 'Scheduled publish');
                    $published++;
                } catch (Throwable $e) {
                    $draft->update(['scheduled_publish_at' => null]);
                    report($e);
                }
            });

        return $published;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @return list<string>
     */
    private function parkedVariantNames(CmsNavigation $navigation): array
    {
        return $this->variants()->where('cms_navigation_id', $navigation->id)->orderBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->all();
    }

    /**
     * Saves the working draft as a parked variant, replacing any of that name.
     */
    private function storeVariant(CmsNavigation $navigation, CmsNavigationDraft $draft, ?Authenticatable $actor): void
    {
        $this->variants()->updateOrCreate(
            ['cms_navigation_id' => $navigation->id, 'name' => $draft->variant_name],
            [
                'updated_by_id' => $actor?->getAuthIdentifier(),
                'snapshot' => [
                    'payload' => $draft->payload,
                    'base_version_id' => $draft->base_version_id,
                    'is_dirty' => $draft->is_dirty,
                ],
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liveItems(CmsNavigation $navigation): array
    {
        return is_array($navigation->items) ? array_values($navigation->items) : [];
    }

    private function latestVersionId(CmsNavigation $navigation): ?int
    {
        $id = $this->versions()->where('cms_navigation_id', $navigation->id)->max('id');

        return $id !== null ? (int) $id : null;
    }

    private function createVersion(CmsNavigation $navigation, ?Authenticatable $actor, ?string $summary): CmsNavigationVersion
    {
        $next = ((int) $this->versions()->where('cms_navigation_id', $navigation->id)->lockForUpdate()->max('version_number')) + 1;

        /** @var CmsNavigationVersion */
        return $this->versions()->create([
            'cms_navigation_id' => $navigation->id,
            'version_number' => $next,
            'snapshot' => ['name' => (string) $navigation->name, 'items' => $this->liveItems($navigation)],
            'change_summary' => $summary !== null ? trim($summary) : null,
            'created_by_id' => $actor?->getAuthIdentifier(),
            'created_at' => now(),
        ]);
    }

    /**
     * @return Builder<CmsNavigationDraft>
     */
    private function drafts(): Builder
    {
        /** @var Builder<CmsNavigationDraft> */
        return Models::class('navigation_draft')::query();
    }

    /**
     * @return Builder<CmsNavigationDraftVariant>
     */
    private function variants(): Builder
    {
        /** @var Builder<CmsNavigationDraftVariant> */
        return Models::class('navigation_draft_variant')::query();
    }

    /**
     * @return Builder<CmsNavigationVersion>
     */
    private function versions(): Builder
    {
        /** @var Builder<CmsNavigationVersion> */
        return Models::class('navigation_version')::query();
    }
}
