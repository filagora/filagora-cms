<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsBlocks;

use BackedEnum;
use Filagora\Cms\Filament\Concerns\GatedByManage;
use Filagora\Cms\Filament\Resources\CmsBlocks\Pages\CreateCmsBlock;
use Filagora\Cms\Filament\Resources\CmsBlocks\Pages\EditCmsBlock;
use Filagora\Cms\Filament\Resources\CmsBlocks\Pages\ListCmsBlocks;
use Filagora\Cms\Filament\Resources\CmsBlocks\RelationManagers\VersionsRelationManager;
use Filagora\Cms\Filament\Resources\CmsBlocks\Schemas\CmsBlockForm;
use Filagora\Cms\Filament\Resources\CmsBlocks\Tables\CmsBlocksTable;
use Filagora\Cms\Support\Models;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Reusable blocks: standalone blocks with a slug that a site renders in more than one place (a
 * footer block, say). Blocks that belong to a page are edited with the page.
 */
class CmsBlockResource extends Resource
{
    use GatedByManage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 15;

    public static function getModel(): string
    {
        return Models::class('block');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('cms');
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-cms::messages.blocks.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-cms::messages.blocks.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-cms::messages.blocks.plural_model_label');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_reusable', true)->whereNull('cms_page_id');
    }

    public static function form(Schema $schema): Schema
    {
        return CmsBlockForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CmsBlocksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCmsBlocks::route('/'),
            'create' => CreateCmsBlock::route('/create'),
            'edit' => EditCmsBlock::route('/{record}/edit'),
        ];
    }
}
