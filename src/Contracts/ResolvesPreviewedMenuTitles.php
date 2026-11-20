<?php

declare(strict_types=1);

namespace Filagora\Cms\Contracts;

/**
 * An optional addition to `PreviewResolver` for previews that can also serve a menu's staged
 * heading. Kept separate so a host's own resolver keeps working; without it, a previewed menu
 * shows its live heading.
 */
interface ResolvesPreviewedMenuTitles
{
    /**
     * The staged heading of the named menu; null when it is not being previewed or stages none.
     */
    public function draftTitleFor(string $name): ?string;
}
