<?php

declare(strict_types=1);

namespace Filagora\Cms\Models;

use Filagora\Cms\Enums\CmsPageStatus;
use Filagora\Cms\Support\Models;
use Filagora\Core\Support\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string|null $custom_route
 * @property string $title
 * @property string|null $excerpt
 * @property CmsPageStatus $status
 * @property Carbon|null $published_at
 * @property string|null $preview_token
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CmsPage extends Model
{
    protected $fillable = [
        'slug',
        'custom_route',
        'title',
        'excerpt',
        'status',
        'published_at',
        'preview_token',
        'seo_title',
        'seo_description',
    ];

    public function getTable(): string
    {
        return Tables::name('cms_pages');
    }

    protected function casts(): array
    {
        return [
            'status' => CmsPageStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * A custom route is kept as `/x/y` (leading slash, no trailing one) and a blank one is null.
     * A page that becomes published without a time is stamped with now.
     */
    protected static function booted(): void
    {
        static::saving(function (CmsPage $page): void {
            if (is_string($page->custom_route)) {
                $customRoute = trim($page->custom_route);
                $page->custom_route = $customRoute === '' ? null : '/'.trim($customRoute, '/');
            }

            if ($page->status === CmsPageStatus::PUBLISHED && $page->published_at === null) {
                $page->published_at = now();
            }
        });
    }

    /**
     * Live blocks, in order. Staged copies are hidden by the block model's scope.
     *
     * @return HasMany<CmsBlock, $this>
     */
    public function blocks(): HasMany
    {
        /** @var HasMany<CmsBlock, $this> */
        return $this->hasMany(Models::class('block'), 'cms_page_id')->orderBy('sort_order');
    }

    /**
     * Staged working copies of this page's blocks.
     *
     * @return HasMany<CmsBlock, $this>
     */
    public function draftBlocks(): HasMany
    {
        /** @var HasMany<CmsBlock, $this> */
        return $this->hasMany(Models::class('block'), 'cms_page_id')
            ->withoutGlobalScopes()
            ->withAttributes(['is_draft' => true])
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return HasOne<CmsPageDraft, $this>
     */
    public function draft(): HasOne
    {
        /** @var HasOne<CmsPageDraft, $this> */
        return $this->hasOne(Models::class('page_draft'), 'cms_page_id');
    }

    /**
     * Named drafts parked while another variant is the working draft.
     *
     * @return HasMany<CmsPageDraftVariant, $this>
     */
    public function draftVariants(): HasMany
    {
        /** @var HasMany<CmsPageDraftVariant, $this> */
        return $this->hasMany(Models::class('page_draft_variant'), 'cms_page_id')->orderBy('name');
    }

    /**
     * @return BelongsToMany<CmsTag, $this>
     */
    public function tags(): BelongsToMany
    {
        /** @var BelongsToMany<CmsTag, $this> */
        return $this->belongsToMany(Models::class('tag'), Tables::name('cms_page_tag'), 'cms_page_id', 'cms_tag_id');
    }

    /**
     * @return HasMany<CmsPageVersion, $this>
     */
    public function versions(): HasMany
    {
        /** @var HasMany<CmsPageVersion, $this> */
        return $this->hasMany(Models::class('page_version'), 'cms_page_id')->orderByDesc('version_number');
    }

    /**
     * Published, with a publication time that has arrived.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', CmsPageStatus::PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
