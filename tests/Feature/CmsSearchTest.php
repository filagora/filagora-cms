<?php

declare(strict_types=1);

use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Search\BlockText;
use Filagora\Cms\Search\CmsPageDatabaseSearch;
use Filagora\Cms\Search\CmsPageSearchIndex;
use Filagora\Cms\Search\CmsSearch;
use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Any;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\In;
use Filagora\Search\Filters\Not;
use Filagora\Search\Filters\Range;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Search;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchQuery;
use Illuminate\Support\Facades\DB;

/*
 * Every scenario runs on the array engine and on the database engine and must give the same answer.
 */

function cmsSearchOn(string $engine): ArrayEngine
{
    $array = new ArrayEngine;
    app(SearchEngines::class)->extend('array', fn () => $array);
    config(['filagora-search.engine' => $engine]);
    $array->syncSettings(app(CmsPageSearchIndex::class)->definition());

    return $array;
}

function searchablePage(string $title, array $attributes = [], array $blocks = []): CmsPage
{
    $page = CmsPage::create(array_merge(['slug' => 'p-'.uniqid(), 'title' => $title, 'status' => 'published', 'published_at' => now()->subMinute()], $attributes));

    foreach ($blocks as $order => $data) {
        CmsBlock::create(['cms_page_id' => $page->id, 'type' => 'text', 'sort_order' => $order, 'data' => $data, 'is_published' => true]);
    }

    return $page->refresh();
}

/**
 * @return list<int>
 */
function foundPages(string $text, int $page = 1): array
{
    return app(CmsSearch::class)->pages($text, $page)->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
}

dataset('cmsEngines', ['array', 'database']);

it('finds a published page by title, excerpt, SEO text, slug or block text', function (string $engine) {
    cmsSearchOn($engine);
    $byTitle = searchablePage('Shipping guide');
    $byExcerpt = searchablePage('Other', ['excerpt' => 'All about shipping rates']);
    $bySeo = searchablePage('Third', ['seo_description' => 'Cheap shipping tips']);
    $bySlug = searchablePage('Fourth', ['slug' => 'shipping-faq']);
    $byBlock = searchablePage('Fifth', blocks: [['heading' => 'Rates', 'body' => '<p>We offer <b>shipping</b> worldwide</p>']]);
    searchablePage('Unrelated', blocks: [['body' => 'Nothing here']]);

    expect(foundPages('shipping'))->toEqualCanonicalizing([$byTitle->id, $byExcerpt->id, $bySeo->id, $bySlug->id, $byBlock->id])
        ->and(foundPages('zzzmissing'))->toBe([])
        ->and(foundPages('shipping worldwide'))->toBe([$byBlock->id]);
})->with('cmsEngines');

it('leaves out drafts, pages not yet due and unpublished blocks', function (string $engine) {
    cmsSearchOn($engine);
    $live = searchablePage('Visible widget');
    searchablePage('Draft widget', ['status' => 'draft', 'published_at' => null]);
    searchablePage('Future widget', ['published_at' => now()->addDay()]);
    $page = searchablePage('Blocks', blocks: [['body' => 'hidden-word']]);
    CmsBlock::query()->where('cms_page_id', $page->id)->update(['is_published' => false]);
    app(Indexer::class)->reindex(app(CmsPageSearchIndex::class));

    expect(foundPages('widget'))->toBe([$live->id])
        ->and(foundPages('hidden-word'))->toBe([]);
})->with('cmsEngines');

it('orders the database answer newest first and pages it', function () {
    cmsSearchOn('database');
    $old = searchablePage('Guide old', ['published_at' => now()->subDays(5)]);
    $new = searchablePage('Guide new', ['published_at' => now()->subDay()]);

    $page = app(CmsSearch::class)->pages('guide', 1, 1);

    expect($page->getCollection()->pluck('id')->all())->toBe([$new->id])->and($page->total())->toBe(2)
        ->and(app(CmsSearch::class)->pages('guide', 2, 1)->getCollection()->pluck('id')->all())->toBe([$old->id]);
});

it('drops a hit whose page is no longer live, because the index can be stale', function () {
    cmsSearchOn('array');
    $page = searchablePage('Stale widget');
    $other = searchablePage('Stale gadget', ['slug' => 'stale-other']);

    expect(foundPages('stale'))->toEqualCanonicalizing([$page->id, $other->id]);

    DB::table($page->getTable())->where('id', $page->id)->update(['status' => 'draft']);
    DB::table($other->getTable())->where('id', $other->id)->update(['published_at' => now()->addDay()]);

    expect(foundPages('stale'))->toBe([]);
});

