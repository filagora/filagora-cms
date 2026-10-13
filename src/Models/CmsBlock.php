<?php

declare(strict_types=1);

namespace Filagora\Cms\Models;

use Filagora\Cms\Models\Scopes\LiveBlockScope;
use Filagora\Cms\Support\Models;
use Filagora\Core\Support\Tables;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A content block on a page, or a reusable block with its own slug. `type` is a free string: a
 * block type registry gives it meaning, and a value the registry does not know still loads.
 *
 * @property int $id
 * @property int|null $cms_page_id
 * @property string|null $slug
 * @property string $type
 * @property string|null $name
 * @property int $sort_order
 * @property bool $is_reusable
 * @property bool $is_published
 * @property bool $is_draft
 * @property int|null $live_block_id
 * @property array<string, mixed>|null $data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([LiveBlockScope::class])]
class CmsBlock extends Model
{
    protected $fillable = [
        'cms_page_id',
        'slug',
        'type',
        'name',
        'sort_order',
        'is_reusable',
        'is_published',
        'is_draft',
        'live_block_id',
        'data',
    ];

    protected $hidden = [
        'is_draft',
        'live_block_id',
    ];

    public function getTable(): string
    {
        return Tables::name('cms_blocks');
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'sort_order' => 'integer',
            'is_reusable' => 'boolean',
            'is_published' => 'boolean',
            'is_draft' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Models::class('page'), 'cms_page_id');
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Models::class('block_version'), 'cms_block_id')->orderByDesc('version_number');
    }

    /**
     * The live block a staged copy stands for.
     *
     * @return BelongsTo<static, $this>
     */
    public function liveBlock(): BelongsTo
    {
        return $this->belongsTo(static::class, 'live_block_id')->withoutGlobalScopes();
    }

    /**
     * Staged copy of a standalone reusable block, if one exists.
     *
     * @return HasOne<static, $this>
     */
    public function draftCopy(): HasOne
    {
        return $this->hasOne(static::class, 'live_block_id')
            ->withoutGlobalScopes()
            ->where('is_draft', true);
    }
}
