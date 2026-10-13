<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsMediaAssets\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use ForumKit\Cms\Media\MediaAssets;

class CmsMediaAssetForm
{
    public const ROOT = 'cms-media';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('File')->schema([
                ToggleButtons::make('folder_mode')
                    ->label('Folder')
                    ->options(['existing' => 'Choose existing', 'new' => 'New folder'])
                    ->default('existing')
                    ->inline()
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'create'),
                Select::make('existing_subdirectory')
                    ->label('Existing folder')
                    ->options(fn (): array => app(MediaAssets::class)->folders())
                    ->searchable()
                    ->placeholder(self::ROOT.' (root)')
                    ->dehydrated(false)
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && ($get('folder_mode') ?? 'existing') !== 'new'),
                TextInput::make('new_subdirectory')
                    ->label('New folder')
                    ->placeholder('heroes/homepage')
                    ->rule('regex:/^[A-Za-z0-9\-_\/]*$/')
                    ->validationMessages(['regex' => 'Folder names may only contain letters, numbers, dashes, underscores, and forward slashes.'])
                    ->dehydrated(false)
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && ($get('folder_mode') ?? 'existing') === 'new')
                    ->live(onBlur: true),
                FileUpload::make('path')
                    ->label('File')
                    ->disk('public')
                    ->directory(fn (Get $get): string => self::uploadDirectory(self::selectedSubdirectory($get)))
                    ->visibility('public')
                    ->storeFileNamesIn('filename')
                    ->preserveFilenames()
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->columnSpanFull(),
                TextInput::make('alt_text')->label('Alt text')->maxLength(255)->helperText('Describe the image for people who cannot see it.'),
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
