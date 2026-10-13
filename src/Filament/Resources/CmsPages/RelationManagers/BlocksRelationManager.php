<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsPages\RelationManagers;

use Filagora\Cms\BlockTypes\BlockTypeRegistry;
use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Services\PageDraftDiff;
use Filagora\Cms\Services\PageDrafts;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
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
        return $schema->components(self::blockFields());
    }

    /**
     * @return list<Component>
     */
    public static function blockFields(): array
    {
        return [
            Select::make('type')
                ->label('Type')
                ->options(function (?CmsBlock $record): array {
                    $registry = app(BlockTypeRegistry::class);
                    $options = $registry->options();

                    if ($record !== null && ! isset($options[$record->type])) {
                        $options[$record->type] = $registry->label($record->type);
                    }

                    return $options;
                })
                ->required()
                ->searchable(),
            TextInput::make('name')->label('Name')->maxLength(255),
            TextInput::make('sort_order')->label('Display order')->numeric()->minValue(0)->default(0)->required()
                ->helperText('Lower values appear first.'),
            Textarea::make('data_json')
                ->label('Content (JSON)')
                ->rows(14)
                ->rules(['nullable', 'json'])
                ->helperText('The block\'s content as JSON. Leave empty for none.')
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillFromRecord(array $data): array
    {
        $data['data_json'] = is_array($data['data'] ?? null) && $data['data'] !== []
            ? (string) json_encode($data['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepare(array $data): array
    {
        $json = trim((string) ($data['data_json'] ?? ''));
        unset($data['data_json']);

        $data['data'] = $json === '' ? null : json_decode($json, true);
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
            ->description('Blocks are edited in the draft; they only appear on the live page when you publish. Lower display order values appear first.')
            ->columns([
                TextColumn::make('sort_order')->label('Order')->numeric()->sortable(),
                TextColumn::make('change_status')
                    ->label('Changes')
                    ->badge()
                    ->state(fn (CmsBlock $record): ?string => app(PageDraftDiff::class)->blockStatus($record, $record->liveBlock instanceof CmsBlock ? $record->liveBlock : null))
                    ->color(fn (?string $state): string => $state === 'New' ? 'success' : 'warning')
                    ->placeholder('—'),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => app(BlockTypeRegistry::class)->label($state)),
                TextColumn::make('name')->label('Name')->placeholder('Unnamed block')->searchable(),
                TextColumn::make('updated_at')->label('Updated')->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add block')
                    ->modalHeading('Add page block')
                    ->mutateDataUsing(fn (array $data): array => self::prepare($data))
                    ->after(fn () => $this->draftChanged()),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => self::fillFromRecord($data))
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
