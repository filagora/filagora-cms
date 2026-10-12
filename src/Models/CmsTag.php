<?php

declare(strict_types=1);

namespace ForumKit\Cms\Models;

use ForumKit\Cms\Support\Models;
use ForumKit\Core\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CmsTag extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function getTable(): string
    {
        return Tables::name('cms_tags');
    }

    /**
     * @return BelongsToMany<Model, $this>
     */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Models::class('page'), Tables::name('cms_page_tag'), 'cms_tag_id', 'cms_page_id');
    }
}
