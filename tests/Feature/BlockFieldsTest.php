<?php

declare(strict_types=1);

use Filagora\Cms\BlockTypes\BlockType;
use Filagora\Cms\BlockTypes\BlockTypeRegistry;
use Filagora\Cms\BlockTypes\Fields\Field;
use Filagora\Cms\Models\CmsMediaAsset;
use Illuminate\Support\Facades\Storage;

function blockType(string $key): BlockType
{
    return app(BlockTypeRegistry::class)->get($key) ?? throw new RuntimeException("No block type {$key}");
}

function libraryImage(array $attributes = []): CmsMediaAsset
{
    Storage::fake('public');
    $path = 'cms-media/'.uniqid().'.png';
    Storage::disk('public')->put($path, 'x');

    return CmsMediaAsset::create(array_merge(['disk' => 'public', 'path' => $path, 'filename' => 'a.png', 'mime_type' => 'image/png'], $attributes))->refresh();
}

it('gives every generic type a form', function () {
    foreach (app(BlockTypeRegistry::class)->all() as $key => $type) {
        expect($type->hasForm())->toBeTrue($key);
    }
});

it('stores a type with no value as empty strings and lists, never missing keys', function (string $key, array $expected) {
    expect(blockType($key)->normalize([]))->toBe($expected);
})->with([
    'raw_html' => ['raw_html', ['html' => '']],
    'divider' => ['divider', ['style' => 'solid']],
    'embed' => ['embed', ['url' => '', 'html' => '']],
    'feature_list' => ['feature_list', ['intro' => '', 'items' => []]],
    'cta_band' => ['cta_band', ['title' => '', 'body' => '', 'theme' => 'dark', 'primary_label' => '', 'primary_url' => '', 'secondary_label' => '', 'secondary_url' => '']],
    'gallery' => ['gallery', ['items' => []]],
    'hero' => ['hero', ['title' => '', 'body' => '', 'cta_label' => '', 'cta_url' => '', 'background_image_asset_id' => null, 'background_image_url' => '']],
    'image' => ['image', ['asset_id' => null, 'url' => '', 'alt' => '', 'caption' => '']],
]);

it('trims text and keeps only the type\'s own keys', function () {
    $data = blockType('hero')->normalize(['title' => '  Hello  ', 'body' => "\nBody\n", 'cta_label' => ' Go ', 'cta_url' => ' /go ', 'nonsense' => 'dropped', 'background_image_url' => ' https://example.test/a.jpg ']);

    expect($data)->toBe([
        'title' => 'Hello', 'body' => 'Body', 'cta_label' => 'Go', 'cta_url' => '/go',
        'background_image_asset_id' => null, 'background_image_url' => 'https://example.test/a.jpg',
    ]);
});

it('resolves a chosen image to its URL and falls back to the typed URL and the asset\'s alt text', function () {
    $asset = libraryImage(['alt_text' => 'From the library']);

    $chosen = blockType('image')->normalize(['asset_id' => (string) $asset->id, 'url' => 'https://ignored.test/x.png', 'alt' => '', 'caption' => ' c ']);
    $typed = blockType('image')->normalize(['asset_id' => '', 'url' => ' https://example.test/x.png ', 'alt' => ' Mine ']);
    $missing = blockType('image')->normalize(['asset_id' => '99999', 'url' => 'https://example.test/y.png']);

    expect($chosen)->toBe(['asset_id' => $asset->id, 'url' => $asset->publicUrl(), 'alt' => 'From the library', 'caption' => 'c'])
        ->and($typed)->toBe(['asset_id' => null, 'url' => 'https://example.test/x.png', 'alt' => 'Mine', 'caption' => ''])
        ->and($missing['asset_id'])->toBeNull()
        ->and($missing['url'])->toBe('https://example.test/y.png');
});

it('resolves the images inside a list too', function () {
    $asset = libraryImage(['alt_text' => 'Lib alt']);

    $gallery = blockType('gallery')->normalize(['items' => [
        ['asset_id' => $asset->id, 'url' => '', 'alt' => '', 'caption' => 'one'],
        ['asset_id' => null, 'url' => 'https://example.test/b.png', 'alt' => 'Typed', 'caption' => ''],
        'not an item',
    ]]);
    $cards = blockType('card_grid')->normalize(['cards' => [['title' => ' T ', 'url' => '/t', 'image_asset_id' => $asset->id, 'image_url' => '', 'body' => 'b']]]);

    expect($gallery['items'])->toHaveCount(2)
        ->and($gallery['items'][0])->toMatchArray(['asset_id' => $asset->id, 'url' => $asset->publicUrl(), 'alt' => 'Lib alt', 'caption' => 'one'])
        ->and($gallery['items'][1])->toMatchArray(['asset_id' => null, 'url' => 'https://example.test/b.png', 'alt' => 'Typed'])
        ->and($cards['cards'][0])->toMatchArray(['title' => 'T', 'url' => '/t', 'image_asset_id' => $asset->id, 'image_url' => $asset->publicUrl(), 'body' => 'b']);
});

