<?php

declare(strict_types=1);

namespace Filagora\Cms\Search;

use Filagora\Cms\Enums\CmsPageStatus;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Support\Models;
use Filagora\Search\Contracts\SearchIndex;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Published pages as search documents: title, excerpt, SEO text and the text of the page's live
 * blocks. A page whose publication time has not arrived is indexed too (the time is a field, so
 * the search can leave it out until then without needing an event at that moment).
 */
final class CmsPageSearchIndex implements SearchIndex
{
    public const NAME = 'cms_pages';

    public function __construct(private readonly CmsPageDatabaseSearch $database, private readonly BlockText $text) {}

    public function definition(): IndexDefinition
    {
        return new IndexDefinition(
            name: self::NAME,
            searchable: ['title', 'excerpt', 'seo_title', 'seo_description', 'slug', 'content'],
            filterable: ['status', 'published_at_ts'],
            sortable: ['published_at_ts'],
        );
    }

    public function model(): string
    {
        return Models::class('page');
    }

    public function shouldBeSearchable(Model $model): bool
    {
        return $model instanceof CmsPage && $model->status === CmsPageStatus::PUBLISHED;
    }

    public function toDocument(Model $model): Document
    {
        assert($model instanceof CmsPage);

        return new Document($model->getKey(), [
            'slug' => $model->slug,
            'title' => $model->title,
            'excerpt' => (string) $model->excerpt,
            'seo_title' => (string) $model->seo_title,
            'seo_description' => (string) $model->seo_description,
            'status' => $model->status->value,
            'published_at_ts' => $model->published_at?->getTimestamp() ?? 0,
            'content' => $this->text->forPage($model),
        ]);
    }

    public function query(): Builder
    {
        return Models::class('page')::query()->where('status', CmsPageStatus::PUBLISHED->value);
    }

    public function expectedCount(): int
    {
        return Models::class('page')::query()->where('status', CmsPageStatus::PUBLISHED->value)->count();
    }

    public function fallback(SearchQuery $query): SearchResult
    {
        return $this->database->search($query);
    }
}
