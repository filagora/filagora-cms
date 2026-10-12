<?php

declare(strict_types=1);

namespace ForumKit\Cms\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides the staged working copies of blocks (`is_draft`) from every live read. Lift it with
 * `withoutGlobalScopes()` where the draft rows are wanted.
 *
 * @implements Scope<Model>
 */
final class LiveBlockScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('is_draft'), false);
    }
}
