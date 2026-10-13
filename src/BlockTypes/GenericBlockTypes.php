<?php

declare(strict_types=1);

namespace Filagora\Cms\BlockTypes;

/**
 * The block types any site can use. Site-specific types and old aliases are registered by the
 * site's own profile package.
 */
final class GenericBlockTypes
{
    /**
     * @return list<BlockType>
     */
    public static function all(): array
    {
        return [
            new BlockType('raw_html', 'Raw HTML'),
            new BlockType('hero', 'Hero', ['background_image_asset_id']),
            new BlockType('image', 'Image', ['asset_id']),
            new BlockType('gallery', 'Gallery', ['items.*.asset_id']),
            new BlockType('card_grid', 'Card grid', ['cards.*.image_asset_id']),
            new BlockType('feature_list', 'Feature list'),
            new BlockType('embed', 'Embed'),
            new BlockType('divider', 'Divider'),
            new BlockType('cta_band', 'Call-to-action band'),
            new BlockType('stat_strip', 'Stat strip'),
            new BlockType('link_grid', 'Link grid'),
            new BlockType('logo_grid', 'Logo grid'),
            new BlockType('accordion_list', 'Accordion list'),
            new BlockType('icon_callout', 'Icon callout'),
            new BlockType('icon_card_grid', 'Icon card grid'),
            new BlockType('split_info_panel', 'Split info panel'),
            new BlockType('checklist_callout', 'Checklist callout'),
            new BlockType('comparison_matrix', 'Comparison matrix'),
            new BlockType('comparison_panel', 'Comparison panel', ['image_asset_id']),
        ];
    }
}
