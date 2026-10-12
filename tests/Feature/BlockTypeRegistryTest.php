<?php

declare(strict_types=1);

use ForumKit\Cms\BlockTypes\BlockType;
use ForumKit\Cms\BlockTypes\BlockTypeRegistry;
use ForumKit\Cms\BlockTypes\GenericBlockTypes;

enum TestBlockKind: string
{
    case Hero = 'hero';
}

it('ships the nineteen generic types and none of the site-specific ones', function () {
    $registry = app(BlockTypeRegistry::class);

    expect(array_keys($registry->all()))->toBe([
        'raw_html', 'hero', 'image', 'gallery', 'card_grid', 'feature_list', 'embed', 'divider', 'cta_band', 'stat_strip',
        'link_grid', 'logo_grid', 'accordion_list', 'icon_callout', 'icon_card_grid', 'split_info_panel', 'checklist_callout',
        'comparison_matrix', 'comparison_panel',
    ])->and(count(GenericBlockTypes::all()))->toBe(19)
        ->and($registry->has('donation_hero'))->toBeFalse()
        ->and($registry->options()['cta_band'])->toBe('Call-to-action band');
});

it('normalises a stored type: trimmed, lowercase, enum unwrapped, aliases replaced', function () {
    $registry = new BlockTypeRegistry;
    $registry->register(new BlockType('promo_hero', 'Promo hero'));
    $registry->alias('Download_Hero', 'promo_hero');

    expect($registry->normalize('  HERO '))->toBe('hero')
        ->and($registry->normalize(TestBlockKind::Hero))->toBe('hero')
        ->and($registry->normalize('download_hero'))->toBe('promo_hero')
        ->and($registry->normalize(' Download_Hero '))->toBe('promo_hero')
        ->and($registry->normalize(''))->toBeNull()
        ->and($registry->normalize('   '))->toBeNull()
        ->and($registry->normalize(null))->toBeNull()
        ->and($registry->normalize(12))->toBeNull()
        ->and($registry->is('download_hero', 'promo_hero'))->toBeTrue()
        ->and($registry->is('hero', 'image'))->toBeFalse()
        ->and($registry->has('download_hero'))->toBeTrue()
        ->and($registry->get('download_hero')?->label)->toBe('Promo hero');
});

it('lets a type it has never heard of load, labelled as unknown, never throwing', function () {
    $registry = app(BlockTypeRegistry::class);

    expect($registry->normalize('Some_Site_Type'))->toBe('some_site_type')
        ->and($registry->has('some_site_type'))->toBeFalse()
        ->and($registry->get('some_site_type'))->toBeNull()
        ->and($registry->label('some_site_type'))->toBe('Unknown type (Some Site Type)')
        ->and($registry->label(null))->toBe('Unknown type')
        ->and($registry->label('hero'))->toBe('Hero');
});

it('finds an asset id at the declared paths, including inside lists', function () {
    $registry = app(BlockTypeRegistry::class);

    expect($registry->usesAsset('hero', ['background_image_asset_id' => 5], 5))->toBeTrue()
        ->and($registry->usesAsset('hero', ['background_image_asset_id' => '5'], 5))->toBeTrue()
        ->and($registry->usesAsset('hero', ['background_image_asset_id' => 6], 5))->toBeFalse()
        ->and($registry->usesAsset('image', ['asset_id' => 5], 5))->toBeTrue()
        ->and($registry->usesAsset('gallery', ['items' => [['asset_id' => 1], ['asset_id' => 5]]], 5))->toBeTrue()
        ->and($registry->usesAsset('gallery', ['items' => [['asset_id' => 1], 'junk']], 5))->toBeFalse()
        ->and($registry->usesAsset('card_grid', ['cards' => [['image_asset_id' => 5]]], 5))->toBeTrue()
        ->and($registry->usesAsset('card_grid', ['cards' => 'not a list'], 5))->toBeFalse()
        ->and($registry->usesAsset('comparison_panel', ['image_asset_id' => 5], 5))->toBeTrue()
        ->and($registry->usesAsset('divider', ['asset_id' => 5], 5))->toBeFalse()
        ->and($registry->usesAsset('unknown_type', ['asset_id' => 5], 5))->toBeFalse()
        ->and($registry->usesAsset('image', [], 5))->toBeFalse();
});

it('lets a site register its own types and media paths', function () {
    $registry = app(BlockTypeRegistry::class);
    $registry->register(new BlockType('donation_hero', 'Donation hero', ['logo.asset_id']));

    expect($registry->has('donation_hero'))->toBeTrue()
        ->and($registry->usesAsset('donation_hero', ['logo' => ['asset_id' => 9]], 9))->toBeTrue();
});
