<?php

declare(strict_types=1);

namespace Filagora\Cms\Support;

use Filagora\Cms\Contracts\PageUrlResolver;
use Filagora\Cms\Models\CmsPage;

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
