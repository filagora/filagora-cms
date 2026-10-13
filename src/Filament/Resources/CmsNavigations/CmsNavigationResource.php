<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsNavigations;

use BackedEnum;
use Filagora\Cms\Filament\Concerns\GatedByManage;
use Filagora\Cms\Filament\Resources\CmsNavigations\Pages\CreateCmsNavigation;
use Filagora\Cms\Filament\Resources\CmsNavigations\Pages\EditCmsNavigation;
use Filagora\Cms\Filament\Resources\CmsNavigations\Pages\ListCmsNavigations;
use Filagora\Cms\Filament\Resources\CmsNavigations\RelationManagers\VersionsRelationManager;
use Filagora\Cms\Filament\Resources\CmsNavigations\Schemas\CmsNavigationForm;
use Filagora\Cms\Filament\Resources\CmsNavigations\Tables\CmsNavigationsTable;
use Filagora\Cms\Support\Models;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CmsNavigationResource extends Resource
{
    use GatedByManage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static ?int $navigationSort = 20;

    public static function getModel(): string
    {
        return Models::class('navigation');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('cms');
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-cms::messages.navigations.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-cms::messages.navigations.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-cms::messages.navigations.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return CmsNavigationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CmsNavigationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCmsNavigations::route('/'),
            'create' => CreateCmsNavigation::route('/create'),
            'edit' => EditCmsNavigation::route('/{record}/edit'),
        ];
    }
}
