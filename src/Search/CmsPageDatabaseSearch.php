<?php

declare(strict_types=1);

namespace Filagora\Cms\Search;

use Filagora\Cms\Support\Models;
use Filagora\Search\Contracts\Filter;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Any;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\In;
use Filagora\Search\Filters\Not;
use Filagora\Search\Filters\Range;
use Filagora\Search\Support\Hit;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Answers a page search from the tables: every word must appear in the title, excerpt, SEO
 * fields, slug or the data of a live published block (compared without regard to case: MariaDB
 * keeps JSON columns in a case-sensitive collation). Newest publication first.
 */
class CmsPageDatabaseSearch
{
    public function search(SearchQuery $query): SearchResult
    {
        $builder = Models::class('page')::query();

        if ($query->filter !== null) {
            $this->apply($builder, $query->filter);
        }

        foreach (preg_split('/\s+/', trim($query->text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';
            $builder->where(fn (Builder $match) => $match
                ->where('title', 'like', $like)
                ->orWhere('excerpt', 'like', $like)
                ->orWhere('seo_title', 'like', $like)
                ->orWhere('seo_description', 'like', $like)
                ->orWhere('slug', 'like', $like)
                ->orWhereHas('blocks', fn (Builder $block) => $block->where('is_published', true)->whereRaw(
                    'LOWER('.$block->getQuery()->getGrammar()->wrap($block->getModel()->qualifyColumn('data')).') like ?',
                    [mb_strtolower($like)],
                )));
        }

        $total = (clone $builder)->count();
        $ids = $builder->orderByDesc('published_at')->orderByDesc('id')->forPage($query->page, $query->perPage)->pluck('id');

        return new SearchResult($ids->map(static fn (mixed $id): Hit => new Hit((int) $id))->all(), $total);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function apply(Builder $query, Filter $filter): void
    {
        match (true) {
            $filter instanceof Equals => $this->condition($query, $filter->field, [$filter->value]),
            $filter instanceof In => $this->condition($query, $filter->field, $filter->values),
            $filter instanceof Range => $this->range($query, $filter),
            $filter instanceof All => $query->where(function (Builder $all) use ($filter): void {
                foreach ($filter->filters as $inner) {
                    $all->where(fn (Builder $each) => $this->apply($each, $inner));
                }
            }),
            $filter instanceof Any => $filter->filters === []
                ? $query->whereRaw('1 = 0')
                : $query->where(function (Builder $any) use ($filter): void {
                    foreach ($filter->filters as $inner) {
                        $any->orWhere(fn (Builder $each) => $this->apply($each, $inner));
                    }
                }),
            $filter instanceof Not => $query->whereNot(fn (Builder $not) => $this->apply($not, $filter->filter)),
            default => throw new InvalidArgumentException('Unsupported filter '.$filter::class.'.'),
        };
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<int|string|bool>  $values
     */
    private function condition(Builder $query, string $field, array $values): void
    {
        if ($values === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        match ($field) {
            'status' => $query->whereIn($query->getModel()->qualifyColumn('status'), $values),
            default => throw new InvalidArgumentException("[{$field}] cannot be filtered on in the database."),
        };
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function range(Builder $query, Range $range): void
    {
        if ($range->field !== 'published_at_ts') {
            throw new InvalidArgumentException("[{$range->field}] cannot be filtered on in the database.");
        }

        $column = $query->getModel()->qualifyColumn('published_at');

        foreach ([['>=', $range->gte], ['<=', $range->lte]] as [$operator, $bound]) {
            if ($bound !== null) {
                $query->where($column, $operator, date('Y-m-d H:i:s', (int) $bound));
            }
        }
    }
}
