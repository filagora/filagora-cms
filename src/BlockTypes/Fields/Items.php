<?php

declare(strict_types=1);

namespace Filagora\Cms\BlockTypes\Fields;

/**
 * A repeatable group of fields (cards, links, table rows). Keys an item carries that the group
 * does not know are kept as they are, so data saved by something newer is not lost on an edit.
 */
final class Items extends Field
{
    /**
     * @param  list<Field>  $fields
     * @param  array<string, mixed>|null  $visibleWhen
     */
    public function __construct(
        string $key,
        string $label,
        public readonly array $fields,
        public readonly ?string $titleKey = null,
        public readonly ?string $legacyScalarKey = null,
        ?string $help = null,
        ?array $visibleWhen = null,
    ) {
        parent::__construct($key, $label, $help, $visibleWhen);
    }

    public function showWhen(array $conditions): static
    {
        return new self($this->key, $this->label, $this->fields, $this->titleKey, $this->legacyScalarKey, $this->help, $conditions);
    }

    public function normalize(array $input): array
    {
        return [$this->key => array_map(function (array $item): array {
            $out = $item;

            foreach ($this->fields as $field) {
                $out = array_merge($out, $field->normalize($item));
            }

            return $out;
        }, $this->entries($input[$this->key] ?? []))];
    }

    public function hydrate(array $stored): array
    {
        return [$this->key => array_map(function (array $item): array {
            $out = $item;

            foreach ($this->fields as $field) {
                $out = array_merge($out, $field->hydrate($item));
            }

            return $out;
        }, $this->entries($stored[$this->key] ?? []))];
    }

    public function mediaPaths(): array
    {
        $paths = [];

        foreach ($this->fields as $field) {
            foreach ($field->mediaPaths() as $path) {
                $paths[] = $this->key.'.*.'.$path;
            }
        }

        return $paths;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = $item;
            } elseif ($this->legacyScalarKey !== null && is_scalar($item)) {
                $items[] = [$this->legacyScalarKey => trim((string) $item)];
            }
        }

        return $items;
    }
}
