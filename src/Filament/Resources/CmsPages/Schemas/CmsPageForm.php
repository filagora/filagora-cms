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
        return Section::make(__('filagora-cms::ui.cms_page_form.page_content'))
            ->description(__('filagora-cms::ui.cms_page_form.set_the_page_title_url_and'))
            ->columnSpan(1)
            ->schema([
                TextInput::make('title')
                    ->label(__('filagora-cms::ui.cms_page_form.page_title'))
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state): void {
                        if (($get('slug') ?? '') === Str::slug((string) $old)) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->label(__('filagora-cms::ui.cms_page_form.slug'))
                    ->required()
                    ->maxLength(255)
                    ->unique(table: (new (Models::class('page')))->getTable(), column: 'slug', ignorable: fn (?CmsPage $record) => $record)
                    ->helperText(__('filagora-cms::ui.cms_page_form.used_for_the_default_page_route')),
                TextInput::make('custom_route')
                    ->label(__('filagora-cms::ui.cms_page_form.custom_route'))
                    ->maxLength(255)
                    ->nullable()
                    ->placeholder(__('filagora-cms::ui.cms_page_form.mycustompath'))
                    ->rule('regex:/^\/?[A-Za-z0-9\-_\/]+$/')
                    ->validationMessages(['regex' => 'The custom route may only contain letters, numbers, dashes, underscores, and forward slashes.'])
                    ->helperText(__('filagora-cms::ui.cms_page_form.use_this_when_the_page_should'))
                    ->columnSpanFull(),
                Textarea::make('excerpt')
                    ->label(__('filagora-cms::ui.cms_page_form.excerpt'))
                    ->rows(4)
                    ->columnSpanFull()
                    ->helperText(__('filagora-cms::ui.cms_page_form.optional_short_summary_used_for_listings')),
                Select::make('tag_ids')
                    ->label(__('filagora-cms::ui.cms_page_form.tags'))
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
        return Section::make(__('filagora-cms::ui.cms_page_form.publishing'))
            ->description(__('filagora-cms::ui.cms_page_form.changes_are_saved_to_a_draft'))
            ->columnSpan(1)
            ->schema([
                Placeholder::make('workflow_status')
                    ->label(__('filagora-cms::ui.cms_page_form.status'))
                    ->content(fn (?CmsPage $record): string => $record === null
                        ? 'New pages start as drafts and are not public until published.'
                        : app(WorkflowLabels::class)->describePage($record))
                    ->visible(fn (?CmsPage $record): bool => $record !== null),
            ])
            ->columns(1);
    }

    public static function seoSection(): Section
    {
        return Section::make(__('filagora-cms::ui.cms_page_form.seo'))
            ->description(__('filagora-cms::ui.cms_page_form.optional_fields_for_search_engines_and'))
            ->columnSpan(1)
            ->schema([
                TextInput::make('seo_title')->label(__('filagora-cms::ui.cms_page_form.seo_title'))->maxLength(255),
                Textarea::make('seo_description')->label(__('filagora-cms::ui.cms_page_form.seo_description'))->rows(3)->columnSpanFull(),
            ])
            ->columns(1);
    }
}
