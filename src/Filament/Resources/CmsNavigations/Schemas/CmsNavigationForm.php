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
            Section::make(__('filagora-cms::ui.cms_navigation_form.menu'))
                ->description(__('filagora-cms::ui.cms_navigation_form.one_record_per_menu_location_add'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('filagora-cms::ui.cms_navigation_form.menu_key'))
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText(__('filagora-cms::ui.cms_navigation_form.the_name_your_site_looks_the')),
                ]),
            Section::make(__('filagora-cms::ui.cms_navigation_form.links'))
                ->description(__('filagora-cms::ui.cms_navigation_form.use_child_links_to_build_a'))
                ->schema([
                    Repeater::make('items')
                        ->label(__('filagora-cms::ui.cms_navigation_form.links'))
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
            TextInput::make('label')->label(__('filagora-cms::ui.cms_navigation_form.link_label'))->placeholder(__('filagora-cms::ui.cms_navigation_form.about_us'))->required(),
            Select::make('type')
                ->label(__('filagora-cms::ui.cms_navigation_form.link_type'))
                ->options(fn (): array => $kinds()->options())
                ->default('url')
                ->live()
                ->required(),
            TextInput::make('url')
                ->label(__('filagora-cms::ui.cms_navigation_form.url'))
                ->required(fn (Get $get): bool => $get('type') === 'url')
                ->visible(fn (Get $get): bool => $get('type') === 'url')
                ->placeholder(__('filagora-cms::ui.cms_navigation_form.forums_or_httpsexamplecom'))
                ->helperText(__('filagora-cms::ui.cms_navigation_form.relative_urls_stay_onsite_full_urls')),
            Select::make('reference')
                ->label(__('filagora-cms::ui.cms_navigation_form.reference'))
                ->options(fn (Get $get): array => $kinds()->references((string) $get('type')))
                ->required(fn (Get $get): bool => $get('type') !== 'url')
                ->visible(fn (Get $get): bool => $get('type') !== 'url' && $kinds()->has((string) $get('type')))
                ->helperText(__('filagora-cms::ui.cms_navigation_form.resolved_by_the_application_from_a')),
            Select::make('target')
                ->label(__('filagora-cms::ui.cms_navigation_form.target'))
                ->options(['_self' => '_self', '_blank' => '_blank', '_parent' => '_parent', '_top' => '_top'])
                ->default('_self')
                ->visible(fn (Get $get): bool => $get('type') === 'url')
                ->helperText(__('filagora-cms::ui.cms_navigation_form.open_in_a_new_tab_only')),
            Select::make('entitlements')
                ->label(__('filagora-cms::ui.cms_navigation_form.visible_for_entitlements'))
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn (): array => EntitlementDefinition::selectorOptions())
                ->helperText(__('filagora-cms::ui.cms_navigation_form.leave_empty_to_show_this_link'))
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
