<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsPages\Schemas;

use Filagora\Cms\Filament\Resources\CmsPages\Pages\EditCmsPage;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Support\Models;
use Filagora\Cms\Support\WorkflowLabels;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CmsPageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'xl' => 2])->schema([
                    Grid::make(1)->schema([
                        self::contentSection(),
                        self::seoSection(),
                    ]),
                    Grid::make(1)->schema([
                        View::make('filagora-cms::filament.pending-changes')
                            ->viewData(['record' => $schema->getRecord()])
                            ->visible(fn (?CmsPage $record): bool => $record !== null),
                        self::publishingSection(),
                        View::make('filagora-cms::filament.page-blocks-panel')
                            ->viewData(['record' => $schema->getRecord(), 'pageClass' => EditCmsPage::class])
                            ->visible(fn (?CmsPage $record): bool => $record !== null),
                    ]),
                ]),
            ]);
    }

    public static function contentSection(): Section
    {
        return Section::make('Page content')
            ->description('Set the page title, URL, and summary content first.')
            ->columnSpan(1)
            ->schema([
                TextInput::make('title')
                    ->label('Page title')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state): void {
                        if (($get('slug') ?? '') === Str::slug((string) $old)) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(table: (new (Models::class('page')))->getTable(), column: 'slug', ignorable: fn (?CmsPage $record) => $record)
                    ->helperText('Used for the default page route at /pages/{slug}.'),
                TextInput::make('custom_route')
                    ->label('Custom route')
                    ->maxLength(255)
                    ->nullable()
                    ->placeholder('/my-custom-path')
                    ->rule('regex:/^\/?[A-Za-z0-9\-_\/]+$/')
                    ->validationMessages(['regex' => 'The custom route may only contain letters, numbers, dashes, underscores, and forward slashes.'])
                    ->helperText('Use this when the page should live at a custom path like /privacy instead of /pages/{slug}. A leading slash is added for you.')
                    ->columnSpanFull(),
                Textarea::make('excerpt')
                    ->label('Excerpt')
                    ->rows(4)
                    ->columnSpanFull()
                    ->helperText('Optional short summary used for listings, previews, or search context.'),
                Select::make('tag_ids')
                    ->label('Tags')
                    ->options(fn (): array => Models::class('tag')::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function publishingSection(): Section
    {
        return Section::make('Publishing')
            ->description('Changes are saved to a draft. Use Preview to check them, then Publish to make them live.')
            ->columnSpan(1)
            ->schema([
                Placeholder::make('workflow_status')
                    ->label('Status')
                    ->content(fn (?CmsPage $record): string => $record === null
                        ? 'New pages start as drafts and are not public until published.'
                        : app(WorkflowLabels::class)->describePage($record))
                    ->visible(fn (?CmsPage $record): bool => $record !== null),
            ])
            ->columns(1);
    }

    public static function seoSection(): Section
    {
        return Section::make('SEO')
            ->description('Optional fields for search engines and social previews.')
            ->columnSpan(1)
            ->schema([
                TextInput::make('seo_title')->label('SEO title')->maxLength(255),
                Textarea::make('seo_description')->label('SEO description')->rows(3)->columnSpanFull(),
            ])
            ->columns(1);
    }
}
