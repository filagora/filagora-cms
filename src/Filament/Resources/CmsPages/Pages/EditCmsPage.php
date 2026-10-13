<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsPages\Pages;

use Filagora\Cms\Contracts\PageUrlResolver;
use Filagora\Cms\Enums\CmsPageStatus;
use Filagora\Cms\Exceptions\DraftOperationFailed;
use Filagora\Cms\Filament\Actions\DraftVariantActions;
use Filagora\Cms\Filament\Actions\SharePreviewLinkAction;
use Filagora\Cms\Filament\Resources\CmsPages\CmsPageResource;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Preview\PreviewSession;
use Filagora\Cms\Services\PageDraftDiff;
use Filagora\Cms\Services\PageDrafts;
use Filagora\Cms\Support\WorkflowLabels;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

/**
 * Edits go to the working draft; the live page is untouched until Publish.
 */
class EditCmsPage extends EditRecord
{
    protected static string $resource = CmsPageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_merge($data, app(PageDrafts::class)->formState($this->page(), auth()->user()));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof CmsPage);
        app(PageDrafts::class)->saveDraftFields($record, $data, auth()->user());

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved';
    }

    /**
     * Block edits happen in a child component; receiving the event re-renders the pending-changes panel.
     */
    #[On('cms-draft-changed')]
    public function refreshPendingChanges(): void
    {
        $this->record->unsetRelation('draft');
    }

    protected function afterSave(): void
    {
        $this->refreshWorkflowState();
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save draft');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return app(WorkflowLabels::class)->describePage($this->page()->refresh());
    }

    protected function getHeaderActions(): array
    {
        return [
            DraftVariantActions::make(
                fn (): CmsPage => $this->page(),
                fn (): PageDrafts => app(PageDrafts::class),
                function (): void {
                    $this->refreshWorkflowState();
                    $this->fillForm();
                },
            ),
            SharePreviewLinkAction::make(
                fn (): CmsPage => $this->page(),
                fn (): PageDrafts => app(PageDrafts::class),
            ),
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->action(function (): void {
                    $drafts = app(PageDrafts::class);
                    $drafts->ensureDraft($this->page(), auth()->user());
                    app(PreviewSession::class)->addPage($this->page());

                    $this->redirect(app(PageUrlResolver::class)->urlFor($drafts->previewPage($this->page())));
                }),
            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->modalHeading('Publish changes')
                ->modalDescription(function (): string {
                    $count = app(PageDraftDiff::class)->forPage($this->page())['count'];
                    $summary = $count === 0
                        ? 'The draft has no changes compared with the live page.'
                        : $count.' pending '.Str::plural('change', $count).' will go live and a new version will be recorded.';

                    return app(PageDrafts::class)->isStale($this->page())
                        ? 'Warning: this page was published again after you started this draft. Publishing will overwrite those newer changes. '.$summary
                        : $summary;
                })
                ->schema([
                    Textarea::make('summary')->label('Version summary')->rows(2)->maxLength(255),
                ])
                ->action(function (array $data): void {
                    try {
                        $version = app(PageDrafts::class)->publish($this->page(), auth()->user(), $data['summary'] ?? null);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title('Cannot publish')->body(collect($e->errors())->flatten()->implode(' '))->send();

                        return;
                    } catch (DraftOperationFailed $e) {
                        Notification::make()->danger()->title('Cannot publish')->body($e->getMessage())->send();

                        return;
                    }

                    $this->refreshWorkflowState();
                    Notification::make()->success()->title('Published')->body('Version '.$version->version_number.' is now live.')->send();
                }),
            Action::make('schedule')
                ->label(fn (): string => $this->page()->draft?->scheduled_publish_at ? 'Reschedule' : 'Schedule')
                ->icon('heroicon-o-clock')
                ->color('info')
                ->schema([
                    DateTimePicker::make('publish_at')->label('Publish at')->required()->minDate(now()),
                    Textarea::make('summary')->label('Version summary')->rows(2)->maxLength(255),
                ])
                ->action(function (array $data): void {
                    app(PageDrafts::class)->schedule($this->page(), Carbon::parse($data['publish_at']), auth()->user(), $data['summary'] ?? null);
                    $this->refreshWorkflowState();
                    Notification::make()->success()->title('Publish scheduled')->send();
                }),
            Action::make('cancel_schedule')
                ->label('Cancel schedule')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->page()->draft()->whereNotNull('scheduled_publish_at')->exists())
                ->action(function (): void {
                    app(PageDrafts::class)->cancelSchedule($this->page());
                    $this->refreshWorkflowState();
                }),
            Action::make('discard_draft')
                ->label('Discard draft')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Throws away all unpublished changes to this page and its blocks.')
                ->visible(fn (): bool => (bool) $this->page()->draft()->first()?->is_dirty)
                ->action(function (): void {
                    app(PageDrafts::class)->discard($this->page());
                    $this->refreshWorkflowState();
                    $this->fillForm();
                    Notification::make()->success()->title('Draft discarded')->send();
                }),
            Action::make('unpublish')
                ->label('Unpublish')
                ->icon('heroicon-o-eye-slash')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Immediately takes the page offline. Content and versions are kept.')
                ->visible(fn (): bool => $this->page()->status === CmsPageStatus::PUBLISHED)
                ->action(function (): void {
                    $this->page()->update(['status' => CmsPageStatus::DRAFT]);
                    $this->refreshWorkflowState();
                }),
            DeleteAction::make(),
        ];
    }

    private function page(): CmsPage
    {
        assert($this->record instanceof CmsPage);

        return $this->record;
    }

    private function refreshWorkflowState(): void
    {
        $this->record->refresh()->unsetRelation('draft');
        $this->dispatch('cms-versions-updated');
        $this->dispatch('cms-blocks-updated');
    }
}
