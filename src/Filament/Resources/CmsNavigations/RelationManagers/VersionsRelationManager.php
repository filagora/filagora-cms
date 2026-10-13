<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsNavigations\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use ForumKit\Cms\Filament\Resources\CmsNavigations\CmsNavigationResource;
use ForumKit\Cms\Models\CmsNavigation;
use ForumKit\Cms\Models\CmsNavigationVersion;
use ForumKit\Cms\Services\NavigationDrafts;
use ForumKit\Core\Support\UserModel;
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
                TextColumn::make('version_number')->label('Version')->sortable(),
                TextColumn::make('change_summary')->label('Summary')->wrap()->placeholder('No summary')->searchable(),
                TextColumn::make('createdBy.'.UserModel::titleAttribute())->label('Created by')->placeholder('System'),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('view_snapshot')
                    ->label('View snapshot')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->modalHeading(fn (CmsNavigationVersion $record): string => 'Version '.$record->version_number.' snapshot')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (CmsNavigationVersion $record): View => view('forumkit-cms::filament.version-snapshot', ['version' => $record])),
                Action::make('restore_version')
                    ->label('Restore to draft')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (CmsNavigationVersion $record): string => 'Restore version '.$record->version_number)
                    ->modalDescription('Loads this version into the draft (its links). Nothing goes live until you publish.')
                    ->action(function (CmsNavigationVersion $record): void {
                        $menu = $this->getOwnerRecord();
                        assert($menu instanceof CmsNavigation);
                        app(NavigationDrafts::class)->restoreVersionToDraft($menu, $record, auth()->user());
                        Notification::make()->success()->title('Version loaded into draft')->body('Version '.$record->version_number.' is now your draft. Review it, then publish.')->send();
                        $this->redirect(CmsNavigationResource::getUrl('edit', ['record' => $menu]));
                    }),
                DeleteAction::make()
                    ->modalHeading(fn (CmsNavigationVersion $record): string => 'Delete version '.$record->version_number)
                    ->modalDescription('This permanently removes this version snapshot. The live menu is not changed.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->modalDescription('This permanently removes the selected version snapshots. The live menu is not changed.'),
                ]),
            ]);
    }
}
