<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsTags;

use BackedEnum;
use Filagora\Cms\Filament\Concerns\GatedByManage;
use Filagora\Cms\Filament\Resources\CmsTags\Pages\CreateCmsTag;
use Filagora\Cms\Filament\Resources\CmsTags\Pages\EditCmsTag;
use Filagora\Cms\Filament\Resources\CmsTags\Pages\ListCmsTags;
use Filagora\Cms\Filament\Resources\CmsTags\Schemas\CmsTagForm;
use Filagora\Cms\Filament\Resources\CmsTags\Tables\CmsTagsTable;
use Filagora\Cms\Support\Models;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
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
        return app(NavigationGroupRegistry::class)->label(__('filagora-cms::ui.cms_tag_resource.cms'));
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-cms::messages.tags.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-cms::messages.tags.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-cms::messages.tags.plural_model_label');
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
