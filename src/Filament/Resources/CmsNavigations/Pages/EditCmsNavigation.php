<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsNavigations\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use ForumKit\Cms\Exceptions\DraftOperationFailed;
use ForumKit\Cms\Filament\Actions\DraftVariantActions;
use ForumKit\Cms\Filament\Actions\SharePreviewLinkAction;
use ForumKit\Cms\Filament\Resources\CmsNavigations\CmsNavigationResource;
use ForumKit\Cms\Models\CmsNavigation;
use ForumKit\Cms\Preview\PreviewSession;
use ForumKit\Cms\Services\NavigationDraftDiff;
use ForumKit\Cms\Services\NavigationDrafts;
use ForumKit\Cms\Support\WorkflowLabels;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Edits go to the working draft; the live menu is untouched until Publish. The menu's name is an
 * identifier the site relies on, so a rename applies at once; only the items are staged.
 */
class EditCmsNavigation extends EditRecord
{
    protected static string $resource = CmsNavigationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_merge($data, ['items' => app(NavigationDrafts::class)->editorItems($this->menu(), auth()->user())]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof CmsNavigation);
        $name = trim((string) ($data['name'] ?? ''));

        if ($name !== '' && $name !== $record->name) {
            $record->update(['name' => $name]);
        }

        app(NavigationDrafts::class)->saveDraft($record, is_array($data['items'] ?? null) ? $data['items'] : [], auth()->user());

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved';
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
        return app(WorkflowLabels::class)->describeNavigation($this->menu()->refresh());
    }

    protected function getHeaderActions(): array
    {
        return [
            DraftVariantActions::make(
                fn (): CmsNavigation => $this->menu(),
                fn (): NavigationDrafts => app(NavigationDrafts::class),
                function (): void {
                    $this->refreshWorkflowState();
                    $this->fillForm();
                },
            ),
            SharePreviewLinkAction::make(
                fn (): CmsNavigation => $this->menu(),
                fn (): NavigationDrafts => app(NavigationDrafts::class),
            ),
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->action(function (): void {
                    app(NavigationDrafts::class)->ensureDraft($this->menu(), auth()->user());
                    app(PreviewSession::class)->addNavigation($this->menu());

                    Notification::make()->success()->title('Previewing this menu')->body('Browse the site: this menu shows its draft until you leave the preview.')->send();
                }),
            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->modalHeading('Publish menu changes')
                ->modalDescription(fn (): HtmlString => $this->publishDescription())
                ->schema([
                    Textarea::make('summary')->label('Version summary')->rows(2)->maxLength(255),
                ])
                ->action(function (array $data): void {
                    try {
                        $version = app(NavigationDrafts::class)->publish($this->menu(), auth()->user(), $data['summary'] ?? null);
                    } catch (DraftOperationFailed $e) {
                        Notification::make()->danger()->title('Cannot publish')->body($e->getMessage())->send();

                        return;
                    }

                    $this->refreshWorkflowState();
                    $this->fillForm();
                    Notification::make()->success()->title('Published')->body('Version '.$version->version_number.' is now live.')->send();
                }),
            Action::make('schedule')
                ->label(fn (): string => $this->menu()->draft?->scheduled_publish_at ? 'Reschedule' : 'Schedule')
                ->icon('heroicon-o-clock')
                ->color('info')
                ->schema([
                    DateTimePicker::make('publish_at')->label('Publish at')->required()->minDate(now()),
                    Textarea::make('summary')->label('Version summary')->rows(2)->maxLength(255),
                ])
                ->action(function (array $data): void {
                    app(NavigationDrafts::class)->schedule($this->menu(), Carbon::parse($data['publish_at']), auth()->user(), $data['summary'] ?? null);
                    $this->refreshWorkflowState();
                    Notification::make()->success()->title('Publish scheduled')->send();
                }),
            Action::make('cancel_schedule')
                ->label('Cancel schedule')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->menu()->draft()->whereNotNull('scheduled_publish_at')->exists())
                ->action(function (): void {
                    app(NavigationDrafts::class)->cancelSchedule($this->menu());
                    $this->refreshWorkflowState();
                }),
            Action::make('discard_draft')
                ->label('Discard draft')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Throws away all unpublished changes to this menu.')
                ->visible(fn (): bool => (bool) $this->menu()->draft()->first()?->is_dirty)
                ->action(function (): void {
                    app(NavigationDrafts::class)->discard($this->menu());
                    $this->refreshWorkflowState();
                    $this->fillForm();
                    Notification::make()->success()->title('Draft discarded')->send();
                }),
            DeleteAction::make(),
        ];
    }

    private function menu(): CmsNavigation
    {
        assert($this->record instanceof CmsNavigation);

        return $this->record;
    }

    private function publishDescription(): HtmlString
    {
        $diff = app(NavigationDraftDiff::class)->forNavigation($this->menu());
        $stale = app(NavigationDrafts::class)->isStale($this->menu())
            ? '<p><strong>Warning:</strong> this menu was published again after you started this draft. Publishing will overwrite those newer changes.</p>'
            : '';

        if ($diff['count'] === 0) {
            return new HtmlString($stale.'<p>The draft has no changes compared with the live menu.</p>');
        }

        $lines = implode('', array_map(
            fn (array $change): string => '<li><strong>'.e($change['status']).':</strong> '.e($change['label'])
                .($change['details'] !== [] ? ' ('.e(implode('; ', $change['details'])).')' : '').'</li>',
            array_slice($diff['changes'], 0, 15),
        ));
        $more = $diff['count'] > 15 ? '<p>…and '.($diff['count'] - 15).' more.</p>' : '';

        return new HtmlString(
            $stale
            .'<p>'.$diff['count'].' pending '.Str::plural('change', $diff['count']).' will go live and a new version will be recorded:</p>'
            .'<ul class="mt-2 list-disc pl-5 text-left">'.$lines.'</ul>'.$more
        );
    }

    private function refreshWorkflowState(): void
    {
        $this->record->refresh()->unsetRelation('draft');
        $this->dispatch('cms-versions-updated');
    }
}
