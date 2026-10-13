<?php

declare(strict_types=1);

namespace Filagora\Cms\Events;

use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Models\CmsPageVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A page draft was applied to the live page and a version recorded. Dispatched after the
 * transaction commits; listeners record legal-page versions, reindex search, clear caches.
 */
final class CmsPagePublished
{
    use Dispatchable;

    public function __construct(
        public readonly CmsPage $page,
        public readonly CmsPageVersion $version,
        public readonly ?Authenticatable $actor,
    ) {}
}
