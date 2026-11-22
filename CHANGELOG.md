# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.4.1] - 2026-11-22

### Fixed

- The Pages list fits a 1440px screen without zooming out: the slug, custom route and tags columns are hidden by default, and the editor can toggle any of the optional columns from the column picker.

## [0.4.0] - 2026-11-21

### Changed

- **Breaking:** a preview is carried by the address alone. `/page?preview=TOKEN` shows the drafts a preview link names, and the same address without it is always the live page; no session or cookie is used. The editors' Preview buttons now make (or reuse) a link to the **working draft** of the page or menu, valid for a day, and open its address with the token. `PreviewShares::open()` no longer takes a `PreviewSession` and starts no session; it counts the visit and returns the share. The "include everything in my current preview" option on Share preview link is gone, since there is no session selection to include.
- A link can name a menu or page as `true`, the working draft as it is now, as well as a pinned variant.
- `MarkPreviewResponsesUncacheable` now acts on a request with a token, and also sends `Referrer-Policy: no-referrer`.

### Added

- `CarryPreviewToken`, added to the `web` group (switch off with `filagora-cms.preview.carry_token`): while a request has a token, links to other pages of the site and redirects within it keep it, so a draft menu can be followed across the site. `filagora-cms.preview.carry_token_except` lists path prefixes to leave alone, and an anchor with `data-preview-skip` is never changed.
- `PreviewShares::workingDraft()`, `PreviewUrl::with()` and `PreviewUrl::has()`, and `PreviewSession::token()` and `expiresAt()`.

### Removed

- The session preview: `PreviewSession::addPage()`, `addNavigation()`, `remove()`, `clear()`, `startShare()`, `currentSelection()`, `isShared()`, `sharedUntil()`, `isFromUrl()`, `isViewingLive()`, the `SESSION_KEY` and `SHARE_SESSION_KEY` constants, and `?preview=off` (a request without the parameter is the live page).

## [0.3.8] - 2026-11-20

### Added

