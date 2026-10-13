<?php

declare(strict_types=1);

namespace Filagora\Cms\Support;

use Filagora\Cms\Contracts\PreviewResolver;
use Filagora\Cms\Models\CmsPage;

/**
 * The default: nothing is being previewed.
 */
final class NoPreview implements PreviewResolver
{
    public function pageForSlug(string $slug): ?CmsPage
    {
        return null;
    }

    public function pageForCustomRoute(string $customRoute): ?CmsPage
    {
        return null;
    }

    public function draftItemsFor(string $name): ?array
    {
        return null;
    }
}
