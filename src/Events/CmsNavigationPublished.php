<?php

declare(strict_types=1);

namespace Filagora\Cms\Events;

use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Models\CmsNavigationVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A menu draft was applied to the live menu and a version recorded. Dispatched after the
 * transaction commits.
 */
final class CmsNavigationPublished
{
    use Dispatchable;

    public function __construct(
        public readonly CmsNavigation $navigation,
        public readonly CmsNavigationVersion $version,
        public readonly ?Authenticatable $actor,
    ) {}
}
