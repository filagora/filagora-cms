<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsPages\Tables;

use Filagora\Cms\Enums\CmsPageStatus;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Support\WorkflowLabels;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CmsPagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['draft', 'tags']))
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('custom_route')->placeholder('-')->searchable(),
                TextColumn::make('status')
                    ->label('State')
                    ->badge()
                    ->state(fn (CmsPage $record): string => app(WorkflowLabels::class)->pageBadge($record)['label'])
                    ->color(fn (CmsPage $record): string => app(WorkflowLabels::class)->pageBadge($record)['color']),
                TextColumn::make('published_at')->dateTime()->sortable(),
                TextColumn::make('tags.name')->badge(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(CmsPageStatus::options()),
            ])
            ->recordActions([EditAction::make()])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells)
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
