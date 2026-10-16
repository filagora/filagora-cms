<?php

declare(strict_types=1);

namespace Filagora\Cms\Search;

use Filagora\Cms\Models\CmsBlock;
use Filagora\Search\Jobs\SyncSearchDocument;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * A page's document copies the text of its live blocks, so saving or deleting a live block of a
 * page brings that page's document up to date. Staged working copies are ignored.
 */
final class SearchRelations
{
    public function blockChanged(CmsBlock $block): void
    {
        if ($block->is_draft || $block->cms_page_id === null) {
            return;
        }

        $pending = SyncSearchDocument::dispatch(CmsPageSearchIndex::NAME, $block->cms_page_id);

        $this->configure($pending);
    }

    private function configure(PendingDispatch $pending): void
    {
        if (! config('filagora-search.queue', false)) {
            $pending->onConnection('sync');
        }

        if (config('filagora-search.after_commit', true)) {
            $pending->afterCommit();
        }
    }
}
