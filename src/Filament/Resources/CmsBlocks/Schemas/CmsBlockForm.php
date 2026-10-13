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
            Section::make('Block')
                ->description('A reusable block is rendered wherever your site asks for its slug. Edits are saved as a draft until you publish.')
                ->schema([
                    TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(255)
                        ->rule('regex:/^[A-Za-z0-9\-_]+$/')
                        ->validationMessages(['regex' => 'The slug may only contain letters, numbers, dashes and underscores.'])
                        ->unique(table: (new (Models::class('block')))->getTable(), column: 'slug', ignorable: fn (?CmsBlock $record) => $record)
                        ->helperText('What your site looks the block up by. A change applies at once; the rest is staged.'),
                    Toggle::make('is_published')
                        ->label('Published')
                        ->helperText('A block that is not published is not served to visitors.')
                        ->default(false),
                ])
                ->columns(2),
            Section::make('Content')
                ->schema(BlockForm::components())
                ->columns(2),
        ]);
    }
}
