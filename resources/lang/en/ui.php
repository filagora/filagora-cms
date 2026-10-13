<?php

declare(strict_types=1);

return [
    'common' => [
        'version_is_live' => 'Version :number is now live.',
        'version_is_draft' => 'Version :number is now your draft. Review it, then publish.',
        'media_still_used' => 'This media asset is still used by :count CMS block. Tick the confirmation box to delete it anyway.|This media asset is still used by :count CMS blocks. Tick the confirmation box to delete it anyway.',
    ],

    'draft_variant_actions' => [
        'cannot_change_variants' => 'Cannot change variants',
        'switch_variant' => 'Switch variant',
        'the_current_draft_is_kept_as' => 'The current draft is kept as a variant and the one you pick becomes the working draft.',
        'variant' => 'Variant',
        'new_variant' => 'New variant',
        'the_current_draft_is_kept_as_2' => 'The current draft is kept as a variant and the new one becomes the working draft.',
        'name' => 'Name',
        'start_from_the_live_version_instead' => 'Start from the live version instead of copying the current draft',
        'rename_this_variant' => 'Rename this variant',
        'delete_a_variant' => 'Delete a variant',
        'permanently_deletes_the_chosen_variant_the' => 'Permanently deletes the chosen variant. The working draft is not affected.',
    ],

    'share_preview_link_action' => [
        'share_preview_link' => 'Share preview link',
        'share_a_preview_link' => 'Share a preview link',
        'anyone_with_the_link_can_see' => 'Anyone with the link can see this draft, without logging in, until it expires or you revoke it from Preview links. Visitors still see the published site.',
        'create_link' => 'Create link',
        'draft_variant_to_show' => 'Draft variant to show',
        'label_only_you_see_it' => 'Label (only you see it)',
        'expires_after' => 'Expires after',
        'also_include_everything_in_my_current' => 'Also include everything in my current preview',
        'adds_the_menus_and_pages_in' => 'Adds the menus and pages in your preview, each at its current working draft.',
        'cannot_create_link' => 'Cannot create link',
        'preview_link_created' => 'Preview link created',
    ],

    'block_form' => [
        'type' => 'Type',
        'name' => 'Name',
        'display_order' => 'Display order',
        'lower_values_appear_first' => 'Lower values appear first.',
        'content_json' => 'Content (JSON)',
        'the_blocks_content_as_json_leave' => 'The block\'s content as JSON. Leave empty for none.',
        'used_when_no_library_image_is' => 'Used when no library image is chosen.',
        'alt_text' => 'Alt text',
        'if_left_blank_the_chosen_images' => 'If left blank, the chosen image\'s alt text is used.',
    ],

    'cms_markdown_editor' => [
        'markdown_is_supported_inline_html_in' => 'Markdown is supported. Inline HTML in existing content keeps working.',
    ],

    'cms_block_resource' => [
        'cms' => 'cms',
    ],

    'edit_cms_block' => [
        'save_draft' => 'Save draft',
        'publish' => 'Publish',
        'publish_block' => 'Publish block',
        'this_makes_the_draft_live_everywhere' => 'This makes the draft live everywhere this block is rendered and records a version.',
        'version_summary' => 'Version summary',
        'cannot_publish' => 'Cannot publish',
        'published' => 'Published',
        'discard_draft' => 'Discard draft',
        'throws_away_the_unpublished_changes_to' => 'Throws away the unpublished changes to this block.',
        'draft_discarded' => 'Draft discarded',
    ],

    'versions_relation_manager' => [
        'version' => 'Version',
        'summary' => 'Summary',
        'no_summary' => 'No summary',
        'created_by' => 'Created by',
        'system' => 'System',
        'created' => 'Created',
        'view_snapshot' => 'View snapshot',
        'restore_to_draft' => 'Restore to draft',
        'loads_this_version_into_the_draft' => 'Loads this version into the draft (its content). Nothing goes live until you publish.',
        'version_loaded_into_draft' => 'Version loaded into draft',
        'this_permanently_removes_this_version_snapshot' => 'This permanently removes this version snapshot. The live block is not changed.',
        'this_permanently_removes_the_selected_version' => 'This permanently removes the selected version snapshots. The live block is not changed.',
        'loads_this_version_into_the_draft_2' => 'Loads this version into the draft (its links). Nothing goes live until you publish.',
        'this_permanently_removes_this_version_snapshot_2' => 'This permanently removes this version snapshot. The live menu is not changed.',
        'this_permanently_removes_the_selected_version_2' => 'This permanently removes the selected version snapshots. The live menu is not changed.',
        'loads_this_version_into_the_draft_3' => 'Loads this version into the draft (page fields, blocks and tags). Nothing goes live until you publish.',
        'this_permanently_removes_this_version_snapshot_3' => 'This permanently removes this version snapshot. The live page is not changed.',
        'this_permanently_removes_the_selected_version_3' => 'This permanently removes the selected version snapshots. The live page is not changed.',
    ],

    'cms_block_form' => [
        'block' => 'Block',
        'a_reusable_block_is_rendered_wherever' => 'A reusable block is rendered wherever your site asks for its slug. Edits are saved as a draft until you publish.',
        'slug' => 'Slug',
        'what_your_site_looks_the_block' => 'What your site looks the block up by. A change applies at once; the rest is staged.',
        'published' => 'Published',
        'a_block_that_is_not_published' => 'A block that is not published is not served to visitors.',
        'content' => 'Content',
    ],

    'cms_blocks_table' => [
        'unnamed_block' => 'Unnamed block',
        'published' => 'Published',
        'draft' => 'Draft',
    ],

    'cms_media_asset_resource' => [
        'cms' => 'cms',
    ],

    'edit_cms_media_asset' => [
        'open_file' => 'Open file',
        'i_understand_this_asset_may_still' => 'I understand this asset may still be referenced by CMS blocks',
        'required_only_when_the_asset_is' => 'Required only when the asset is still in use.',
        'confirm_deletion_of_inuse_asset' => 'Confirm deletion of in-use asset',
    ],

    'list_cms_media_assets' => [
        'bulk_upload' => 'Bulk upload',
        'bulk_upload_media_assets' => 'Bulk upload media assets',
        'upload_several_files_and_create_one' => 'Upload several files and create one media asset record for each.',
        'folder' => 'Folder',
        'existing_folder' => 'Existing folder',
        'cmsmedia_root' => 'cms-media (root)',
        'new_folder' => 'New folder',
        'heroeshomepage' => 'heroes/homepage',
        'files' => 'Files',
    ],

    'cms_media_asset_form' => [
        'file' => 'File',
        'folder' => 'Folder',
        'existing_folder' => 'Existing folder',
        'new_folder' => 'New folder',
        'heroeshomepage' => 'heroes/homepage',
        'alt_text' => 'Alt text',
        'describe_the_image_for_people_who' => 'Describe the image for people who cannot see it.',
    ],

    'cms_media_assets_table' => [
        'preview' => 'Preview',
        'ext' => 'Ext',
        'no_alt_text' => 'No alt text',
        'used_in' => 'Used in',
        'path' => 'Path',
        'kind' => 'Kind',
        'open' => 'Open',
    ],

    'cms_navigation_resource' => [
        'cms' => 'cms',
    ],

    'edit_cms_navigation' => [
        'save_draft' => 'Save draft',
        'preview' => 'Preview',
        'previewing_this_menu' => 'Previewing this menu',
        'browse_the_site_this_menu_shows' => 'Browse the site: this menu shows its draft until you leave the preview.',
        'publish' => 'Publish',
        'publish_menu_changes' => 'Publish menu changes',
        'version_summary' => 'Version summary',
        'cannot_publish' => 'Cannot publish',
        'published' => 'Published',
        'publish_at' => 'Publish at',
        'publish_scheduled' => 'Publish scheduled',
        'cancel_schedule' => 'Cancel schedule',
        'discard_draft' => 'Discard draft',
        'throws_away_all_unpublished_changes_to' => 'Throws away all unpublished changes to this menu.',
        'draft_discarded' => 'Draft discarded',
    ],

    'cms_navigation_form' => [
        'menu' => 'Menu',
        'one_record_per_menu_location_add' => 'One record per menu location. Add links in the order they should appear.',
        'menu_key' => 'Menu key',
        'the_name_your_site_looks_the' => 'The name your site looks the menu up by, for example header or footer.',
        'links' => 'Links',
        'use_child_links_to_build_a' => 'Use child links to build a dropdown; a dropdown link can in turn hold its own links as a group (two levels below a top-level link). Footer menus should usually stay flat.',
        'link_label' => 'Link label',
        'about_us' => 'About us',
        'link_type' => 'Link type',
        'url' => 'URL',
        'forums_or_httpsexamplecom' => '/forums or https://example.com',
        'relative_urls_stay_onsite_full_urls' => 'Relative URLs stay on-site; full URLs can link externally. For a heading that only groups links, use #.',
        'reference' => 'Reference',
        'resolved_by_the_application_from_a' => 'Resolved by the application from a fixed list, at render time.',
        'target' => 'Target',
        'open_in_a_new_tab_only' => 'Open in a new tab only when that improves the experience, usually for external links.',
        'visible_for_entitlements' => 'Visible for entitlements',
        'leave_empty_to_show_this_link' => 'Leave empty to show this link to everyone. When set, it is shown to anyone holding any selected entitlement.',
    ],

    'cms_navigations_table' => [
        'menu' => 'Menu',
        'links' => 'Links',
        'status' => 'Status',
    ],

    'cms_page_resource' => [
        'cms' => 'cms',
    ],

    'edit_cms_page' => [
        'save_draft' => 'Save draft',
        'preview' => 'Preview',
        'publish' => 'Publish',
        'publish_changes' => 'Publish changes',
        'version_summary' => 'Version summary',
        'cannot_publish' => 'Cannot publish',
        'published' => 'Published',
        'publish_at' => 'Publish at',
        'publish_scheduled' => 'Publish scheduled',
        'cancel_schedule' => 'Cancel schedule',
        'discard_draft' => 'Discard draft',
        'throws_away_all_unpublished_changes_to' => 'Throws away all unpublished changes to this page and its blocks.',
        'draft_discarded' => 'Draft discarded',
        'unpublish' => 'Unpublish',
        'immediately_takes_the_page_offline_content' => 'Immediately takes the page offline. Content and versions are kept.',
    ],

    'blocks_relation_manager' => [
        'blocks_are_edited_in_the_draft' => 'Blocks are edited in the draft; they only appear on the live page when you publish. Lower display order values appear first.',
        'order' => 'Order',
        'changes' => 'Changes',
        'type' => 'Type',
        'name' => 'Name',
        'unnamed_block' => 'Unnamed block',
        'updated' => 'Updated',
        'add_block' => 'Add block',
        'add_page_block' => 'Add page block',
    ],

    'cms_page_form' => [
        'page_content' => 'Page content',
        'set_the_page_title_url_and' => 'Set the page title, URL, and summary content first.',
        'page_title' => 'Page title',
        'slug' => 'Slug',
        'used_for_the_default_page_route' => 'Used for the default page route at /pages/{slug}.',
        'custom_route' => 'Custom route',
        'mycustompath' => '/my-custom-path',
        'use_this_when_the_page_should' => 'Use this when the page should live at a custom path like /privacy instead of /pages/{slug}. A leading slash is added for you.',
        'excerpt' => 'Excerpt',
        'optional_short_summary_used_for_listings' => 'Optional short summary used for listings, previews, or search context.',
        'tags' => 'Tags',
        'publishing' => 'Publishing',
        'changes_are_saved_to_a_draft' => 'Changes are saved to a draft. Use Preview to check them, then Publish to make them live.',
        'status' => 'Status',
        'seo' => 'SEO',
        'optional_fields_for_search_engines_and' => 'Optional fields for search engines and social previews.',
        'seo_title' => 'SEO title',
        'seo_description' => 'SEO description',
    ],

    'cms_pages_table' => [
        'state' => 'State',
    ],

    'cms_preview_share_resource' => [
        'cms' => 'cms',
        'no_label' => 'No label',
        'shows' => 'Shows',
        'opens' => 'Opens',
        'never' => 'Never',
        'created_by' => 'Created by',
        'show_link' => 'Show link',
        'preview_link' => 'Preview link',
        'revoke' => 'Revoke',
        'link_revoked' => 'Link revoked',
    ],

    'cms_tag_resource' => [
        'cms' => 'cms',
    ],

    'cms_tags_table' => [
        'pages' => 'Pages',
    ],
];
