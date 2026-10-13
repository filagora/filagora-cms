<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsTags;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use ForumKit\Cms\Filament\Concerns\GatedByManage;
use ForumKit\Cms\Filament\Resources\CmsTags\Pages\CreateCmsTag;
use ForumKit\Cms\Filament\Resources\CmsTags\Pages\EditCmsTag;
use ForumKit\Cms\Filament\Resources\CmsTags\Pages\ListCmsTags;
use ForumKit\Cms\Filament\Resources\CmsTags\Schemas\CmsTagForm;
use ForumKit\Cms\Filament\Resources\CmsTags\Tables\CmsTagsTable;
use ForumKit\Cms\Support\Models;
use ForumKit\Core\Navigation\NavigationGroupRegistry;
use UnitEnum;

class CmsTagResource extends Resource
{
    use GatedByManage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 30;

    public static function getModel(): string
    {
        return Models::class('tag');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('cms');
    }

    public static function getNavigationLabel(): string
    {
        return __('forumkit-cms::messages.tags.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('forumkit-cms::messages.tags.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forumkit-cms::messages.tags.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return CmsTagForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CmsTagsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCmsTags::route('/'),
            'create' => CreateCmsTag::route('/create'),
            'edit' => EditCmsTag::route('/{record}/edit'),
        ];
    }
}
