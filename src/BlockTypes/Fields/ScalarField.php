<?php

declare(strict_types=1);

namespace Filagora\Cms\BlockTypes\Fields;

/**
 * A single value: text, a URL, a block of text or markdown, one choice from a list, a switch, or
 * a whole number.
 */
final class ScalarField extends Field
{
    /**
     * @param  array<string, string>  $options
     * @param  array<string, mixed>|null  $visibleWhen
     */
    public function __construct(
        string $key,
        string $label,
        public readonly ScalarKind $kind = ScalarKind::Text,
        public readonly array $options = [],
        public readonly ?string $default = null,
        ?string $help = null,
        ?array $visibleWhen = null,
        public readonly int $rows = 3,
    ) {
        parent::__construct($key, $label, $help, $visibleWhen);
    }

    public function showWhen(array $conditions): static
    {
        return new self($this->key, $this->label, $this->kind, $this->options, $this->default, $this->help, $conditions, $this->rows);
    }

    public function normalize(array $input): array
    {
        $value = $input[$this->key] ?? null;

        return [$this->key => match ($this->kind) {
            ScalarKind::Toggle => (bool) $value,
            ScalarKind::Number => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
            ScalarKind::Select => ($text = trim((string) $value)) !== '' ? $text : (string) $this->default,
            default => trim((string) $value),
        }];
    }

    public function hydrate(array $stored): array
    {
        $value = $stored[$this->key] ?? null;

        return [$this->key => match ($this->kind) {
            ScalarKind::Toggle => (bool) $value,
            ScalarKind::Number => is_numeric($value) ? (int) $value : null,
            ScalarKind::Select => ($text = trim((string) $value)) !== '' ? $text : $this->default,
            default => (string) $value,
        }];
    }
}
