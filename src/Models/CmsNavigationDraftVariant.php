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
 * A named draft parked while another variant is the working draft.
 *
 * @property int $id
 * @property int $cms_navigation_id
 * @property string $name
 * @property array<string, mixed> $snapshot
 * @property int|null $updated_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CmsNavigationDraftVariant extends Model
{
    protected $fillable = [
        'cms_navigation_id',
        'name',
        'snapshot',
        'updated_by_id',
    ];

    public function getTable(): string
    {
        return Tables::name('cms_navigation_draft_variants');
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
    public function navigation(): BelongsTo
    {
        return $this->belongsTo(Models::class('navigation'), 'cms_navigation_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'updated_by_id');
    }
}
