<?php

declare(strict_types=1);

namespace Filagora\Cms\Models;

use Filagora\Cms\Support\Models;
use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable snapshot taken when content is published. No `updated_at`; `created_at` is stamped
 * by the writer (or, for pages, the database).
 *
 * @property int $id
 * @property int $cms_block_id
 * @property int $version_number
 * @property array<string, mixed> $snapshot
 * @property string|null $change_summary
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 */
class CmsBlockVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cms_block_id',
        'version_number',
        'snapshot',
        'change_summary',
        'created_by_id',
        'created_at',
    ];

    public function getTable(): string
    {
        return Tables::name('cms_block_versions');
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(Models::class('block'), 'cms_block_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'created_by_id');
    }
}
