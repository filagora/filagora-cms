# ForumKit CMS

Pages, content blocks, menus, tags and media for ForumKit, with a staged-edit workflow (drafts,
named variants, versions, scheduled publishing and previews), as a Laravel 13 / Filament 5 plugin.
Requires [`zcwilt/forumkit-core`](https://github.com/zcwilt/forumkit-core) and
[`zcwilt/forumkit-entitlements`](https://github.com/zcwilt/forumkit-entitlements).

> **In development: not released and not on Packagist yet.** Only the data layer exists so far
> (tables and models); the services, the draft and preview workflow and the Filament screens are
> still to come. There is no frontend: the finished package will be models, services, events and
> staff screens, and you render the pages.

## What exists so far

- **Fourteen tables**, prefixed `forumkit_` by default, matching an existing site's tables index
  for index so `php artisan forumkit:adopt` can accept them: `cms_pages`, `cms_blocks`,
  `cms_block_versions`, `cms_page_versions`, `cms_page_drafts`, `cms_page_draft_variants`,
  `cms_navigations`, `cms_navigation_versions`, `cms_navigation_drafts`,
  `cms_navigation_draft_variants`, `cms_tags`, `cms_page_tag`, `cms_media_assets` and
  `cms_preview_shares`.
- **Models** for each (the tag pivot has none), swappable under `forumkit.models.<key>`. A page
  keeps its custom route as `/x`, is stamped with a publication time when it becomes published,
  and is public once that time has arrived (`CmsPage::published()`). Staged copies of blocks are
  hidden from every live read. A block's `type` is a free string, so a type the site no longer
  knows still loads. Saving a menu clears its cached items.
- **A schema snapshot** for `forumkit:adopt`. A site's own extra columns are declared with a core
  `SchemaExtension`.

## Still to come

Readers (published pages, menus, reusable blocks, media), a block type registry, the draft,
variant, version, schedule and preview workflow for pages, menus and reusable blocks, shared
preview links, and the Filament screens.

## Install

Not yet released.

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
