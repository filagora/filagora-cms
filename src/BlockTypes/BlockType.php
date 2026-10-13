<?php

declare(strict_types=1);

namespace Filagora\Cms\BlockTypes;

use Filagora\Cms\BlockTypes\Fields\Field;

/**
 * One kind of content block: its key, label, and (optionally) the fields that make up its content.
 *
 * With `fields`, the editor shows a real form for it and the saved JSON is built from them. Without,
 * the editor falls back to a JSON box, which is also what an unknown stored type gets.
 *
 * `mediaPaths` lists where in the data a media asset id lives that the fields do not describe;
 * the fields' own media are found automatically. Dot notation, `*` for each list item.
 */
final readonly class BlockType
{
    /**
     * @param  list<string>  $mediaPaths
     * @param  list<Field>  $fields
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $mediaPaths = [],
        public ?string $description = null,
        public array $fields = [],
    ) {}

    public function hasForm(): bool
    {
        return $this->fields !== [];
    }

    /**
     * Where a media asset id is kept in this type's data.
     *
     * @return list<string>
     */
    public function allMediaPaths(): array
    {
        $paths = $this->mediaPaths;

        foreach ($this->fields as $field) {
            array_push($paths, ...$field->mediaPaths());
        }

        return array_values(array_unique($paths));
    }

    /**
     * The data to store, from what the form submitted: every field cleaned, anything else dropped.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    public function normalize(array $submitted): array
    {
        $data = [];

        foreach ($this->fields as $field) {
            $data = array_merge($data, $field->normalize($submitted));
        }

        return $data;
    }

    /**
     * The form values for stored data: every field present, old stored shapes upgraded.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public function hydrate(?array $stored): array
    {
        $stored ??= [];
        $values = [];

        foreach ($this->fields as $field) {
            $values = array_merge($values, $field->hydrate($stored));
        }

        return $values;
    }
}
