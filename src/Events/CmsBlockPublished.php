<?php

declare(strict_types=1);

namespace ForumKit\Cms\Events;

use ForumKit\Cms\Models\CmsBlock;
use ForumKit\Cms\Models\CmsBlockVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reusable block's staged copy was applied to the live block and a version recorded.
 */
final class CmsBlockPublished
{
    use Dispatchable;

    public function __construct(
        public readonly CmsBlock $block,
        public readonly CmsBlockVersion $version,
        public readonly ?Authenticatable $actor,
    ) {}
}
