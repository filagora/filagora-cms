<?php

declare(strict_types=1);

namespace Filagora\Cms\Search;

use Filagora\Cms\Enums\CmsPageStatus;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Support\Models;
use Filagora\Search\Enums\MatchingStrategy;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\Range;
use Filagora\Search\Search;
use Filagora\Search\Support\SearchQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Finds published pages. Only pages whose publication time has arrived are returned, and every
 * hit is re-checked against the row, because an index is only as fresh as its last sync.
 */
class CmsSearch
{
    public const PER_PAGE = 10;

    public function __construct(private readonly Search $search) {}

    /**
     * @return LengthAwarePaginator<int, CmsPage>
     */
    public function pages(string $text, int $page = 1, int $perPage = self::PER_PAGE, MatchingStrategy $matching = MatchingStrategy::All): LengthAwarePaginator
    {
        $result = $this->search->search(CmsPageSearchIndex::NAME, new SearchQuery(
            text: trim($text),
            filter: new All(new Equals('status', CmsPageStatus::PUBLISHED->value), new Range('published_at_ts', lte: now()->getTimestamp())),
            page: max(1, $page),
            perPage: $perPage,
            matching: $matching,
        ));

        $rows = Models::class('page')::query()->whereIn('id', $result->ids() ?: [0])->get()->keyBy(fn (Model $page): string => (string) $page->getKey());

        $pages = collect($result->ids())
            ->map(fn (int|string $id): ?Model => $rows->get((string) $id))
            ->filter(fn (?Model $page): bool => $page instanceof CmsPage
                && $page->status === CmsPageStatus::PUBLISHED
                && $page->published_at !== null
                && $page->published_at->lte(now()))
            ->values();

        return new LengthAwarePaginator($pages, $result->total, $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
    }
}
