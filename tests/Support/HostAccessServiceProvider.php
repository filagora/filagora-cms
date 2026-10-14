<?php

declare(strict_types=1);

namespace Filagora\Cms\Tests\Support;

use Filagora\Core\Contracts\AccessChecker;
use Illuminate\Support\ServiceProvider;

/**
 * The host application's own permission system, as a test double.
 */
class HostAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccessChecker::class, HostAccess::class);
    }
}
