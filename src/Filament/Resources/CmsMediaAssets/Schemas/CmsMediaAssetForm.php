<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsMediaAssets\Schemas;

use Filagora\Cms\Media\MediaAssets;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CmsMediaAssetForm
{
    public const ROOT = 'cms-media';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filagora-cms::ui.cms_media_asset_form.file'))->schema([
                ToggleButtons::make('folder_mode')
                    ->label(__('filagora-cms::ui.cms_media_asset_form.folder'))
                    ->options(['existing' => 'Choose existing', 'new' => 'New folder'])
                    ->default('existing')
                    ->inline()
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'create'),
                Select::make('existing_subdirectory')
                    ->label(__('filagora-cms::ui.cms_media_asset_form.existing_folder'))
                    ->options(fn (): array => app(MediaAssets::class)->folders())
                    ->searchable()
                    ->placeholder(self::ROOT.' (root)')
                    ->dehydrated(false)
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && ($get('folder_mode') ?? 'existing') !== 'new'),
                TextInput::make('new_subdirectory')
                    ->label(__('filagora-cms::ui.cms_media_asset_form.new_folder'))
                    ->placeholder(__('filagora-cms::ui.cms_media_asset_form.heroeshomepage'))
                    ->rule('regex:/^[A-Za-z0-9\-_\/]*$/')
                    ->validationMessages(['regex' => 'Folder names may only contain letters, numbers, dashes, underscores, and forward slashes.'])
                    ->dehydrated(false)
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && ($get('folder_mode') ?? 'existing') === 'new')
                    ->live(onBlur: true),
                FileUpload::make('path')
                    ->label(__('filagora-cms::ui.cms_media_asset_form.file'))
                    ->disk('public')
                    ->directory(fn (Get $get): string => self::uploadDirectory(self::selectedSubdirectory($get)))
                    ->visibility('public')
                    ->storeFileNamesIn('filename')
                    ->preserveFilenames()
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->columnSpanFull(),
                TextInput::make('alt_text')->label(__('filagora-cms::ui.cms_media_asset_form.alt_text'))->maxLength(255)->helperText(__('filagora-cms::ui.cms_media_asset_form.describe_the_image_for_people_who')),
            ]),
        ]);
    }

    public static function uploadDirectory(?string $subdirectory): string
    {
        $normalized = trim(str_replace('\\', '/', (string) $subdirectory), '/');

        return $normalized === '' ? self::ROOT : self::ROOT.'/'.$normalized;
    }

    public static function selectedSubdirectory(Get $get): string
    {
        return (string) (($get('folder_mode') ?? 'existing') === 'new'
            ? ($get('new_subdirectory') ?? '')
            : ($get('existing_subdirectory') ?? ''));
    }
}
