<?php

declare(strict_types=1);

namespace ForumKit\Cms\Support;

use ForumKit\Cms\Enums\CmsPageStatus;
use ForumKit\Cms\Models\CmsNavigation;
use ForumKit\Cms\Models\CmsPage;

/**
 * What state a page or menu is in, in words and as a coloured badge, from its draft.
 */
final class WorkflowLabels
{
    public function describePage(CmsPage $page): string
    {
        $draft = $page->draft()->first();
        $state = $page->status === CmsPageStatus::PUBLISHED ? 'Published' : 'Draft (not public)';

        if ($draft?->scheduled_publish_at !== null) {
            return $state.' · Changes scheduled for '.$draft->scheduled_publish_at->format('j M Y H:i');
        }

        return $draft?->is_dirty ? $state.' · Unpublished changes' : $state;
    }

    /**
     * @return array{label: string, color: string}
     */
    public function pageBadge(CmsPage $page): array
    {
        $draft = $page->draft;

        return match (true) {
            $draft?->scheduled_publish_at !== null => ['label' => 'Scheduled', 'color' => 'info'],
            $page->status !== CmsPageStatus::PUBLISHED => ['label' => 'Draft', 'color' => 'gray'],
            (bool) $draft?->is_dirty => ['label' => 'Unpublished changes', 'color' => 'warning'],
            default => ['label' => 'Published', 'color' => 'success'],
        };
    }

    public function describeNavigation(CmsNavigation $navigation): string
    {
        $draft = $navigation->draft()->first();

        if ($draft?->scheduled_publish_at !== null) {
            return 'Live · Changes scheduled for '.$draft->scheduled_publish_at->format('j M Y H:i');
        }

        return $draft?->is_dirty ? 'Live · Unpublished changes' : 'Live';
    }

    /**
     * @return array{label: string, color: string}
     */
    public function navigationBadge(CmsNavigation $navigation): array
    {
        $draft = $navigation->draft;

        return match (true) {
            $draft?->scheduled_publish_at !== null => ['label' => 'Scheduled', 'color' => 'info'],
            (bool) $draft?->is_dirty => ['label' => 'Unpublished changes', 'color' => 'warning'],
            default => ['label' => 'Published', 'color' => 'success'],
        };
    }
}
