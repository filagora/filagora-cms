<?php

declare(strict_types=1);

namespace ForumKit\Cms\Tests\Support;

use Filament\Panel;
use Filament\PanelProvider;
use ForumKit\Cms\ForumKitCmsPlugin;
use ForumKit\Core\ForumKitCorePlugin;
use ForumKit\Entitlements\ForumKitEntitlementsPlugin;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(ForumKitCorePlugin::make())
            ->plugin(ForumKitEntitlementsPlugin::make())
            ->plugin(ForumKitCmsPlugin::make());
    }
}
