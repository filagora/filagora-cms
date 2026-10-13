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
                ->label(__('filagora-cms::ui.list_cms_media_assets.bulk_upload'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading(__('filagora-cms::ui.list_cms_media_assets.bulk_upload_media_assets'))
                ->modalDescription(__('filagora-cms::ui.list_cms_media_assets.upload_several_files_and_create_one'))
                ->schema([
                    ToggleButtons::make('folder_mode')
                        ->label(__('filagora-cms::ui.list_cms_media_assets.folder'))
                        ->options(['existing' => 'Choose existing', 'new' => 'New folder'])
                        ->default('existing')
                        ->inline()
                        ->live(),
                    Select::make('existing_subdirectory')
                        ->label(__('filagora-cms::ui.list_cms_media_assets.existing_folder'))
                        ->options(fn (): array => app(MediaAssets::class)->folders())
                        ->searchable()
                        ->placeholder(__('filagora-cms::ui.list_cms_media_assets.cmsmedia_root'))
                        ->visible(fn (Get $get): bool => ($get('folder_mode') ?? 'existing') !== 'new'),
                    TextInput::make('new_subdirectory')
                        ->label(__('filagora-cms::ui.list_cms_media_assets.new_folder'))
                        ->placeholder(__('filagora-cms::ui.list_cms_media_assets.heroeshomepage'))
                        ->rule('regex:/^[A-Za-z0-9\-_\/]*$/')
                        ->validationMessages(['regex' => 'Folder names may only contain letters, numbers, dashes, underscores, and forward slashes.'])
                        ->visible(fn (Get $get): bool => ($get('folder_mode') ?? 'existing') === 'new')
                        ->live(onBlur: true),
                    FileUpload::make('paths')
                        ->label(__('filagora-cms::ui.list_cms_media_assets.files'))
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
