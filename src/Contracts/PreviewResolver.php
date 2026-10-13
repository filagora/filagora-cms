<?php

declare(strict_types=1);

namespace Filagora\Cms\Contracts;

use Filagora\Cms\Models\CmsPage;

/**
 * Lets the readers serve unpublished content to someone who is previewing it. The package's
 * preview session implements this; the default previews nothing.
 */
interface PreviewResolver
{
    /**
     * The previewed page that would be served at this slug, as it will look once published.
     */
    public function pageForSlug(string $slug): ?CmsPage;

    /**
     * The previewed page that would be served at this custom route, such as `/` or `/about`.
     */
    public function pageForCustomRoute(string $customRoute): ?CmsPage;

    /**
     * The staged items of the named menu, or null when it is not being previewed.
     *
     * @return list<array<string, mixed>>|null
     */
    public function draftItemsFor(string $name): ?array;
}
