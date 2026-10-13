<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsMediaAssets\Tables;

use Filagora\Cms\Models\CmsMediaAsset;
use Filagora\Cms\Services\MediaAssetUsage;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CmsMediaAssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('path')
                    ->label(__('filagora-cms::ui.cms_media_assets_table.preview'))
                    ->disk(fn (CmsMediaAsset $record): string => (string) $record->disk)
                    ->visibility('public')
                    ->square()
                    ->size(48),
                TextColumn::make('filename')->searchable()->sortable()->wrap(),
                TextColumn::make('meta.extension')->label(__('filagora-cms::ui.cms_media_assets_table.ext'))->badge()->placeholder('-'),
                TextColumn::make('mime_type')->searchable()->toggleable(),
                TextColumn::make('size')->numeric()->sortable()->toggleable(),
                TextColumn::make('alt_text')->limit(40)->placeholder(__('filagora-cms::ui.cms_media_assets_table.no_alt_text'))->toggleable(),
                TextColumn::make('usage')
                    ->label(__('filagora-cms::ui.cms_media_assets_table.used_in'))
                    ->state(fn (CmsMediaAsset $record): string => (string) app(MediaAssetUsage::class)->countForAsset($record))
                    ->badge(),
                TextColumn::make('path')->label(__('filagora-cms::ui.cms_media_assets_table.path'))->toggleable(isToggledHiddenByDefault: true)->searchable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('filagora-cms::ui.cms_media_assets_table.kind'))
                    ->options(['image' => 'Images', 'other' => 'Other files'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'image' => $query->where('mime_type', 'like', 'image/%'),
                        'other' => $query->where(fn (Builder $q) => $q->whereNull('mime_type')->orWhere('mime_type', 'not like', 'image/%')),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('open_file')
                    ->label(__('filagora-cms::ui.cms_media_assets_table.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (CmsMediaAsset $record): ?string => $record->publicUrl(), shouldOpenInNewTab: true)
                    ->visible(fn (CmsMediaAsset $record): bool => filled($record->publicUrl())),
                EditAction::make(),
            ])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells);
    }
}
