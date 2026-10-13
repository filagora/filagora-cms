<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsNavigations\Schemas;

use Filagora\Cms\Navigation\NavigationLinkTypes;
use Filagora\Entitlements\Models\EntitlementDefinition;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CmsNavigationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Menu')
                ->description('One record per menu location. Add links in the order they should appear.')
                ->schema([
                    TextInput::make('name')
                        ->label('Menu key')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('The name your site looks the menu up by, for example header or footer.'),
                ]),
            Section::make('Links')
                ->description('Use child links to build a dropdown; a dropdown link can in turn hold its own links as a group (two levels below a top-level link). Footer menus should usually stay flat.')
                ->schema([
                    Repeater::make('items')
                        ->label('Links')
                        ->schema(self::itemSchema())
                        ->defaultItems(0)
                        ->addActionLabel('Add link')
                        ->reorderableWithButtons()
                        ->cloneable()
                        ->collapsible()
                        ->collapsed()
                        ->itemLabel(fn (array $state): string => self::itemLabel($state))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * @return list<Component>
     */
    private static function itemSchema(int $levelsBelow = 2): array
    {
        $kinds = fn (): NavigationLinkTypes => app(NavigationLinkTypes::class);

        $schema = [
            TextInput::make('label')->label('Link label')->placeholder('About us')->required(),
            Select::make('type')
                ->label('Link type')
                ->options(fn (): array => $kinds()->options())
                ->default('url')
                ->live()
                ->required(),
            TextInput::make('url')
                ->label('URL')
                ->required(fn (Get $get): bool => $get('type') === 'url')
                ->visible(fn (Get $get): bool => $get('type') === 'url')
                ->placeholder('/forums or https://example.com')
                ->helperText('Relative URLs stay on-site; full URLs can link externally. For a heading that only groups links, use #.'),
            Select::make('reference')
                ->label('Reference')
                ->options(fn (Get $get): array => $kinds()->references((string) $get('type')))
                ->required(fn (Get $get): bool => $get('type') !== 'url')
                ->visible(fn (Get $get): bool => $get('type') !== 'url' && $kinds()->has((string) $get('type')))
                ->helperText('Resolved by the application from a fixed list, at render time.'),
            Select::make('target')
                ->label('Target')
                ->options(['_self' => '_self', '_blank' => '_blank', '_parent' => '_parent', '_top' => '_top'])
                ->default('_self')
                ->visible(fn (Get $get): bool => $get('type') === 'url')
                ->helperText('Open in a new tab only when that improves the experience, usually for external links.'),
            Select::make('entitlements')
                ->label('Visible for entitlements')
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn (): array => EntitlementDefinition::selectorOptions())
                ->helperText('Leave empty to show this link to everyone. When set, it is shown to anyone holding any selected entitlement.')
                ->columnSpanFull(),
        ];

        if ($levelsBelow > 0) {
            $isDropdownLevel = $levelsBelow === 2;
            $schema[] = Repeater::make('children')
                ->label($isDropdownLevel ? 'Dropdown links' : 'Links in this group')
                ->schema(self::itemSchema($levelsBelow - 1))
                ->defaultItems(0)
                ->addActionLabel($isDropdownLevel ? 'Add child link' : 'Add link to group')
                ->reorderableWithButtons()
                ->cloneable()
                ->collapsible()
                ->collapsed()
                ->itemLabel(fn (array $state): string => self::itemLabel($state))
                ->columnSpanFull();
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function itemLabel(array $state): string
    {
        $label = trim((string) ($state['label'] ?? ''));
        $type = (string) ($state['type'] ?? 'url');
        $children = is_array($state['children'] ?? null) ? count($state['children']) : 0;
        $entitlements = is_array($state['entitlements'] ?? null)
            ? count(array_filter($state['entitlements'], static fn (mixed $entitlement): bool => is_string($entitlement) && trim($entitlement) !== ''))
            : 0;

        if ($label === '') {
            return $children > 0 ? "New link ({$children} child links)" : 'New link';
        }

        $notes = array_filter([
            $type !== 'url' ? str_replace('_', ' ', $type) : null,
            $entitlements > 0 ? $entitlements.' entitlement'.($entitlements === 1 ? '' : 's') : null,
            $children > 0 ? $children.' child links' : null,
        ]);

        return $notes === [] ? $label : $label.' ('.implode(', ', $notes).')';
    }
}
