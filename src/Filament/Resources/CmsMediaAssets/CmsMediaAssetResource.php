<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsMediaAssets;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use ForumKit\Cms\Filament\Concerns\GatedByManage;
use ForumKit\Cms\Filament\Resources\CmsMediaAssets\Pages\CreateCmsMediaAsset;
use ForumKit\Cms\Filament\Resources\CmsMediaAssets\Pages\EditCmsMediaAsset;
use ForumKit\Cms\Filament\Resources\CmsMediaAssets\Pages\ListCmsMediaAssets;
use ForumKit\Cms\Filament\Resources\CmsMediaAssets\Schemas\CmsMediaAssetForm;
use ForumKit\Cms\Filament\Resources\CmsMediaAssets\Tables\CmsMediaAssetsTable;
use ForumKit\Cms\Support\Models;
use ForumKit\Core\Navigation\NavigationGroupRegistry;
use UnitEnum;

class CmsMediaAssetResource extends Resource
{
    use GatedByManage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?int $navigationSort = 40;

    public static function getModel(): string
    {
        return Models::class('media_asset');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('cms');
    }

    public static function getNavigationLabel(): string
    {
        return __('forumkit-cms::messages.media.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('forumkit-cms::messages.media.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forumkit-cms::messages.media.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return CmsMediaAssetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CmsMediaAssetsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCmsMediaAssets::route('/'),
            'create' => CreateCmsMediaAsset::route('/create'),
            'edit' => EditCmsMediaAsset::route('/{record}/edit'),
        ];
    }
}
