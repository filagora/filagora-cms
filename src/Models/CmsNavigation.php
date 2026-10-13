<?php

declare(strict_types=1);

namespace Filagora\Cms\Models;

use Filagora\Cms\Support\Models;
use Filagora\Core\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A named menu. The cached copy of its items (key `cms:navigation:{name}`) is cleared whenever
 * the menu is saved or deleted, and for the old name when it is renamed.
 *
 * @property int $id
 * @property string $name
 * @property array<int, array<string, mixed>>|null $items
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CmsNavigation extends Model
{
    public const CACHE_PREFIX = 'cms:navigation:';

    protected $fillable = [
        'name',
        'items',
    ];

    public function getTable(): string
    {
        return Tables::name('cms_navigations');
    }

    protected function casts(): array
    {
        return [
            'items' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (CmsNavigation $navigation): void {
            self::flushCache($navigation->name);
            $originalName = trim((string) $navigation->getOriginal('name'));

            if ($originalName !== '' && $originalName !== (string) $navigation->name) {
                self::flushCache($originalName);
            }
        });

        static::deleted(function (CmsNavigation $navigation): void {
            self::flushCache($navigation->name);
        });
    }

    public static function flushCache(string $name): void
    {
        $key = trim($name);

        if ($key !== '') {
            Cache::forget(self::CACHE_PREFIX.$key);
        }
    }

    /**
     * @return HasOne<CmsNavigationDraft, $this>
     */
    public function draft(): HasOne
    {
        /** @var HasOne<CmsNavigationDraft, $this> */
        return $this->hasOne(Models::class('navigation_draft'), 'cms_navigation_id');
    }

    /**
     * Named drafts parked while another variant is the working draft.
     *
     * @return HasMany<CmsNavigationDraftVariant, $this>
     */
    public function draftVariants(): HasMany
    {
        /** @var HasMany<CmsNavigationDraftVariant, $this> */
        return $this->hasMany(Models::class('navigation_draft_variant'), 'cms_navigation_id')->orderBy('name');
    }

    /**
     * @return HasMany<CmsNavigationVersion, $this>
     */
    public function versions(): HasMany
    {
        /** @var HasMany<CmsNavigationVersion, $this> */
        return $this->hasMany(Models::class('navigation_version'), 'cms_navigation_id');
    }
}
