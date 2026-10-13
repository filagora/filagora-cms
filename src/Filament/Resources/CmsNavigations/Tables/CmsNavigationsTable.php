<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsNavigations\Tables;

use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Support\WorkflowLabels;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CmsNavigationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('draft'))
            ->columns([
                TextColumn::make('name')->label('Menu')->weight('medium')->searchable(),
                TextColumn::make('items')
                    ->label('Links')
                    ->state(fn (CmsNavigation $record): string => (string) count(is_array($record->items) ? $record->items : []))
                    ->badge(),
                TextColumn::make('workflow')
                    ->label('Status')
                    ->state(fn (CmsNavigation $record): string => app(WorkflowLabels::class)->navigationBadge($record)['label'])
                    ->badge()
                    ->color(fn (CmsNavigation $record): string => app(WorkflowLabels::class)->navigationBadge($record)['color']),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make()])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells)
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
