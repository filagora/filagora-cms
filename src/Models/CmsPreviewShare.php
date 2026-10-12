<?php

declare(strict_types=1);

namespace ForumKit\Cms\Models;

use ForumKit\Core\Support\Tables;
use ForumKit\Core\Support\UserModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A link that shows chosen draft menus and pages to someone without a login.
 * `items` is `{"navigations": {menu name: variant name}, "pages": {page id: variant name}}`.
 *
 * @property int $id
 * @property string $token
 * @property string|null $label
 * @property array<string, mixed> $items
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_opened_at
 * @property int $open_count
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CmsPreviewShare extends Model
{
    protected $fillable = [
        'token',
        'label',
        'items',
        'expires_at',
        'revoked_at',
        'created_by_id',
    ];

    protected $hidden = ['token'];

    public function getTable(): string
    {
        return Tables::name('cms_preview_shares');
    }

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'open_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'created_by_id');
    }

    /**
     * Not revoked and not yet expired.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * The menus in the share, as menu name => variant name.
     *
     * @return array<string, string|null>
     */
    public function navigations(): array
    {
        return is_array($this->items['navigations'] ?? null) ? $this->items['navigations'] : [];
    }

    /**
     * The pages in the share, as page id => variant name.
     *
     * @return array<int|string, string|null>
     */
    public function pages(): array
    {
        return is_array($this->items['pages'] ?? null) ? $this->items['pages'] : [];
    }
}
