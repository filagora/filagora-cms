<?php

declare(strict_types=1);

namespace ForumKit\Cms\Support;

use ForumKit\Cms\Contracts\PageUrlResolver;
use ForumKit\Cms\Models\CmsPage;

/**
 * The page's custom route if it has one, otherwise `/pages/{slug}`.
 */
final class DefaultPageUrl implements PageUrlResolver
{
    public function urlFor(CmsPage $page): string
    {
        $customRoute = trim((string) $page->custom_route);

        return url($customRoute !== '' ? $customRoute : '/pages/'.trim((string) $page->slug));
    }
}
