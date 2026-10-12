<?php

declare(strict_types=1);

namespace ForumKit\Cms\Models;

use ForumKit\Cms\Support\Models;
use ForumKit\Core\Support\Tables;
use ForumKit\Core\Support\UserModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named draft parked while another variant is the working draft.
 *
 * @property int $id
 * @property int $cms_page_id
 * @property string $name
 * @property array<string, mixed> $snapshot
 * @property int|null $updated_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CmsPageDraftVariant extends Model
{
    protected $fillable = [
        'cms_page_id',
        'name',
        'snapshot',
        'updated_by_id',
    ];

    public function getTable(): string
    {
        return Tables::name('cms_page_draft_variants');
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
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
     * @return BelongsTo<Model, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'updated_by_id');
    }
}