it('keeps the keys an item carries that the group does not know', function () {
    $out = blockType('link_grid')->normalize(['title' => 'T', 'body' => '', 'items' => [['title' => ' A ', 'url' => '/a', 'body' => '', 'extra' => 'kept']]]);

    expect($out['items'][0])->toBe(['title' => 'A', 'url' => '/a', 'body' => '', 'extra' => 'kept']);
});

it('cleans string lists: trimmed, blanks dropped, old {text} entries unwrapped', function () {
    $out = blockType('feature_list')->normalize(['intro' => '', 'items' => ['  One ', '', ['text' => 'Two'], '   ', 'Three']]);

    expect($out['items'])->toBe(['One', 'Two', 'Three']);
});

it('stores a toggle as a boolean and a number as a positive integer or null', function () {
    $matrix = blockType('comparison_matrix')->normalize([
        'headers' => [['label' => 'A', 'emphasize' => '1'], ['label' => 'B']],
        'rows' => [
            ['feature' => 'F1', 'values' => ['x', 'y'], 'highlight_column' => '2'],
            ['feature' => 'F2', 'values' => [], 'highlight_column' => '0'],
            ['feature' => 'F3', 'values' => ['z'], 'highlight_column' => ''],
        ],
    ]);

    expect($matrix['headers'])->toBe([['label' => 'A', 'emphasize' => true], ['label' => 'B', 'emphasize' => false]])
        ->and(array_column($matrix['rows'], 'highlight_column'))->toBe([2, null, null])
        ->and($matrix['rows'][0]['values'])->toBe(['x', 'y']);
});

it('upgrades old stored shapes when a block is loaded into the form', function () {
    $form = blockType('comparison_matrix')->hydrate([
        'headers' => ['Plain header', ['label' => 'Rich', 'emphasize' => true]],
        'rows' => [['feature' => 'F', 'values' => [['value' => 'cell'], 'plain'], 'highlight_column' => 3]],
    ]);
    $stats = blockType('stat_strip')->hydrate(['title' => 'T', 'items' => ['Just text', ['headline' => 'Head', 'icon_class' => 'i', 'url' => '/u', 'emphasize' => 1]]]);

    expect($form['headers'])->toBe([['label' => 'Plain header', 'emphasize' => false], ['label' => 'Rich', 'emphasize' => true]])
        ->and($form['rows'][0]['values'])->toBe(['cell', 'plain'])
        ->and($form['rows'][0]['highlight_column'])->toBe(3)
        ->and($stats['items'][0])->toBe(['headline' => 'Just text'] + ['icon_class' => '', 'url' => '', 'emphasize' => false])
        ->and($stats['items'][1]['emphasize'])->toBeTrue();
});

it('fills in every field when loading a block that lacks some, with the select defaults', function () {
    $form = blockType('comparison_panel')->hydrate(['title' => 'T']);

    expect($form)->toMatchArray(['title' => 'T', 'eyebrow' => '', 'section_tone' => 'cream', 'aside_type' => 'card', 'aside_theme' => 'dark', 'image_position' => 'end', 'paragraphs' => [], 'aside_rows' => []])
        ->and(blockType('divider')->hydrate(null))->toBe(['style' => 'solid']);
});

