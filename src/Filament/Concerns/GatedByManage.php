<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Concerns;

use Filagora\Cms\Support\Abilities;
use Filagora\Entitlements\Filament\Concerns\GatedByAbility;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every CMS screen needs the `filagora.cms.manage` ability; nothing is allowed without it.
 */
trait GatedByManage
{
    use GatedByAbility;

    protected static function abilityFor(string|UnitEnum $action, ?Model $record): ?string
    {
        return Abilities::MANAGE;
    }
}