it('keeps page documents current as pages and blocks change', function () {
    $engine = cmsSearchOn('array');
    $definition = app(CmsPageSearchIndex::class)->definition();
    $ids = fn (string $text) => array_map('intval', $engine->search($definition, new SearchQuery($text))->ids());
    $page = searchablePage('Plain title');

    expect($ids('uniqueword'))->toBe([]);

    $block = CmsBlock::create(['cms_page_id' => $page->id, 'type' => 'text', 'sort_order' => 0, 'data' => ['body' => 'a uniqueword'], 'is_published' => true]);
    expect($ids('uniqueword'))->toBe([$page->id]);

    $block->update(['data' => ['body' => 'changed']]);
    expect($ids('uniqueword'))->toBe([])->and($ids('changed'))->toBe([$page->id]);

    $block->delete();
    expect($ids('changed'))->toBe([]);

    $page->update(['status' => 'draft']);
    expect($ids('plain'))->toBe([]);

    $page->update(['status' => 'published']);
    expect($ids('plain'))->toBe([$page->id]);
});

it('ignores staged working copies of blocks and blocks without a page', function () {
    $engine = cmsSearchOn('array');
    $definition = app(CmsPageSearchIndex::class)->definition();
    $page = searchablePage('Plain title');

    CmsBlock::create(['cms_page_id' => $page->id, 'type' => 'text', 'sort_order' => 0, 'data' => ['body' => 'stagedword'], 'is_published' => true, 'is_draft' => true]);
    CmsBlock::create(['cms_page_id' => null, 'slug' => 'shared', 'type' => 'text', 'sort_order' => 0, 'data' => ['body' => 'sharedword'], 'is_published' => true, 'is_reusable' => true]);

    expect($engine->search($definition, new SearchQuery('stagedword'))->ids())->toBe([])
        ->and($engine->search($definition, new SearchQuery('sharedword'))->ids())->toBe([]);
});

it('describes a page as a document with the text of its live blocks, without links', function () {
    cmsSearchOn('array');
    $page = searchablePage('Doc page', ['excerpt' => 'Short', 'seo_title' => 'SEO', 'seo_description' => 'About'], [
        ['heading' => 'First', 'link' => '/some/path', 'image' => 'https://example.test/a.png', 'items' => [['text' => '<i>Nested</i> text']]],
        ['body' => 'Second block'],
    ]);

    $document = app(CmsPageSearchIndex::class)->toDocument($page);

    expect($document->id)->toBe($page->id)
        ->and($document->fields['title'])->toBe('Doc page')
        ->and($document->fields['excerpt'])->toBe('Short')
        ->and($document->fields['status'])->toBe('published')
        ->and($document->fields['published_at_ts'])->toBe($page->published_at->getTimestamp())
        ->and($document->fields['content'])->toBe('First Nested text Second block');
});

it('caps the block text copied into a document', function () {
    cmsSearchOn('array');
    config(['filagora-cms.search.content_limit' => 12]);
    $page = searchablePage('Long', blocks: [['body' => str_repeat('word ', 20)]]);

    expect(mb_strlen(app(BlockText::class)->forPage($page)))->toBe(12);
});

it('knows which pages belong in the index and how many', function () {
    cmsSearchOn('array');
    $live = searchablePage('Live');
    $draft = searchablePage('Draft', ['status' => 'draft', 'published_at' => null]);
    $index = app(CmsPageSearchIndex::class);

    expect($index->shouldBeSearchable($live))->toBeTrue()
        ->and($index->shouldBeSearchable($draft))->toBeFalse()
        ->and($index->shouldBeSearchable($live->blocks()->getRelated()))->toBeFalse()
        ->and($index->expectedCount())->toBe(1)
        ->and($index->query()->pluck('id')->all())->toBe([$live->id]);
});

it('understands every filter in the database fallback, and refuses a field it cannot filter on', function () {
    cmsSearchOn('database');
    $live = searchablePage('Alpha');
    $draft = searchablePage('Beta', ['status' => 'draft', 'published_at' => null]);
    $database = app(CmsPageDatabaseSearch::class);
    $ids = function ($filter) use ($database): array {
        $found = array_map(fn ($hit) => (int) $hit->id, $database->search(new SearchQuery(filter: $filter))->hits);
        sort($found);

        return $found;
    };

    expect($ids(new Equals('status', 'published')))->toBe([$live->id])
        ->and($ids(new In('status', ['published', 'draft'])))->toBe([$live->id, $draft->id])
        ->and($ids(new In('status', [])))->toBe([])
        ->and($ids(new Not(new Equals('status', 'published'))))->toBe([$draft->id])
        ->and($ids(new Any))->toBe([])
        ->and($ids(new Any(new Equals('status', 'draft'), new Equals('status', 'published'))))->toBe([$live->id, $draft->id])
        ->and($ids(new All))->toBe([$live->id, $draft->id])
        ->and($ids(new Range('published_at_ts', gte: now()->subHour()->getTimestamp(), lte: now()->getTimestamp())))->toBe([$live->id])
        ->and(fn () => $ids(new Equals('title', 'x')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $ids(new Range('title', gte: 1)))->toThrow(InvalidArgumentException::class)
        ->and(app(Search::class)->search(CmsPageSearchIndex::NAME, new SearchQuery('alpha'))->ids())->toBe([$live->id]);
});
