<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsBlocks\Schemas;

use Filagora\Cms\Filament\Blocks\BlockForm;
use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Support\Models;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CmsBlockForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filagora-cms::ui.cms_block_form.block'))
                ->description(__('filagora-cms::ui.cms_block_form.a_reusable_block_is_rendered_wherever'))
                ->schema([
                    TextInput::make('slug')
                        ->label(__('filagora-cms::ui.cms_block_form.slug'))
                        ->required()
                        ->maxLength(255)
                        ->rule('regex:/^[A-Za-z0-9\-_]+$/')
                        ->validationMessages(['regex' => 'The slug may only contain letters, numbers, dashes and underscores.'])
                        ->unique(table: (new (Models::class('block')))->getTable(), column: 'slug', ignorable: fn (?CmsBlock $record) => $record)
                        ->helperText(__('filagora-cms::ui.cms_block_form.what_your_site_looks_the_block')),
                    Toggle::make('is_published')
                        ->label(__('filagora-cms::ui.cms_block_form.published'))
                        ->helperText(__('filagora-cms::ui.cms_block_form.a_block_that_is_not_published'))
                        ->default(false),
                ])
                ->columns(2),
            Section::make(__('filagora-cms::ui.cms_block_form.content'))
                ->schema(BlockForm::components())
                ->columns(2),
        ]);
    }
}
