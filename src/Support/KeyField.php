<?php

declare(strict_types=1);

namespace Filagora\Cms\Support;

use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Entitlements\Models\EntitlementDefinition;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;

/**
 * The form field for a list of access keys (the keys a menu item is visible for). With
 * `filagora-entitlements` installed it picks from the defined entitlements; without it the keys
 * are typed, because what a key means is then up to the host's access checker.
 */
final class KeyField
{
    public static function multiple(string $name): Field
    {
        if (! app(ModuleRegistry::class)->has('entitlements') || ! class_exists(EntitlementDefinition::class)) {
            return TagsInput::make($name);
        }

        return Select::make($name)
            ->multiple()
            ->searchable()
            ->preload()
            ->options(fn (): array => EntitlementDefinition::selectorOptions());
    }
}
