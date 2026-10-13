<?php

declare(strict_types=1);

namespace Filagora\Cms\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

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
     * The address that opens this link, if the host has defined the route named in
     * `filagora-cms.preview.share_route` (it takes the token).
     */
    public function url(): ?string
    {
        $route = (string) config('filagora-cms.preview.share_route', 'cms.preview.share.open');

        return Route::has($route) ? route($route, ['token' => $this->getRawOriginal('token')]) : null;
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
