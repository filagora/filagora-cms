<?php

declare(strict_types=1);

namespace ForumKit\Cms\Support;

/**
 * The Gate abilities of the CMS. Each maps to an entitlement key in `forumkit-cms.gates`, which a
 * host can point at its own keys.
 */
final class Abilities
{
    /** Create, edit, publish and delete content. Includes previewing. */
    public const MANAGE = 'forumkit.cms.manage';

    /** See unpublished drafts of menus and pages, without editing them. */
    public const PREVIEW = 'forumkit.cms.preview';
}
