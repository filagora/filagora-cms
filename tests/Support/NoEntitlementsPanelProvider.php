<?php

declare(strict_types=1);

namespace Filagora\Cms\Tests\Support;

use Filagora\Cms\FilagoraCmsPlugin;
use Filagora\Core\FilagoraCorePlugin;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * A panel for a host that has no entitlements package: core and the CMS only.
 */
class NoEntitlementsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(FilagoraCorePlugin::make())
            ->plugin(FilagoraCmsPlugin::make());
    }
}
