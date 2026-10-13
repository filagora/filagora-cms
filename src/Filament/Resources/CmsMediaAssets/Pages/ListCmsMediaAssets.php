<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsMediaAssets\Pages;

use Filagora\Cms\Filament\Resources\CmsMediaAssets\CmsMediaAssetResource;
use Filagora\Cms\Filament\Resources\CmsMediaAssets\Schemas\CmsMediaAssetForm;
use Filagora\Cms\Media\MediaAssets;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListCmsMediaAssets extends ListRecords
{
    protected static string $resource = CmsMediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkUpload')
                ->label('Bulk upload')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Bulk upload media assets')
                ->modalDescription('Upload several files and create one media asset record for each.')
                ->schema([
                    ToggleButtons::make('folder_mode')
                        ->label('Folder')
                        ->options(['existing' => 'Choose existing', 'new' => 'New folder'])
                        ->default('existing')
                        ->inline()
                        ->live(),
                    Select::make('existing_subdirectory')
                        ->label('Existing folder')
                        ->options(fn (): array => app(MediaAssets::class)->folders())
                        ->searchable()
                        ->placeholder('cms-media (root)')
                        ->visible(fn (Get $get): bool => ($get('folder_mode') ?? 'existing') !== 'new'),
                    TextInput::make('new_subdirectory')
                        ->label('New folder')
                        ->placeholder('heroes/homepage')
                        ->rule('regex:/^[A-Za-z0-9\-_\/]*$/')
                        ->validationMessages(['regex' => 'Folder names may only contain letters, numbers, dashes, underscores, and forward slashes.'])
                        ->visible(fn (Get $get): bool => ($get('folder_mode') ?? 'existing') === 'new')
                        ->live(onBlur: true),
                    FileUpload::make('paths')
                        ->label('Files')
                        ->disk('public')
                        ->directory(fn (Get $get): string => CmsMediaAssetForm::uploadDirectory(CmsMediaAssetForm::selectedSubdirectory($get)))
                        ->visibility('public')
                        ->multiple()
                        ->storeFileNamesIn('filenames')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $created = app(MediaAssets::class)->createFromUploadedPaths(
                        (array) ($data['paths'] ?? []),
                        (array) ($data['filenames'] ?? []),
                        auth()->id(),
                    );

                    Notification::make()
                        ->title($created->count() === 1 ? '1 media asset uploaded' : $created->count().' media assets uploaded')
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
