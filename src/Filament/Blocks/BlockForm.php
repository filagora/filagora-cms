<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Blocks;

use Filagora\Cms\BlockTypes\BlockTypeRegistry;
use Filagora\Cms\BlockTypes\Fields\Field;
use Filagora\Cms\BlockTypes\Fields\Items;
use Filagora\Cms\BlockTypes\Fields\Media;
use Filagora\Cms\BlockTypes\Fields\ScalarField;
use Filagora\Cms\BlockTypes\Fields\ScalarKind;
use Filagora\Cms\BlockTypes\Fields\StringList;
use Filagora\Cms\Filament\Forms\CmsMarkdownEditor;
use Filagora\Cms\Models\CmsBlock;
use Filagora\Cms\Models\CmsMediaAsset;
use Filagora\Cms\Support\Models;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The form of a block (type, name, order, and the content fields of its type) and the two
 * conversions around it: a stored block into form state (`fill`), form state into what to store
 * (`prepare`). Each registered type with fields gets its own form, built from those fields;
 * any other type (unknown, or registered without fields) gets a JSON box.
 *
 * Form state keeps a typed block's content under `data`, so the saved JSON is that state, cleaned.
 */
final class BlockForm
{
    /**
     * The type, name and order fields, and the content fields for the block's type.
     *
     * @return list<Component>
     */
    public static function components(): array
    {
        $registry = app(BlockTypeRegistry::class);

        $components = [
            Select::make('type')
                ->label(__('filagora-cms::ui.block_form.type'))
                ->options(function (?CmsBlock $record) use ($registry): array {
                    $options = $registry->options();

                    if ($record !== null && ! isset($options[$record->type])) {
                        $options[$record->type] = $registry->label($record->type);
                    }

                    return $options;
                })
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state) use ($registry): void {
                    // Content is per type: start the new type from its defaults.
                    $set('data', $registry->get((string) $state)?->hydrate([]) ?? []);
                    $set('data_json', '');
                }),
            TextInput::make('name')->label(__('filagora-cms::ui.block_form.name'))->maxLength(255),
            TextInput::make('sort_order')->label(__('filagora-cms::ui.block_form.display_order'))->numeric()->minValue(0)->default(0)->required()
                ->helperText(__('filagora-cms::ui.block_form.lower_values_appear_first')),
        ];

        foreach ($registry->all() as $key => $type) {
            if (! $type->hasForm()) {
                continue;
            }

            $components[] = Group::make([
                Group::make(self::fields($type->fields))->statePath('data')->columnSpanFull(),
            ])
                ->visible(fn (Get $get): bool => $registry->is($get('type'), $key))
                ->columnSpanFull();
        }

        $components[] = Textarea::make('data_json')
            ->label(__('filagora-cms::ui.block_form.content_json'))
            ->rows(14)
            ->rules(['nullable', 'json'])
            ->helperText(__('filagora-cms::ui.block_form.the_blocks_content_as_json_leave'))
            ->visible(fn (Get $get): bool => ! ($registry->get((string) $get('type'))?->hasForm() ?? false))
            ->columnSpanFull();

        return $components;
    }

    /**
     * Form state for a stored block.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function fill(array $record): array
    {
        $type = app(BlockTypeRegistry::class)->get((string) ($record['type'] ?? ''));
        $data = is_array($record['data'] ?? null) ? $record['data'] : [];

        if ($type?->hasForm()) {
            $record['data'] = $type->hydrate($data);
            $record['data_json'] = '';

            return $record;
        }

        $record['data_json'] = $data !== []
            ? (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';
        $record['data'] = [];

        return $record;
    }

    /**
     * What to store for submitted form state.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function prepare(array $form): array
    {
        $type = app(BlockTypeRegistry::class)->get((string) ($form['type'] ?? ''));
        $json = trim((string) ($form['data_json'] ?? ''));
        $submitted = is_array($form['data'] ?? null) ? $form['data'] : [];
        unset($form['data_json']);

        if ($type?->hasForm()) {
            $form['data'] = $type->normalize($submitted);
        } else {
            $form['data'] = $json === '' ? null : json_decode($json, true);
        }

        return $form;
    }

    /**
     * @param  list<Field>  $fields
     * @return list<Component>
     */
    public static function fields(array $fields): array
    {
        $components = [];

        foreach ($fields as $field) {
            array_push($components, ...self::component($field));
        }

        return $components;
    }

    /**
     * @return list<Component>
     */
    private static function component(Field $field): array
    {
        $components = match (true) {
            $field instanceof ScalarField => [self::scalar($field)],
            $field instanceof StringList => [self::stringList($field)],
            $field instanceof Items => [self::items($field)],
            $field instanceof Media => self::media($field),
            default => [],
        };

        if ($field->visibleWhen !== null) {
            $conditions = $field->visibleWhen;

            return [Group::make($components)
                ->visible(fn (Get $get): bool => collect($conditions)->every(fn (mixed $expected, string $sibling): bool => ($get($sibling) ?? self::defaultOf($field, $sibling)) === $expected))
                ->columnSpanFull()];
        }

        return $components;
    }

    /**
     * What a sibling select holds before the editor touches it: its default, which is what is stored.
     */
    private static function defaultOf(Field $field, string $sibling): ?string
    {
        foreach (app(BlockTypeRegistry::class)->all() as $type) {
            foreach ($type->fields as $candidate) {
                if ($candidate instanceof ScalarField && $candidate->key === $sibling && $candidate->kind === ScalarKind::Select) {
                    return $candidate->default;
                }
            }
        }

        return null;
    }

    private static function scalar(ScalarField $field): Component
    {
        $component = match ($field->kind) {
            ScalarKind::Text, ScalarKind::Url => TextInput::make($field->key)->maxLength($field->kind === ScalarKind::Url ? 2048 : 500),
            ScalarKind::Textarea => Textarea::make($field->key)->rows($field->rows)->columnSpanFull(),
            ScalarKind::Markdown => CmsMarkdownEditor::make($field->key)->rows($field->rows)->columnSpanFull(),
            ScalarKind::Select => Select::make($field->key)->options($field->options)->default($field->default)->native(false),
            ScalarKind::Toggle => Toggle::make($field->key)->default(false),
            ScalarKind::Number => TextInput::make($field->key)->numeric()->minValue(1),
        };

        $component->label($field->label);

        if ($field->help !== null && $field->kind !== ScalarKind::Markdown) {
            $component->helperText($field->help);
        }

        return $component;
    }

    private static function stringList(StringList $field): Component
    {
        $entry = $field->markdown
            ? CmsMarkdownEditor::make('text')->label($field->itemLabel)->rows(3)->required()
            : TextInput::make('text')->label($field->itemLabel)->required();

        return Repeater::make($field->key)
            ->label($field->label)
            ->simple($entry)
            ->defaultItems(0)
            ->addActionLabel('Add '.mb_strtolower($field->itemLabel))
            ->reorderableWithButtons()
            ->helperText($field->help)
            ->columnSpanFull();
    }

    private static function items(Items $field): Component
    {
        $titleKey = $field->titleKey;

        return Repeater::make($field->key)
            ->label($field->label)
            ->schema(self::fields($field->fields))
            ->defaultItems(0)
            ->addActionLabel('Add')
            ->reorderableWithButtons()
            ->cloneable()
            ->collapsible()
            ->collapsed()
            ->itemLabel(fn (array $state): ?string => $titleKey !== null && filled($state[$titleKey] ?? null) ? (string) $state[$titleKey] : null)
            ->helperText($field->help)
            ->columnSpanFull();
    }

    /**
     * @return list<Component>
     */
    private static function media(Media $field): array
    {
        $components = [
            Select::make($field->assetKey)
                ->label($field->label.' (from the library)')
                ->options(fn (): array => self::imageOptions())
                ->searchable()
                ->helperText($field->help ?? 'Pick an uploaded image, or leave blank and paste a URL below.'),
            TextInput::make($field->urlKey)->label($field->label.' URL')->url()->helperText(__('filagora-cms::ui.block_form.used_when_no_library_image_is')),
        ];

        if ($field->altKey !== null) {
            $components[] = TextInput::make($field->altKey)->label(__('filagora-cms::ui.block_form.alt_text'))->helperText(__('filagora-cms::ui.block_form.if_left_blank_the_chosen_images'));
        }

        return $components;
    }

    /**
     * @return array<int, string>
     */
    private static function imageOptions(): array
    {
        $options = [];

        /** @var CmsMediaAsset $asset */
        foreach (Models::class('media_asset')::query()->where('mime_type', 'like', 'image/%')->orderBy('filename')->get() as $asset) {
            $options[(int) $asset->id] = $asset->filename.' ('.$asset->path.')';
        }

        return $options;
    }
}
