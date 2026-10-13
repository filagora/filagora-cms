<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsBlocks\Tables;

use Filagora\Cms\BlockTypes\BlockTypeRegistry;
use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Services\BlockDrafts;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CmsBlocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('draftCopy'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->placeholder(__('filagora-cms::ui.cms_blocks_table.unnamed_block'))->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => app(BlockTypeRegistry::class)->label($state)),
                IconColumn::make('is_published')->label(__('filagora-cms::ui.cms_blocks_table.published'))->boolean(),
                TextColumn::make('pending')
                    ->label(__('filagora-cms::ui.cms_blocks_table.draft'))
                    ->badge()
                    ->state(fn (CmsBlock $record): ?string => app(BlockDrafts::class)->hasPendingChanges($record) ? 'Unpublished changes' : null)
                    ->color('warning')
                    ->placeholder('—'),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(fn (): array => app(BlockTypeRegistry::class)->options()),
            ])
            ->recordActions([EditAction::make()])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells);
    }
}
