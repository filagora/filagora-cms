<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsPages;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use ForumKit\Cms\Filament\Concerns\GatedByManage;
use ForumKit\Cms\Filament\Resources\CmsPages\Pages\CreateCmsPage;
use ForumKit\Cms\Filament\Resources\CmsPages\Pages\EditCmsPage;
use ForumKit\Cms\Filament\Resources\CmsPages\Pages\ListCmsPages;
use ForumKit\Cms\Filament\Resources\CmsPages\RelationManagers\VersionsRelationManager;
use ForumKit\Cms\Filament\Resources\CmsPages\Schemas\CmsPageForm;
use ForumKit\Cms\Filament\Resources\CmsPages\Tables\CmsPagesTable;
use ForumKit\Cms\Support\Models;
use ForumKit\Core\Navigation\NavigationGroupRegistry;
use UnitEnum;

class CmsPageResource extends Resource
{
    use GatedByManage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 10;

    public static function getModel(): string
    {
        return Models::class('page');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('cms');
    }

    public static function getNavigationLabel(): string
    {
        return __('forumkit-cms::messages.pages.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('forumkit-cms::messages.pages.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forumkit-cms::messages.pages.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return CmsPageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CmsPagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCmsPages::route('/'),
            'create' => CreateCmsPage::route('/create'),
            'edit' => EditCmsPage::route('/{record}/edit'),
        ];
    }
}
