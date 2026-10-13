<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsNavigations\RelationManagers;

use Filagora\Cms\Filament\Resources\CmsNavigations\CmsNavigationResource;
use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Models\CmsNavigationVersion;
use Filagora\Cms\Services\NavigationDrafts;
use Filagora\Core\Support\UserModel;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    /**
     * Receiving the event re-renders the table so new versions appear without a page reload.
     */
    #[On('cms-versions-updated')]
    public function refreshVersions(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version_number')
            ->defaultSort('version_number', 'desc')
            ->columns([
                TextColumn::make('version_number')->label(__('filagora-cms::ui.versions_relation_manager.version'))->sortable(),
                TextColumn::make('change_summary')->label(__('filagora-cms::ui.versions_relation_manager.summary'))->wrap()->placeholder(__('filagora-cms::ui.versions_relation_manager.no_summary'))->searchable(),
                TextColumn::make('createdBy.'.UserModel::titleAttribute())->label(__('filagora-cms::ui.versions_relation_manager.created_by'))->placeholder(__('filagora-cms::ui.versions_relation_manager.system')),
                TextColumn::make('created_at')->label(__('filagora-cms::ui.versions_relation_manager.created'))->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('view_snapshot')
                    ->label(__('filagora-cms::ui.versions_relation_manager.view_snapshot'))
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->modalHeading(fn (CmsNavigationVersion $record): string => 'Version '.$record->version_number.' snapshot')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (CmsNavigationVersion $record): View => view('filagora-cms::filament.version-snapshot', ['version' => $record])),
                Action::make('restore_version')
                    ->label(__('filagora-cms::ui.versions_relation_manager.restore_to_draft'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (CmsNavigationVersion $record): string => 'Restore version '.$record->version_number)
                    ->modalDescription(__('filagora-cms::ui.versions_relation_manager.loads_this_version_into_the_draft_2'))
                    ->action(function (CmsNavigationVersion $record): void {
                        $menu = $this->getOwnerRecord();
                        assert($menu instanceof CmsNavigation);
                        app(NavigationDrafts::class)->restoreVersionToDraft($menu, $record, auth()->user());
                        Notification::make()->success()->title(__('filagora-cms::ui.versions_relation_manager.version_loaded_into_draft'))->body(__('filagora-cms::ui.common.version_is_draft', ['number' => $record->version_number]))->send();
                        $this->redirect(CmsNavigationResource::getUrl('edit', ['record' => $menu]));
                    }),
                DeleteAction::make()
                    ->modalHeading(fn (CmsNavigationVersion $record): string => 'Delete version '.$record->version_number)
                    ->modalDescription(__('filagora-cms::ui.versions_relation_manager.this_permanently_removes_this_version_snapshot_2')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->modalDescription(__('filagora-cms::ui.versions_relation_manager.this_permanently_removes_the_selected_version_2')),
                ]),
            ]);
    }
}
