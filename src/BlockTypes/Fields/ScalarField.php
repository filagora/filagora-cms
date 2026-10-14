<?php

declare(strict_types=1);

namespace Filagora\Cms\BlockTypes\Fields;

use Closure;

/**
 * A single value: text, a URL, a block of text or markdown, one choice from a list, a switch, or
 * a whole number.
 */
final class ScalarField extends Field
{
    /**
     * @param  array<string, string>  $options
     * @param  array<string, mixed>|null  $visibleWhen
     * @param  (Closure(array<string, mixed>): string)|null  $fallback  The wording a text field has when nothing was ever saved for it, from the block's other values.
     * @param  bool  $blankFallsBack  A blank value is saved as the fallback, instead of staying blank to hide the text.
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
        public readonly ?Closure $fallback = null,
        public readonly bool $blankFallsBack = false,
        public readonly bool $integer = false,
    ) {
        parent::__construct($key, $label, $help, $visibleWhen);
    }

    public function showWhen(array $conditions): static
    {
        return new self($this->key, $this->label, $this->kind, $this->options, $this->default, $this->help, $conditions, $this->rows, $this->fallback, $this->blankFallsBack, $this->integer);
    }

    /**
     * A copy that supplies wording for a block saved before the field existed (the key is absent),
     * and optionally for a blank value. A key saved as an empty string otherwise stays empty.
     *
     * @param  Closure(array<string, mixed>): string  $fallback
     */
    public function withFallback(Closure $fallback, bool $whenBlank = false): static
    {
        return new self($this->key, $this->label, $this->kind, $this->options, $this->default, $this->help, $this->visibleWhen, $this->rows, $fallback, $whenBlank, $this->integer);
    }

    /**
     * A copy whose choice is saved as a whole number, for lists of numbers such as column counts.
     */
    public function asInteger(): static
    {
        return new self($this->key, $this->label, $this->kind, $this->options, $this->default, $this->help, $this->visibleWhen, $this->rows, $this->fallback, $this->blankFallsBack, true);
    }

    public function normalize(array $input): array
    {
        $value = $input[$this->key] ?? null;

        return [$this->key => match ($this->kind) {
            ScalarKind::Toggle => (bool) $value,
            ScalarKind::Number => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
            ScalarKind::Select => $this->integer
                ? (int) (($text = trim((string) $value)) !== '' && isset($this->options[$text]) ? $text : $this->default)
                : (($text = trim((string) $value)) !== '' ? $text : (string) $this->default),
            default => $this->blankFallsBack && ($text = trim((string) $value)) === '' ? ($this->fallback)($input) : trim((string) $value),
        }];
    }

    public function hydrate(array $stored): array
    {
        $value = $stored[$this->key] ?? null;

        return [$this->key => match ($this->kind) {
            ScalarKind::Toggle => (bool) $value,
            ScalarKind::Number => is_numeric($value) ? (int) $value : null,
            ScalarKind::Select => ($text = trim((string) $value)) !== '' ? $text : $this->default,
            default => $this->fallback !== null && ! array_key_exists($this->key, $stored) ? ($this->fallback)($stored) : (string) $value,
        }];
    }
}
