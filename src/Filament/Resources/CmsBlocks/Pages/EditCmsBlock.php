<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsBlocks\Pages;

use Filagora\Cms\Exceptions\DraftOperationFailed;
use Filagora\Cms\Filament\Blocks\BlockForm;
use Filagora\Cms\Filament\Resources\CmsBlocks\CmsBlockResource;
use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Services\BlockDrafts;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edits go to the block's staged copy; the live block is untouched until Publish. The slug is an
 * identifier a site relies on, so it applies at once.
 */
class EditCmsBlock extends EditRecord
{
    protected static string $resource = CmsBlockResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var CmsBlock|null $staged */
        $staged = $this->block()->draftCopy()->first();

        if ($staged !== null) {
            $data = array_merge($data, $staged->only(['name', 'type', 'sort_order', 'is_published', 'data']));
        }

        return BlockForm::fill($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof CmsBlock);
        $data = BlockForm::prepare($data);
        $record->update(['slug' => trim((string) $data['slug'])]);
        app(BlockDrafts::class)->saveDraft($record, $data);

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved';
    }

    protected function afterSave(): void
    {
        $this->dispatch('cms-versions-updated');
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label(__('filagora-cms::ui.edit_cms_block.save_draft'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('filagora-cms::ui.edit_cms_block.publish'))
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->modalHeading(__('filagora-cms::ui.edit_cms_block.publish_block'))
                ->modalDescription(__('filagora-cms::ui.edit_cms_block.this_makes_the_draft_live_everywhere'))
                ->visible(fn (): bool => $this->block()->draftCopy()->exists())
                ->schema([
                    Textarea::make('summary')->label(__('filagora-cms::ui.edit_cms_block.version_summary'))->rows(2)->maxLength(255),
                ])
                ->action(function (array $data): void {
                    try {
                        $version = app(BlockDrafts::class)->publish($this->block(), auth()->user(), $data['summary'] ?? null);
                    } catch (DraftOperationFailed $e) {
                        Notification::make()->danger()->title(__('filagora-cms::ui.edit_cms_block.cannot_publish'))->body($e->getMessage())->send();

                        return;
                    }

                    $this->refreshAfterChange();
                    Notification::make()->success()->title(__('filagora-cms::ui.edit_cms_block.published'))->body(__('filagora-cms::ui.common.version_is_live', ['number' => $version->version_number]))->send();
                }),
            Action::make('discard_draft')
                ->label(__('filagora-cms::ui.edit_cms_block.discard_draft'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('filagora-cms::ui.edit_cms_block.throws_away_the_unpublished_changes_to'))
                ->visible(fn (): bool => $this->block()->draftCopy()->exists())
                ->action(function (): void {
                    app(BlockDrafts::class)->discard($this->block());
                    $this->refreshAfterChange();
                    Notification::make()->success()->title(__('filagora-cms::ui.edit_cms_block.draft_discarded'))->send();
                }),
            DeleteAction::make()
                ->before(fn () => app(BlockDrafts::class)->discard($this->block())),
        ];
    }

    private function block(): CmsBlock
    {
        assert($this->record instanceof CmsBlock);

        return $this->record;
    }

    private function refreshAfterChange(): void
    {
        $this->record->refresh();
        $this->fillForm();
        $this->dispatch('cms-versions-updated');
    }
}
