<?php

declare(strict_types=1);

namespace Filagora\Cms\BlockTypes\Fields;

/**
 * One piece of a block type's content. A field says how it is labelled and edited, and how its
 * value is cleaned when saved (`normalize`) and brought back into shape when loaded (`hydrate`,
 * which also upgrades old stored shapes). The block form and the saved JSON are built from the
 * same list, so they cannot drift apart.
 *
 * The factories below are the vocabulary a block type is written in.
 */
abstract class Field
{
    /**
     * @param  array<string, mixed>|null  $visibleWhen  Show only when these sibling values match, for example `['aside_type' => 'card']`.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $help = null,
        public readonly ?array $visibleWhen = null,
    ) {}

    /**
     * The saved values for this field, from the submitted values of its block (or item).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    abstract public function normalize(array $input): array;

    /**
     * The values to put in the form for this field, from a stored block (or item).
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    abstract public function hydrate(array $stored): array;

    /**
     * Where in the block data a media asset id is kept, dot notation with `*` for list items.
     *
     * @return list<string>
     */
    public function mediaPaths(): array
    {
        return [];
    }

    /**
     * A copy of this field that is shown only when the given sibling values match.
     *
     * @param  array<string, mixed>  $conditions
     */
    abstract public function showWhen(array $conditions): static;

    public static function text(string $key, string $label, ?string $help = null): ScalarField
    {
        return new ScalarField($key, $label, ScalarKind::Text, help: $help);
    }

    public static function url(string $key, string $label, ?string $help = null): ScalarField
    {
        return new ScalarField($key, $label, ScalarKind::Url, help: $help);
    }

    public static function textarea(string $key, string $label, ?string $help = null, int $rows = 6): ScalarField
    {
        return new ScalarField($key, $label, ScalarKind::Textarea, help: $help, rows: $rows);
    }

    public static function markdown(string $key, string $label, ?string $help = null, int $rows = 3): ScalarField
    {
        return new ScalarField($key, $label, ScalarKind::Markdown, help: $help, rows: $rows);
    }

    /**
     * @param  array<string, string>  $options  value => label
     */
    public static function select(string $key, string $label, array $options, string $default, ?string $help = null): ScalarField
    {
        return new ScalarField($key, $label, ScalarKind::Select, options: $options, default: $default, help: $help);
    }

    public static function toggle(string $key, string $label, ?string $help = null): ScalarField
    {
        return new ScalarField($key, $label, ScalarKind::Toggle, help: $help);
    }

    public static function number(string $key, string $label, ?string $help = null): ScalarField
    {
        return new ScalarField($key, $label, ScalarKind::Number, help: $help);
    }

    public static function strings(string $key, string $label, ?string $help = null, bool $markdown = false, string $itemLabel = 'Item'): StringList
    {
        return new StringList($key, $label, $markdown, $itemLabel, $help);
    }

    /**
     * @param  list<Field>  $fields
     * @param  string|null  $legacyScalarKey  An old stored item that is just a string becomes `[$legacyScalarKey => string]`.
     */
    public static function items(string $key, string $label, array $fields, ?string $titleKey = null, ?string $legacyScalarKey = null, ?string $help = null): Items
    {
        return new Items($key, $label, $fields, $titleKey, $legacyScalarKey, $help);
    }

    public static function media(string $label, string $assetKey = 'asset_id', string $urlKey = 'url', ?string $altKey = null, ?string $help = null): Media
    {
        return new Media($assetKey, $label, $assetKey, $urlKey, $altKey, $help);
    }
}
