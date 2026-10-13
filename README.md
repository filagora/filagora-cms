# Filagora CMS

Pages, content blocks, menus, tags and media for Filagora, with a staged-edit workflow (drafts,
named variants, versions, scheduled publishing and previews), as a Laravel 13 / Filament 5 plugin.
Requires [`filagora/filagora-core`](https://github.com/filagora/filagora-core) and
[`filagora/filagora-entitlements`](https://github.com/filagora/filagora-entitlements).

> **In development: not released and not on Packagist yet.** Only the data layer exists so far
> (tables and models); the services, the draft and preview workflow and the Filament screens are
> still to come. There is no frontend: the finished package will be models, services, events and
> staff screens, and you render the pages.

## What exists so far

- **Fourteen tables**, prefixed `filagora_` by default, matching an existing site's tables index
  for index so `php artisan filagora:adopt` can accept them: `cms_pages`, `cms_blocks`,
  `cms_block_versions`, `cms_page_versions`, `cms_page_drafts`, `cms_page_draft_variants`,
  `cms_navigations`, `cms_navigation_versions`, `cms_navigation_drafts`,
  `cms_navigation_draft_variants`, `cms_tags`, `cms_page_tag`, `cms_media_assets` and
  `cms_preview_shares`.
- **Models** for each (the tag pivot has none), swappable under `filagora.models.<key>`. A page
  keeps its custom route as `/x`, is stamped with a publication time when it becomes published,
  and is public once that time has arrived (`CmsPage::published()`). Staged copies of blocks are
  hidden from every live read. A block's `type` is a free string, so a type the site no longer
  knows still loads. Saving a menu clears its cached items.
- **A schema snapshot** for `filagora:adopt`. A site's own extra columns are declared with a core
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
    `php artisan filagora:cms:publish-due` (schedule it every minute). A draft that can no longer
    publish has its schedule cleared and the problem reported; the rest still go out.
  - *Preview*: `previewPage` and `previewPageVariant` build an unsaved in-memory copy as it would
    look once published.
  - `PageDraftDiff` describes what publishing would change, field by field, tags and blocks.
- **The same for menus** (`NavigationDrafts`): one working draft of a menu's items, named variants,
  discard, stale check, publish (saving the menu clears its cache) with a version of the menu's
  name and items and a `CmsNavigationPublished` event, restore a version to the draft, schedule,
  and `itemsForVariant` to read the items of any variant. `NavigationDraftDiff` lists items added,
  changed and removed, matched by label path ("Support › Donate"). A menu's name is an identifier
  the site relies on, so it is never staged. `filagora:cms:publish-due` publishes due pages and
  menus.
- **The same for reusable blocks** (`BlockDrafts`): a hidden staged copy, pending-change check,
  publish with a version and a `CmsBlockPublished` event, restore from a version.

These services do not check who may call them; the Filament screens do.

- **Previews.** `PreviewSession` is the `PreviewResolver` the readers use, so a browser that is
  previewing sees draft menus and pages at their normal public addresses while everything else
  shows live content. Two things start a preview: an editor adding items (`addPage`,
  `addNavigation`; each served from its working draft; needs `filagora.cms.manage` or
  `filagora.cms.preview`), or a **shared preview link**.
  - `PreviewShares::create()` bundles chosen menus and pages, each pinned to a named variant, with
    an expiry in days and a label. The link's token is long, random and never serialised.
    `open($token, $session)` starts the preview for whoever holds it and counts the open;
    `revoke()` ends it, and so does expiry, at the next request. The holder previews in their own
    browser session; nothing is made public. `landingPage()` says where to send them.
  - `items()` describes what is being previewed (for a banner) and `currentSelection()` bundles an
    editor's own preview for sharing.
  - `MarkPreviewResponsesUncacheable` marks every response `no-store, private` and
    `noindex, nofollow` while a preview is in effect. It is added to the `web` group unless you
    set `filagora-cms.preview.uncacheable_middleware` to false.
  - The routes, the controllers and the banner are yours: this package has no frontend.
- **Two abilities**, `filagora.cms.manage` and `filagora.cms.preview`, mapped to entitlements in
  `filagora-cms.gates`.

- **Filament screens** (`FilagoraCmsPlugin`, in a "Content" navigation group; every screen needs
  `filagora.cms.manage`):
  - *Pages*: list with a state badge and filter; create; an editor that works on the draft
    ("Save draft"), with header actions for variants (switch, new, rename, delete), a shared
    preview link, preview, publish (with a version summary and a stale-draft warning), schedule,
    cancel schedule, discard draft and unpublish; a pending-changes panel from the diff; a
    staged-blocks table (add, edit, delete, with change badges); and a versions list (view the
    snapshot, restore to draft, delete). Each block type has its own form (see below); a type
    with no fields, or one nobody registered, falls back to a JSON editor and can still be edited.
  - *Menus*: the same workflow, with a nested link editor (three levels) whose link kinds come
    from `NavigationLinkTypes`.
  - *Tags*, *Media* (single and bulk upload into `cms-media`, details read from the file, usage
    count, delete with a warning when a block still uses it) and *Preview links* (status, opens,
    show link, revoke).
  - The page URL the editor's Preview sends you to comes from the `PageUrlResolver` contract
    (default: the custom route or `/pages/{slug}`); the address of a shared link from the route
    named in `filagora-cms.preview.share_route`. Both are yours to provide.
  - *Reusable blocks*: list (type filter, pending-changes badge), create, an editor that works on
    the staged copy (slug changes apply at once), publish, discard, delete, and a versions list.
  - *Block forms*: each of the 19 generic types declares fields with a small DSL
    (`Field::text()`, `url()`, `textarea()`, `markdown()`, `select()`, `toggle()`, `number()`,
    `strings()`, `items()`, `media()`), passed as the fifth argument of `BlockType`. One
    definition drives the form, normalises what is saved, upgrades older stored shapes and
    derives the media paths (so usage tracking follows). A profile package registers its own
    types the same way.

## Still to come

Nothing planned for this package beyond release.

## Install

```bash
composer require filagora/filagora-cms
php artisan migrate
php artisan filagora:entitlements:sync
```

```php
->plugin(\Filagora\Cms\FilagoraCmsPlugin::make())
```

Schedule `filagora:cms:publish-due` every minute to publish scheduled pages, menus and blocks.
Implement `PageUrlResolver` if your pages are not served at `/pages/{slug}`. Publish the config
with `--tag=filagora-cms-config` and the editor text with `--tag=filagora-cms-lang`.

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
