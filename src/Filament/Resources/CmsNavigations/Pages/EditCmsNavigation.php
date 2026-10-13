<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsNavigations\Pages;

use Filagora\Cms\Exceptions\DraftOperationFailed;
use Filagora\Cms\Filament\Actions\DraftVariantActions;
use Filagora\Cms\Filament\Actions\SharePreviewLinkAction;
use Filagora\Cms\Filament\Resources\CmsNavigations\CmsNavigationResource;
use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Preview\PreviewSession;
use Filagora\Cms\Services\NavigationDraftDiff;
use Filagora\Cms\Services\NavigationDrafts;
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
        return parent::getSaveFormAction()->label(__('filagora-cms::ui.edit_cms_navigation.save_draft'));
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
                ->label(__('filagora-cms::ui.edit_cms_navigation.preview'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->action(function (): void {
                    app(NavigationDrafts::class)->ensureDraft($this->menu(), auth()->user());
                    app(PreviewSession::class)->addNavigation($this->menu());

                    Notification::make()->success()->title(__('filagora-cms::ui.edit_cms_navigation.previewing_this_menu'))->body(__('filagora-cms::ui.edit_cms_navigation.browse_the_site_this_menu_shows'))->send();
                }),
            Action::make('publish')
                ->label(__('filagora-cms::ui.edit_cms_navigation.publish'))
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->modalHeading(__('filagora-cms::ui.edit_cms_navigation.publish_menu_changes'))
                ->modalDescription(fn (): HtmlString => $this->publishDescription())
                ->schema([
                    Textarea::make('summary')->label(__('filagora-cms::ui.edit_cms_navigation.version_summary'))->rows(2)->maxLength(255),
                ])
                ->action(function (array $data): void {
                    try {
                        $version = app(NavigationDrafts::class)->publish($this->menu(), auth()->user(), $data['summary'] ?? null);
                    } catch (DraftOperationFailed $e) {
                        Notification::make()->danger()->title(__('filagora-cms::ui.edit_cms_navigation.cannot_publish'))->body($e->getMessage())->send();

                        return;
                    }

                    $this->refreshWorkflowState();
                    $this->fillForm();
                    Notification::make()->success()->title(__('filagora-cms::ui.edit_cms_navigation.published'))->body(__('filagora-cms::ui.common.version_is_live', ['number' => $version->version_number]))->send();
                }),
            Action::make('schedule')
                ->label(fn (): string => $this->menu()->draft?->scheduled_publish_at ? 'Reschedule' : 'Schedule')
                ->icon('heroicon-o-clock')
                ->color('info')
                ->schema([
                    DateTimePicker::make('publish_at')->label(__('filagora-cms::ui.edit_cms_navigation.publish_at'))->required()->minDate(now()),
                    Textarea::make('summary')->label(__('filagora-cms::ui.edit_cms_navigation.version_summary'))->rows(2)->maxLength(255),
                ])
                ->action(function (array $data): void {
                    app(NavigationDrafts::class)->schedule($this->menu(), Carbon::parse($data['publish_at']), auth()->user(), $data['summary'] ?? null);
                    $this->refreshWorkflowState();
                    Notification::make()->success()->title(__('filagora-cms::ui.edit_cms_navigation.publish_scheduled'))->send();
                }),
            Action::make('cancel_schedule')
                ->label(__('filagora-cms::ui.edit_cms_navigation.cancel_schedule'))
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->menu()->draft()->whereNotNull('scheduled_publish_at')->exists())
                ->action(function (): void {
                    app(NavigationDrafts::class)->cancelSchedule($this->menu());
                    $this->refreshWorkflowState();
                }),
            Action::make('discard_draft')
                ->label(__('filagora-cms::ui.edit_cms_navigation.discard_draft'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('filagora-cms::ui.edit_cms_navigation.throws_away_all_unpublished_changes_to'))
                ->visible(fn (): bool => (bool) $this->menu()->draft()->first()?->is_dirty)
                ->action(function (): void {
                    app(NavigationDrafts::class)->discard($this->menu());
                    $this->refreshWorkflowState();
                    $this->fillForm();
                    Notification::make()->success()->title(__('filagora-cms::ui.edit_cms_navigation.draft_discarded'))->send();
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
