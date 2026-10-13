<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsMediaAssets;

use BackedEnum;
use Filagora\Cms\Filament\Concerns\GatedByManage;
use Filagora\Cms\Filament\Resources\CmsMediaAssets\Pages\CreateCmsMediaAsset;
use Filagora\Cms\Filament\Resources\CmsMediaAssets\Pages\EditCmsMediaAsset;
use Filagora\Cms\Filament\Resources\CmsMediaAssets\Pages\ListCmsMediaAssets;
use Filagora\Cms\Filament\Resources\CmsMediaAssets\Schemas\CmsMediaAssetForm;
use Filagora\Cms\Filament\Resources\CmsMediaAssets\Tables\CmsMediaAssetsTable;
use Filagora\Cms\Support\Models;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
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
        return app(NavigationGroupRegistry::class)->label(__('filagora-cms::ui.cms_media_asset_resource.cms'));
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-cms::messages.media.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-cms::messages.media.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-cms::messages.media.plural_model_label');
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
