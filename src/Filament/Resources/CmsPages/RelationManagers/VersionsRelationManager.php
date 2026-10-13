<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsPages\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use ForumKit\Cms\Filament\Resources\CmsPages\CmsPageResource;
use ForumKit\Cms\Models\CmsPage;
use ForumKit\Cms\Models\CmsPageVersion;
use ForumKit\Cms\Services\PageDrafts;
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
                    ->modalHeading(fn (CmsPageVersion $record): string => 'Version '.$record->version_number.' snapshot')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (CmsPageVersion $record): View => view('forumkit-cms::filament.version-snapshot', ['version' => $record])),
                Action::make('restore_version')
                    ->label('Restore to draft')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (CmsPageVersion $record): string => 'Restore version '.$record->version_number)
                    ->modalDescription('Loads this version into the draft (page fields, blocks and tags). Nothing goes live until you publish.')
                    ->action(function (CmsPageVersion $record): void {
                        $page = $this->getOwnerRecord();
                        assert($page instanceof CmsPage);
                        app(PageDrafts::class)->restoreVersionToDraft($page, $record, auth()->user());
                        Notification::make()->success()->title('Version loaded into draft')->body('Version '.$record->version_number.' is now your draft. Review it, then publish.')->send();
                        $this->redirect(CmsPageResource::getUrl('edit', ['record' => $page]));
                    }),
                DeleteAction::make()
                    ->modalHeading(fn (CmsPageVersion $record): string => 'Delete version '.$record->version_number)
                    ->modalDescription('This permanently removes this version snapshot. The live page is not changed.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->modalDescription('This permanently removes the selected version snapshots. The live page is not changed.'),
                ]),
            ]);
    }
}
