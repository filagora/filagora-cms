<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsPages\RelationManagers;

use Filagora\Cms\BlockTypes\BlockTypeRegistry;
use Filagora\Cms\Filament\Blocks\BlockForm;
use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Services\PageDraftDiff;
use Filagora\Cms\Services\PageDrafts;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * The staged blocks of the page's working draft. They reach the live page only on publish.
 * A block's data is edited here as JSON; richer, per-type forms can be registered for a type.
 */
class BlocksRelationManager extends RelationManager
{
    protected static string $relationship = 'draftBlocks';

    protected static ?string $title = 'Page blocks';

    /**
     * Re-renders the table after the draft is discarded, restored or published.
     */
    #[On('cms-blocks-updated')]
    public function refreshBlocks(): void {}

    public function form(Schema $schema): Schema
    {
        return $schema->components(BlockForm::components());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepare(array $data): array
    {
        $data = BlockForm::prepare($data);
        $data['is_reusable'] = false;
        $data['is_published'] = false;
        $data['slug'] = null;

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('liveBlock'))
            ->recordTitleAttribute('name')
            ->defaultSort('sort_order')
            ->description(__('filagora-cms::ui.blocks_relation_manager.blocks_are_edited_in_the_draft'))
            ->columns([
                TextColumn::make('sort_order')->label(__('filagora-cms::ui.blocks_relation_manager.order'))->numeric()->sortable(),
                TextColumn::make('change_status')
                    ->label(__('filagora-cms::ui.blocks_relation_manager.changes'))
                    ->badge()
                    ->state(fn (CmsBlock $record): ?string => app(PageDraftDiff::class)->blockStatus($record, $record->liveBlock instanceof CmsBlock ? $record->liveBlock : null))
                    ->color(fn (?string $state): string => $state === 'New' ? 'success' : 'warning')
                    ->placeholder('—'),
                TextColumn::make('type')
                    ->label(__('filagora-cms::ui.blocks_relation_manager.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => app(BlockTypeRegistry::class)->label($state)),
                TextColumn::make('name')->label(__('filagora-cms::ui.blocks_relation_manager.name'))->placeholder(__('filagora-cms::ui.blocks_relation_manager.unnamed_block'))->searchable(),
                TextColumn::make('updated_at')->label(__('filagora-cms::ui.blocks_relation_manager.updated'))->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('filagora-cms::ui.blocks_relation_manager.add_block'))
                    ->modalHeading(__('filagora-cms::ui.blocks_relation_manager.add_page_block'))
                    ->mutateDataUsing(fn (array $data): array => self::prepare($data))
                    ->after(fn () => $this->draftChanged()),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => BlockForm::fill($data))
                    ->mutateDataUsing(fn (array $data): array => self::prepare($data))
                    ->after(fn () => $this->draftChanged()),
                DeleteAction::make()->after(fn () => $this->draftChanged()),
            ]);
    }

    private function draftChanged(): void
    {
        app(PageDrafts::class)->markDirty((int) $this->getOwnerRecord()->getKey());
        $this->dispatch('cms-draft-changed');
    }
}
