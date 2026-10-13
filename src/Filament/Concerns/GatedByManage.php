<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Concerns;

use ForumKit\Cms\Support\Abilities;
use ForumKit\Entitlements\Filament\Concerns\GatedByAbility;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every CMS screen needs the `forumkit.cms.manage` ability; nothing is allowed without it.
 */
trait GatedByManage
{
    use GatedByAbility;

    protected static function abilityFor(string|UnitEnum $action, ?Model $record): ?string
    {
        return Abilities::MANAGE;
    }
}