it('round-trips every generic type without changing a saved block', function (string $key, array $stored) {
    $type = blockType($key);

    expect($type->normalize($type->hydrate($stored)))->toBe($stored);
})->with([
    'hero' => ['hero', ['title' => 'T', 'body' => 'B', 'cta_label' => 'Go', 'cta_url' => '/go', 'background_image_asset_id' => null, 'background_image_url' => 'https://e.test/a.jpg']],
    'cta_band' => ['cta_band', ['title' => 'T', 'body' => 'B', 'theme' => 'warm', 'primary_label' => 'P', 'primary_url' => '/p', 'secondary_label' => 'S', 'secondary_url' => '/s']],
    'accordion' => ['accordion_list', ['title' => 'T', 'body' => 'B', 'items' => [['title' => 'Q', 'points' => ['a', 'b']]]]],
    'split' => ['split_info_panel', ['title' => 'T', 'subtitle' => 'S', 'body_title' => 'BT', 'paragraphs' => ['p1', 'p2'], 'cta_label' => 'C', 'cta_url' => '/c', 'specs_title' => 'ST', 'specs' => [['icon_class' => 'i', 'label' => 'L', 'value' => 'V']]]],
    'checklist' => ['checklist_callout', ['title' => 'T', 'subtitle' => 'S', 'items' => [['label' => 'L', 'body' => 'B']], 'callout_title' => 'CT', 'callout_body' => 'CB', 'callout_link_label' => 'CL', 'callout_link_url' => '/cl']],
    'icon_callout' => ['icon_callout', ['title' => 'T', 'body' => 'B', 'cta_label' => 'C', 'cta_url' => '/c', 'icon_class' => 'fa x']],
    'icon_card_grid' => ['icon_card_grid', ['title' => 'T', 'body' => 'B', 'items' => [['title' => 'A', 'icon_class' => 'i', 'body' => 'b']]]],
    'logo_grid' => ['logo_grid', ['title' => 'T', 'body' => 'B', 'items' => [['name' => 'N', 'url' => '/u', 'image_url' => 'https://e.test/l.png']]]],
    'stat_strip' => ['stat_strip', ['title' => 'T', 'items' => [['headline' => 'H', 'icon_class' => 'i', 'url' => '/u', 'emphasize' => true]]]],
    'panel_card' => ['comparison_panel', ['eyebrow' => 'E', 'title' => 'T', 'paragraphs' => ['p'], 'checklist_items' => ['c'], 'section_tone' => 'gray', 'image_position' => 'start', 'aside_type' => 'card', 'aside_title' => 'AT', 'aside_theme' => 'light', 'aside_rows' => [['icon_class' => 'i', 'label' => 'L', 'value' => 'V']], 'image_asset_id' => null, 'image_url' => '', 'image_alt' => '']],
    'embed' => ['embed', ['url' => 'https://e.test/v', 'html' => '<iframe></iframe>']],
    'raw' => ['raw_html', ['html' => '<p>x</p>']],
]);

it('finds the media of a type from its fields, so usage tracking needs no separate list', function () {
    expect(blockType('hero')->allMediaPaths())->toBe(['background_image_asset_id'])
        ->and(blockType('image')->allMediaPaths())->toBe(['asset_id'])
        ->and(blockType('gallery')->allMediaPaths())->toBe(['items.*.asset_id'])
        ->and(blockType('card_grid')->allMediaPaths())->toBe(['cards.*.image_asset_id'])
        ->and(blockType('comparison_panel')->allMediaPaths())->toBe(['image_asset_id'])
        ->and(blockType('divider')->allMediaPaths())->toBe([]);

    $custom = new BlockType('x', 'X', ['extra.id'], fields: [Field::media('Logo', 'logo_id', 'logo_url')]);
    expect($custom->allMediaPaths())->toBe(['extra.id', 'logo_id']);
});

it('shows a field only when its siblings match', function () {
    $field = Field::text('aside_title', 'Title')->showWhen(['aside_type' => 'card']);

    expect($field->visibleWhen)->toBe(['aside_type' => 'card'])
        ->and(Field::items('rows', 'Rows', [])->showWhen(['a' => 'b'])->visibleWhen)->toBe(['a' => 'b'])
        ->and(Field::strings('s', 'S')->showWhen(['a' => 'b'])->visibleWhen)->toBe(['a' => 'b'])
        ->and(Field::media('M')->showWhen(['a' => 'b'])->visibleWhen)->toBe(['a' => 'b']);
});

it('supplies wording for a field a block was saved without, and keeps a saved blank blank', function () {
    $field = Field::text('heading', 'Heading')->withFallback(fn (array $data): string => ($data['kind'] ?? 'a') === 'b' ? 'B heading' : 'A heading');

    expect($field->hydrate(['kind' => 'b']))->toBe(['heading' => 'B heading'])
        ->and($field->hydrate(['heading' => 'Mine']))->toBe(['heading' => 'Mine'])
        ->and($field->hydrate(['heading' => '']))->toBe(['heading' => ''])
        ->and($field->normalize(['heading' => '  ']))->toBe(['heading' => '']);
});

it('can save the fallback in place of a blank value, and carries it through showWhen', function () {
    $field = Field::text('name', 'Name')->withFallback(fn (array $data): string => 'Default name', whenBlank: true)->showWhen(['kind' => 'b']);

    expect($field->normalize(['name' => ' ']))->toBe(['name' => 'Default name'])
        ->and($field->normalize(['name' => ' Kept ']))->toBe(['name' => 'Kept'])
        ->and($field->hydrate([]))->toBe(['name' => 'Default name'])
        ->and($field->visibleWhen)->toBe(['kind' => 'b']);
});

it('saves an integer choice as a number, falling back to the default for anything not on offer', function () {
    $field = Field::select('columns', 'Columns', ['2' => 'Two', '3' => 'Three'], '2')->asInteger();

    expect($field->normalize(['columns' => '3']))->toBe(['columns' => 3])
        ->and($field->normalize(['columns' => '9']))->toBe(['columns' => 2])
        ->and($field->normalize([]))->toBe(['columns' => 2])
        ->and($field->hydrate(['columns' => 3]))->toBe(['columns' => '3']);
});
