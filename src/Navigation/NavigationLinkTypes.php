<?php

declare(strict_types=1);

namespace Filagora\Cms\Navigation;

use Closure;
use Illuminate\Support\Str;

/**
 * The kinds of menu link besides a plain `url`. A host registers the kinds its site has (a link
 * to a legal page, a link to a named page of the application, a logout action) with a resolver
 * that turns the item's `reference` into a URL, or null when the reference is not one it allows.
 */
final class NavigationLinkTypes
{
    /** @var array<string, array{resolver: Closure(string): ?string, keepsPlaceholder: bool, label: string, references: array<string, string>}> */
    private array $types = [];

    /**
     * @param  Closure(string): ?string  $resolver
     * @param  bool  $keepsPlaceholder  An item of this kind is kept when it resolves to `#` (an action such as logout has no address). A reference that resolves to null drops the item either way.
     * @param  string|null  $label  What the menu editor calls this kind (default: the type, headlined).
     * @param  array<string, string>  $references  The references an editor may pick, as value => label.
     */
    public function register(string $type, Closure $resolver, bool $keepsPlaceholder = false, ?string $label = null, array $references = []): void
    {
        $this->types[$type] = [
            'resolver' => $resolver,
            'keepsPlaceholder' => $keepsPlaceholder,
            'label' => $label ?? Str::headline($type),
            'references' => $references,
        ];
    }

    /**
     * The kinds an editor can choose, `url` first: type => label.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = ['url' => 'URL'];

        foreach ($this->types as $type => $definition) {
            $options[$type] = $definition['label'];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function references(string $type): array
    {
        return $this->types[$type]['references'] ?? [];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function resolve(string $type, string $reference): ?string
    {
        return isset($this->types[$type]) ? ($this->types[$type]['resolver'])($reference) : null;
    }

    public function keepsPlaceholder(string $type): bool
    {
        return $this->types[$type]['keepsPlaceholder'] ?? false;
    }
}