- A preview can be chosen by the address, for one request and with no session: `?preview=TOKEN` (a usable share's token) shows that share's pinned drafts, and `?preview=off` shows the live site even while a preview is on, so a preview and the original can be compared at the same URL in two tabs. An unknown, revoked or expired token is served live. A token in the address wins over an editor's own preview for that request, and starts no session and counts no open. `PreviewSession::isFromUrl()` and `isViewingLive()` let a banner say which. The parameter name is `filagora-cms.preview.query_parameter` (default `preview`).

### Changed

- `MarkPreviewResponsesUncacheable` also marks a `?preview=off` response uncacheable and noindex, and sends `Referrer-Policy: no-referrer` with any response it marks, so a token in the address is not passed on to other sites.

## [0.3.7] - 2026-11-20

### Added

- A menu can have a heading (`cms_navigations.title`, optional), edited on the menu form and staged in the draft like its links, so it is versioned, diffed on publish, restorable and previewable. `CmsContent::navigationTitle($name)` returns it, or null so a site can fall back to its own heading. Adds a migration (guarded, so a site that already has the column is unaffected) and updates the schema snapshot.
- `ResolvesPreviewedMenuTitles`, an optional contract beside `PreviewResolver`, for previews that serve a menu's staged heading. `PreviewResolver` itself is unchanged.

## [0.3.6] - 2026-11-14

### Changed

- The licence names Ian Wilson as the copyright holder. No code changes.

## [0.3.5] - 2026-11-13

### Changed

- The README's What it provides section has the same sub-sections as every other Filagora package (Overview, Tables, Commands, Events, Access, Using it). No code changes.

## [0.3.4] - 2026-11-13

### Changed

- The README follows the layout shared by every Filagora package (What it provides, Requirements, Install, Configuration, Extending, Testing, Local development, Licence), so the docs page built from it reads the same as the others. No code changes.

## [0.3.3] - 2026-10-28

### Added

- `CmsSearch::pages()` takes a `MatchingStrategy` (default `All`, unchanged), passed to the engine. Requires `filagora/filagora-search` `^0.1.1`.

## [0.3.2] - 2026-10-25

### Fixed

- `MarkPreviewResponsesUncacheable` was added to the `web` group on the router only, and the HTTP kernel overwrites the router's groups from its own whenever it syncs them, so in a real application the middleware was missing and previews could be cached and indexed. It is now appended through the kernel.

## [0.3.1] - 2026-10-16

### Fixed

- The database page search matched block text case-sensitively (MariaDB keeps JSON columns in a case-sensitive collation), so "browse" did not find a block saying "Browse". It now compares in lower case.

## [0.3.0] - 2026-10-16

### Added

- **Search** with `filagora/filagora-search` (suggested, not required): `CmsPageSearchIndex` (published pages with title, excerpt, SEO fields, slug and live block text, capped by `search.content_limit`; the publication time is a field so a scheduled page is left out until due), `CmsSearch::pages()` (live pages only, hits re-checked against the row), `CmsPageDatabaseSearch` (the same query from the tables, newest first) and `BlockText`. A saved or deleted live block keeps its page's document current.

## [0.2.2] - 2026-10-14

### Changed

- **`filagora-entitlements` is now optional.** The staff abilities, and which menu items a visitor sees, go through core's `AccessChecker`; entitlements answers it when installed, a host can bind its own. Gates come from core's `GateAbilities`, the screens use core's `GatedByAbility`, the module now requires only `core`, and `filagora-entitlements` moves from `require` to `suggest`. Without it the menu editor's "visible for" field is a free-text list of keys instead of an entitlement picker. Behaviour with entitlements installed is unchanged. Requires `filagora/filagora-core` `^0.2.1`.

## [0.2.1] - 2026-10-14

- `ScalarField::withFallback()` (also on `Field::text()` and the other text factories): wording a block saved before a field existed shows in the form, and optionally replaces a blank value on save. For site block types whose older rows lack newer text keys.
- `ScalarField::asInteger()`: a select whose choice is saved as a whole number (column counts).

## [0.2.0] - 2026-10-13

First release under the `filagora/filagora-cms` name (the unreleased `zcwilt/forumkit-cms` was never published).

- All editor text now lives in `resources/lang/en/ui.php` (publish the lang files to translate it); a test checks every referenced key exists.

- Typed block forms: a field DSL (`Filagora\Cms\BlockTypes\Fields\Field`) on `BlockType`, forms for all 19 generic types, JSON editor kept as the fallback for field-less or unregistered types.
- Reusable blocks screen (`CmsBlockResource`) working on the staged copy, with publish, discard, delete and versions.

- Filament plugin `ForumKitCmsPlugin` with screens for pages (draft editor, variants, share link, preview, publish, schedule, discard, unpublish, pending-changes panel, staged blocks, versions), menus (same workflow, nested link editor), tags, media (upload, bulk upload, usage, guarded delete) and preview links; navigation group "Content".
- `PageUrlResolver` contract (default: custom route or `/pages/{slug}`), `WorkflowLabels`, `MediaAssets` service, link-kind labels and references on `NavigationLinkTypes`, `CmsPreviewShare::url()` (route named in `forumkit-cms.preview.share_route`).
- `PreviewSession` falls back to the application's session store when the request has none (a Livewire call).

- Preview session (`PreviewSession`, the `PreviewResolver` implementation), shared preview links (`PreviewShares`: create, open, revoke, landing page) and the `MarkPreviewResponsesUncacheable` middleware (added to the `web` group unless switched off).
- Abilities `forumkit.cms.manage` and `forumkit.cms.preview`, mapped in `forumkit-cms.gates` and declared as entitlements.

- Menu staged-edit workflow (`NavigationDrafts`): working draft, named variants, discard, stale check, publish with a version, restore, schedule, `itemsForVariant`; `NavigationDraftDiff`; event `CmsNavigationPublished`. `forumkit:cms:publish-due` now publishes due menus too.

- Page staged-edit workflow (`PageDrafts`): working draft, named variants (create, switch, rename, delete), discard, stale check, publish with a version, restore a version to the draft, scheduled publishing, in-memory preview of the draft or any variant. `PageVersioning` snapshots; `PageDraftDiff` describes what publishing would change.
- Reusable block workflow (`BlockDrafts`): staged copy, pending-change check, publish with a version, restore.
- Events `CmsPagePublished` and `CmsBlockPublished`; `PageDrafts::isPublishing()` for listeners that react to each saved row.
- Command `forumkit:cms:publish-due`.
- `DraftOperationFailed` (carries the HTTP status a frontend would answer with).

- `CmsContent` readers: published pages, by slug and by custom route (preview token for drafts), menus with a cache, reusable blocks, media lookups.
- `NavigationItems` and `NavigationLinkTypes`: visitor-safe menu items and site-registered link kinds.
- `BlockTypeRegistry` with nineteen generic block types, aliases, unknown-type handling and media paths; `MediaAssetUsage`.
- `PreviewResolver` contract (default `NoPreview`) so the readers can serve previews.
- Config `navigation_cache_seconds`.

- The fourteen CMS tables, matching the shape of the site they were extracted from, with a schema snapshot for `forumkit:adopt`.
- Models, the `CmsPageStatus` enum and a model-resolution helper (`forumkit-cms.models`, overridable under `forumkit.models`).
