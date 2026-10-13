<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsMediaAssets\Tables;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use ForumKit\Cms\Models\CmsMediaAsset;
use ForumKit\Cms\Services\MediaAssetUsage;
use Illuminate\Database\Eloquent\Builder;

class CmsMediaAssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('path')
                    ->label('Preview')
                    ->disk(fn (CmsMediaAsset $record): string => (string) $record->disk)
                    ->visibility('public')
                    ->square()
                    ->size(48),
                TextColumn::make('filename')->searchable()->sortable()->wrap(),
                TextColumn::make('meta.extension')->label('Ext')->badge()->placeholder('-'),
                TextColumn::make('mime_type')->searchable()->toggleable(),
                TextColumn::make('size')->numeric()->sortable()->toggleable(),
                TextColumn::make('alt_text')->limit(40)->placeholder('No alt text')->toggleable(),
                TextColumn::make('usage')
                    ->label('Used in')
                    ->state(fn (CmsMediaAsset $record): string => (string) app(MediaAssetUsage::class)->countForAsset($record))
                    ->badge(),
                TextColumn::make('path')->label('Path')->toggleable(isToggledHiddenByDefault: true)->searchable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Kind')
                    ->options(['image' => 'Images', 'other' => 'Other files'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'image' => $query->where('mime_type', 'like', 'image/%'),
                        'other' => $query->where(fn (Builder $q) => $q->whereNull('mime_type')->orWhere('mime_type', 'not like', 'image/%')),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('open_file')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (CmsMediaAsset $record): ?string => $record->publicUrl(), shouldOpenInNewTab: true)
                    ->visible(fn (CmsMediaAsset $record): bool => filled($record->publicUrl())),
                EditAction::make(),
            ])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells);
    }
}
