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

- **Readers** (`CmsContent`): published pages (newest first, optionally by tag), a page by slug
  or by custom route (a draft page is served only to its preview token), menus, a published
  reusable block by slug, and media assets by path. Menus are cached under
  `cms:navigation:{name}` for `navigation_cache_seconds` (default 300) and cleared when a menu is
  saved. A request that is previewing unpublished changes is served those, through the
  `PreviewResolver` contract (the default previews nothing).
- **Menu items for a visitor** (`NavigationItems`): unknown link kinds become plain URLs, URLs
  are limited to relative paths and http(s), targets to `_self`, `_blank`, `_parent`, `_top`,
  items the visitor lacks the entitlements for are dropped, and so are empty placeholders. A site
  registers its own link kinds (a legal page, a named page of the application, an action such as
  logout) with `NavigationLinkTypes::register()`, giving a resolver from the item's `reference`
  to a URL.
- **Block types** (`BlockTypeRegistry`): nineteen generic types ship (raw HTML, hero, image,
  gallery, card grid, feature list, embed, divider, call-to-action band, stat strip, link grid,
  logo grid, accordion, icon callout, icon card grid, split info panel, checklist callout,
  comparison matrix and panel). A site registers its own and aliases for old keys. A stored type
  nobody knows still loads and is labelled as unknown. Each type says where its media asset ids
  sit in the block data, which is how `MediaAssetUsage` tells which live blocks use an asset.

- **The staged-edit workflow for pages** (`PageDrafts`). Edits go into a working draft (the page
  fields, its tags, and a staged copy of each block) and reach live content only on publish.
  - *Variants*: a page has one working draft; others are parked beside it. Start a variant from
    the current draft or from live, switch, rename and delete them. Names are unique within a
    page, ignoring case. A draft with a schedule cannot be switched away from.
  - *Publish* applies the fields, tags and blocks (edited, added and removed) in one transaction,
    refuses a slug or custom route another page uses, records a numbered version and dispatches
    `CmsPagePublished`. `PageDrafts::isPublishing()` is true meanwhile, so listeners that react to
    every saved row can wait for the event.
  - *Versions*: `PageVersioning` snapshots a page; `restoreVersionToDraft` loads one into the
    draft without touching live content; `isStale` says the page was published since the draft
    began.
  - *Schedule*: `schedule`, `cancelSchedule` and `publishDue`, run by
    `php artisan forumkit:cms:publish-due` (schedule it every minute). A draft that can no longer
    publish has its schedule cleared and the problem reported; the rest still go out.
  - *Preview*: `previewPage` and `previewPageVariant` build an unsaved in-memory copy as it would
    look once published.
  - `PageDraftDiff` describes what publishing would change, field by field, tags and blocks.
- **The same for menus** (`NavigationDrafts`): one working draft of a menu's items, named variants,
  discard, stale check, publish (saving the menu clears its cache) with a version of the menu's
  name and items and a `CmsNavigationPublished` event, restore a version to the draft, schedule,
  and `itemsForVariant` to read the items of any variant. `NavigationDraftDiff` lists items added,
  changed and removed, matched by label path ("Support › Donate"). A menu's name is an identifier
  the site relies on, so it is never staged. `forumkit:cms:publish-due` publishes due pages and
  menus.
- **The same for reusable blocks** (`BlockDrafts`): a hidden staged copy, pending-change check,
  publish with a version and a `CmsBlockPublished` event, restore from a version.

These services do not check who may call them; the Filament screens do.

## Still to come

The preview session and shared preview links, and the Filament screens (including each block type's form).

## Install

Not yet released.

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
