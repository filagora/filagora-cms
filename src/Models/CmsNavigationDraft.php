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
 * The working draft of a page or menu: edits accumulate here and reach live content only when
 * published. One per page or menu; other named variants are parked in the variants table.
 *
 * @property int $id
 * @property int $cms_navigation_id
 * @property string $variant_name
 * @property array<string, mixed> $payload
 * @property int|null $base_version_id
 * @property bool $is_dirty
 * @property Carbon|null $scheduled_publish_at
 * @property string|null $scheduled_summary
 * @property int|null $scheduled_by_id
 * @property int|null $updated_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CmsNavigationDraft extends Model
{
    protected $fillable = [
        'cms_navigation_id',
        'variant_name',
        'payload',
        'base_version_id',
        'is_dirty',
        'scheduled_publish_at',
        'scheduled_summary',
        'scheduled_by_id',
        'updated_by_id',
    ];

    public function getTable(): string
    {
        return Tables::name('cms_navigation_drafts');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'base_version_id' => 'integer',
            'is_dirty' => 'boolean',
            'scheduled_publish_at' => 'datetime',
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

    /**
     * @return BelongsTo<Model, $this>
     */
    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'scheduled_by_id');
    }

    /**
     * Whether this draft stages a heading. Drafts started before menus had one do not, and publishing
     * them leaves the live heading alone.
     */
    public function stagesTitle(): bool
    {
        return array_key_exists('title', $this->payload);
    }

    /**
     * The staged heading; null when it is blank or not staged.
     */
    public function title(): ?string
    {
        $title = $this->payload['title'] ?? null;

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    /**
     * The staged menu items.
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        $items = $this->payload['items'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }
}
