<?php

declare(strict_types=1);

namespace Filagora\Cms\BlockTypes;

use Filagora\Cms\BlockTypes\Fields\Field;

/**
 * The block types any site can use, each with the fields of its form. The stored JSON keys are
 * the ones the site this package was extracted from already uses, so existing blocks load as they
 * are. Site-specific types and old aliases are registered by the site's own profile package.
 */
final class GenericBlockTypes
{
    /**
     * @return list<BlockType>
     */
    public static function all(): array
    {
        $cta = static fn (string $prefix = 'cta'): array => [
            Field::text($prefix.'_label', 'Button label'),
            Field::url($prefix.'_url', 'Button URL'),
        ];

        return [
            new BlockType('raw_html', 'Raw HTML', fields: [
                Field::textarea('html', 'HTML', 'Rendered as it is, so only trusted editors should have access.', 12),
            ]),

            new BlockType('hero', 'Hero', fields: [
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                ...$cta(),
                Field::media('Background image', 'background_image_asset_id', 'background_image_url', help: 'Pick an uploaded image, or paste a URL.'),
            ]),

            new BlockType('image', 'Image', fields: [
                Field::media('Image', 'asset_id', 'url', 'alt', 'If the alt text is left blank, the chosen asset\'s alt text is used.'),
                Field::markdown('caption', 'Caption'),
            ]),

            new BlockType('gallery', 'Gallery', fields: [
                Field::items('items', 'Images', [
                    Field::media('Image', 'asset_id', 'url', 'alt'),
                    Field::markdown('caption', 'Caption', rows: 2),
                ], help: 'Several images shown together. One image per row.'),
            ]),

            new BlockType('card_grid', 'Card grid', fields: [
                Field::items('cards', 'Cards', [
                    Field::text('title', 'Title'),
                    Field::url('url', 'Link URL'),
                    Field::media('Card image', 'image_asset_id', 'image_url'),
                    Field::markdown('body', 'Body'),
                ], titleKey: 'title', help: 'A grid of teaser cards, each with its own title, copy, image and link.'),
            ]),

            new BlockType('feature_list', 'Feature list', fields: [
                Field::markdown('intro', 'Intro'),
                Field::strings('items', 'Features', 'A short list of benefits or highlights.', itemLabel: 'Feature'),
            ]),

            new BlockType('embed', 'Embed', fields: [
                Field::url('url', 'Embed URL', 'For an embed the page can build from a URL alone.'),
                Field::textarea('html', 'Embed HTML', 'Optional raw iframe or script if a URL alone is not enough.', 8),
            ]),

            new BlockType('divider', 'Divider', fields: [
                Field::select('style', 'Style', ['solid' => 'Solid', 'dashed' => 'Dashed', 'spacer' => 'Spacer'], 'solid', 'Separates sections, or adds vertical space.'),
            ]),

            new BlockType('cta_band', 'Call-to-action band', fields: [
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::select('theme', 'Theme', ['dark' => 'Dark centered band', 'warm' => 'Warm gradient band'], 'dark'),
                Field::text('primary_label', 'Primary button label'),
                Field::url('primary_url', 'Primary button URL'),
                Field::text('secondary_label', 'Secondary button label'),
                Field::url('secondary_url', 'Secondary button URL'),
            ]),

            new BlockType('stat_strip', 'Stat strip', fields: [
                Field::text('title', 'Title'),
                Field::items('items', 'Stats', [
                    Field::text('headline', 'Headline'),
                    Field::text('icon_class', 'Icon class'),
                    Field::url('url', 'Link URL'),
                    Field::toggle('emphasize', 'Emphasise'),
                ], titleKey: 'headline', legacyScalarKey: 'headline'),
            ]),

            new BlockType('link_grid', 'Link grid', fields: [
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::items('items', 'Links', [
                    Field::text('title', 'Title'),
                    Field::url('url', 'URL'),
                    Field::markdown('body', 'Body'),
                ], titleKey: 'title'),
            ]),

            new BlockType('logo_grid', 'Logo grid', fields: [
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::items('items', 'Logos', [
                    Field::text('name', 'Name'),
                    Field::url('url', 'Link URL'),
                    Field::url('image_url', 'Logo image URL'),
                ], titleKey: 'name'),
            ]),

            new BlockType('accordion_list', 'Accordion list', fields: [
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::items('items', 'Entries', [
                    Field::text('title', 'Title'),
                    Field::strings('points', 'Points', itemLabel: 'Point'),
                ], titleKey: 'title'),
            ]),

            new BlockType('icon_callout', 'Icon callout', fields: [
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                ...$cta(),
                Field::text('icon_class', 'Icon class', 'A CSS class for the icon, for example an icon font class.'),
            ]),

            new BlockType('icon_card_grid', 'Icon card grid', fields: [
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::items('items', 'Cards', [
                    Field::text('title', 'Title'),
                    Field::text('icon_class', 'Icon class'),
                    Field::markdown('body', 'Body'),
                ], titleKey: 'title'),
            ]),

            new BlockType('split_info_panel', 'Split info panel', fields: [
                Field::text('title', 'Title'),
                Field::text('subtitle', 'Subtitle'),
                Field::text('body_title', 'Body heading'),
                Field::strings('paragraphs', 'Paragraphs', markdown: true, itemLabel: 'Paragraph'),
                ...$cta(),
                Field::text('specs_title', 'Specs heading'),
                Field::items('specs', 'Specs', [
                    Field::text('icon_class', 'Icon class'),
                    Field::text('label', 'Label'),
                    Field::text('value', 'Value'),
                ], titleKey: 'label'),
            ]),

            new BlockType('checklist_callout', 'Checklist callout', fields: [
                Field::text('title', 'Title'),
                Field::text('subtitle', 'Subtitle'),
                Field::items('items', 'Checklist', [
                    Field::text('label', 'Lead label'),
                    Field::markdown('body', 'Body', rows: 2),
                ], titleKey: 'label'),
                Field::text('callout_title', 'Callout title'),
                Field::markdown('callout_body', 'Callout body'),
                Field::text('callout_link_label', 'Callout link label'),
                Field::url('callout_link_url', 'Callout link URL'),
            ]),

            new BlockType('comparison_matrix', 'Comparison matrix', fields: [
                Field::text('eyebrow', 'Eyebrow'),
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::items('headers', 'Column headers', [
                    Field::text('label', 'Header label'),
                    Field::toggle('emphasize', 'Highlight column'),
                ], titleKey: 'label', legacyScalarKey: 'label'),
                Field::items('rows', 'Rows', [
                    Field::text('feature', 'Feature label'),
                    Field::strings('values', 'Values', itemLabel: 'Value'),
                    Field::number('highlight_column', 'Highlight column', 'Column number to highlight in this row (1 is the first). Leave blank for none.'),
                ], titleKey: 'feature'),
            ]),

            new BlockType('comparison_panel', 'Comparison panel', fields: [
                Field::text('eyebrow', 'Eyebrow'),
                Field::text('title', 'Title'),
                Field::strings('paragraphs', 'Paragraphs', markdown: true, itemLabel: 'Paragraph'),
                Field::strings('checklist_items', 'Checklist', itemLabel: 'Checklist item'),
                Field::select('section_tone', 'Section tone', ['cream' => 'Cream', 'gray' => 'Gray'], 'cream'),
                Field::select('image_position', 'Aside position', ['end' => 'Right side', 'start' => 'Left side'], 'end'),
                Field::select('aside_type', 'Aside', ['card' => 'Summary card', 'image' => 'Image'], 'card'),
                Field::text('aside_title', 'Card title')->showWhen(['aside_type' => 'card']),
                Field::select('aside_theme', 'Card theme', ['dark' => 'Dark', 'light' => 'Light'], 'dark')->showWhen(['aside_type' => 'card']),
                Field::items('aside_rows', 'Card rows', [
                    Field::text('icon_class', 'Icon class'),
                    Field::text('label', 'Label'),
                    Field::text('value', 'Value'),
                ], titleKey: 'label')->showWhen(['aside_type' => 'card']),
                Field::media('Image', 'image_asset_id', 'image_url', 'image_alt')->showWhen(['aside_type' => 'image']),
            ]),
        ];
    }
}
