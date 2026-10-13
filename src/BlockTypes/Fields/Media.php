<?php

declare(strict_types=1);

namespace Filagora\Cms\BlockTypes\Fields;

use Filagora\Cms\Models\CmsMediaAsset;
use Filagora\Cms\Support\Models;

/**
 * An image: a media asset picked from the library, or a URL typed in. On save the asset's public
 * URL is stored beside its id (a URL typed in is the fallback when no asset is chosen), and the
 * alt text falls back to the asset's.
 */
final class Media extends Field
{
    /**
     * @param  array<string, mixed>|null  $visibleWhen
     */
    public function __construct(
        string $key,
        string $label,
        public readonly string $assetKey,
        public readonly string $urlKey,
        public readonly ?string $altKey = null,
        ?string $help = null,
        ?array $visibleWhen = null,
    ) {
        parent::__construct($key, $label, $help, $visibleWhen);
    }

    public function showWhen(array $conditions): static
    {
        return new self($this->key, $this->label, $this->assetKey, $this->urlKey, $this->altKey, $this->help, $conditions);
    }

    public function normalize(array $input): array
    {
        $asset = $this->asset($input[$this->assetKey] ?? null);
        $out = [
            $this->assetKey => $asset?->id,
            $this->urlKey => $asset?->publicUrl() ?: trim((string) ($input[$this->urlKey] ?? '')),
        ];

        if ($this->altKey !== null) {
            $alt = trim((string) ($input[$this->altKey] ?? ''));
            $out[$this->altKey] = $alt !== '' ? $alt : ($asset !== null ? trim((string) $asset->alt_text) : '');
        }

        return $out;
    }

    public function hydrate(array $stored): array
    {
        $id = $stored[$this->assetKey] ?? null;
        $out = [
            $this->assetKey => is_numeric($id) && (int) $id > 0 ? (int) $id : null,
            $this->urlKey => (string) ($stored[$this->urlKey] ?? ''),
        ];

        if ($this->altKey !== null) {
            $out[$this->altKey] = (string) ($stored[$this->altKey] ?? '');
        }

        return $out;
    }

    public function mediaPaths(): array
    {
        return [$this->assetKey];
    }

    private function asset(mixed $id): ?CmsMediaAsset
    {
        if (! is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        $asset = Models::class('media_asset')::query()->find((int) $id);

        return $asset instanceof CmsMediaAsset ? $asset : null;
    }
}
