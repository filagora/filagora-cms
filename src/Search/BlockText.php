<?php

declare(strict_types=1);

namespace Filagora\Cms\Search;

use Filagora\Cms\Models\CmsPage;

/**
 * The words on a page: every text value in its live, published blocks' data, tags removed. Links
 * and paths are left out, and the result is capped at `search.content_limit` characters.
 */
class BlockText
{
    public function forPage(CmsPage $page): string
    {
        $limit = max(1, (int) config('filagora-cms.search.content_limit', 50000));
        $parts = [];

        foreach ($page->blocks()->where('is_published', true)->get() as $block) {
            $this->collect($block->data ?? [], $parts);
        }

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', implode(' ', $parts))), 0, $limit);
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $parts
     */
    private function collect(array $value, array &$parts): void
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                $this->collect($item, $parts);
            } elseif (is_string($item)) {
                $text = trim(strip_tags($item));

                if ($text !== '' && ! preg_match('#^(https?:)?//|^/#', $text)) {
                    $parts[] = $text;
                }
            }
        }
    }
}
