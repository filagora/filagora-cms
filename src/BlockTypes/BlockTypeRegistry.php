<?php

declare(strict_types=1);

namespace ForumKit\Cms\BlockTypes;

use BackedEnum;
use Illuminate\Support\Str;

/**
 * The block types a site offers. A block's `type` column is a free string, so a stored value the
 * registry does not know still loads: it normalises to itself and is labelled as unknown.
 */
final class BlockTypeRegistry
{
    /** @var array<string, BlockType> */
    private array $types = [];

    /** @var array<string, string> legacy key => canonical key */
    private array $aliases = [];

    public function register(BlockType $type): void
    {
        $this->types[$type->key] = $type;
    }

    /**
     * Let an old stored key stand for a current one (for example a renamed type).
     */
    public function alias(string $legacyKey, string $canonicalKey): void
    {
        $this->aliases[strtolower(trim($legacyKey))] = $canonicalKey;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$this->normalize($key) ?? '']);
    }

    public function get(string $key): ?BlockType
    {
        return $this->types[$this->normalize($key) ?? ''] ?? null;
    }

    /**
     * @return array<string, BlockType>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * @return array<string, string> key => label, for selects.
     */
    public function options(): array
    {
        return array_map(static fn (BlockType $type): string => $type->label, $this->types);
    }

    /**
     * The canonical key for a stored value: trimmed and lowercased, a legacy alias replaced by
     * its current key, an enum case unwrapped. Null for something that is not a usable key.
     */
    public function normalize(mixed $type): ?string
    {
        if ($type instanceof BackedEnum) {
            $type = $type->value;
        }

        if (! is_string($type)) {
            return null;
        }

        $normalized = strtolower(trim($type));

        if ($normalized === '') {
            return null;
        }

        return $this->aliases[$normalized] ?? $normalized;
    }

    public function is(mixed $type, string $expected): bool
    {
        return $this->normalize($type) === $this->normalize($expected);
    }

    public function label(mixed $type): string
    {
        $key = $this->normalize($type);

        if ($key === null) {
            return 'Unknown type';
        }

        return $this->types[$key]->label ?? 'Unknown type ('.Str::headline($key).')';
    }

    /**
     * Whether the block data of this type refers to the media asset.
     *
     * @param  array<string, mixed>  $data
     */
    public function usesAsset(mixed $type, array $data, int $assetId): bool
    {
        $blockType = $this->get((string) ($this->normalize($type) ?? ''));

        if ($blockType === null) {
            return false;
        }

        foreach ($blockType->mediaPaths as $path) {
            foreach ($this->valuesAt($data, explode('.', $path)) as $value) {
                if ((int) $value === $assetId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $segments
     * @return list<mixed>
     */
    private function valuesAt(array $data, array $segments): array
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return [];
        }

        if ($segment === '*') {
            $values = [];

            foreach ($data as $item) {
                if ($segments === []) {
                    $values[] = $item;
                } elseif (is_array($item)) {
                    array_push($values, ...$this->valuesAt($item, $segments));
                }
            }

            return $values;
        }

        if (! array_key_exists($segment, $data)) {
            return [];
        }

        if ($segments === []) {
            return [$data[$segment]];
        }

        return is_array($data[$segment]) ? $this->valuesAt($data[$segment], $segments) : [];
    }
}
