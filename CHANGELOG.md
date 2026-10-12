# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

- `CmsContent` readers: published pages, by slug and by custom route (preview token for drafts), menus with a cache, reusable blocks, media lookups.
- `NavigationItems` and `NavigationLinkTypes`: visitor-safe menu items and site-registered link kinds.
- `BlockTypeRegistry` with nineteen generic block types, aliases, unknown-type handling and media paths; `MediaAssetUsage`.
- `PreviewResolver` contract (default `NoPreview`) so the readers can serve previews.
- Config `navigation_cache_seconds`.

- The fourteen CMS tables, matching the shape of the site they were extracted from, with a schema snapshot for `forumkit:adopt`.
- Models, the `CmsPageStatus` enum and a model-resolution helper (`forumkit-cms.models`, overridable under `forumkit.models`).
