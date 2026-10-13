<?php

declare(strict_types=1);

namespace Filagora\Cms\Contracts;

use Filagora\Cms\Models\CmsPage;

/**
 * The public URL a page is served at. The package has no routes of its own, so the host says
 * (the default is the custom route, or `/pages/{slug}`).
 */
interface PageUrlResolver
{
    public function urlFor(CmsPage $page): string;
}
