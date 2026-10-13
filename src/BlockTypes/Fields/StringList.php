<?php

declare(strict_types=1);

namespace Filagora\Cms\BlockTypes\Fields;

/**
 * A list of plain strings (bullet points, paragraphs). Blank entries are dropped.
 */
final class StringList extends Field
{
    /**
     * @param  array<string, mixed>|null  $visibleWhen
     */
    public function __construct(
        string $key,
        string $label,
        public readonly bool $markdown = false,
        public readonly string $itemLabel = 'Item',
        ?string $help = null,
        ?array $visibleWhen = null,
    ) {
        parent::__construct($key, $label, $help, $visibleWhen);
    }

    public function showWhen(array $conditions): static
    {
        return new self($this->key, $this->label, $this->markdown, $this->itemLabel, $this->help, $conditions);
    }

    public function normalize(array $input): array
    {
        return [$this->key => $this->clean($input[$this->key] ?? [])];
    }

    public function hydrate(array $stored): array
    {
        return [$this->key => $this->clean($stored[$this->key] ?? [])];
    }

    /**
     * @return list<string>
     */
    private function clean(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            // An older form saved each entry as {"text": "..."}.
            $text = trim((string) (is_array($entry) ? ($entry['text'] ?? $entry['value'] ?? '') : $entry));

            if ($text !== '') {
                $strings[] = $text;
            }
        }

        return $strings;
    }
}
