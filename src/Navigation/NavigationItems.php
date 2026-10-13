<?php

declare(strict_types=1);

namespace Filagora\Cms\Navigation;

use Filagora\Entitlements\Services\AuthorizationService;
use Illuminate\Support\Facades\Log;

/**
 * Turns the menu items an editor saved into the items a visitor may see: unknown link kinds fall
 * back to plain URLs, URLs are limited to relative paths and http(s), items the visitor lacks the
 * entitlements for are dropped, and so are empty placeholders.
 */
final class NavigationItems
{
    private const ALLOWED_TARGETS = ['_self', '_blank', '_parent', '_top'];

    public function __construct(
        private readonly NavigationLinkTypes $linkTypes,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function normalize(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $item = $this->normalizeItem($item);

            if ($item !== null) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function normalizeItem(array $item): ?array
    {
        $type = is_string($item['type'] ?? null) ? trim($item['type']) : 'url';

        if ($type !== 'url' && ! $this->linkTypes->has($type)) {
            $type = 'url';
        }

        $url = is_string($item['url'] ?? null) ? trim($item['url']) : null;
        $target = is_string($item['target'] ?? null) ? trim($item['target']) : null;

        // Bad data once stored the URL in the target field.
        if (($url === null || $url === '') && $target !== null && $target !== ''
            && ! in_array($target, self::ALLOWED_TARGETS, true) && $this->sanitizeUrl($target) !== '#') {
            $url = $target;
        }

        if ($target === null || ! in_array($target, self::ALLOWED_TARGETS, true)) {
            $target = '_self';
        }

        $item['label'] = is_string($item['label'] ?? null) ? trim($item['label']) : '';
        $item['entitlements'] = $this->entitlements($item['entitlements'] ?? []);
        $item['type'] = $type;
        $item['reference'] = is_string($item['reference'] ?? null) ? trim($item['reference']) : '';
        $item['children'] = $this->normalize($item['children'] ?? []);

        if (! $this->visible($item['entitlements'])) {
            return null;
        }

        if ($type === 'url') {
            $item['url'] = $url !== null ? $this->sanitizeUrl($url) : '#';
            $item['target'] = $target;

            return $this->isDroppablePlaceholder($item) ? null : $item;
        }

        $resolved = $this->linkTypes->resolve($type, $item['reference']);

        if ($resolved === null) {
            Log::warning('Rejected CMS navigation reference.', ['type' => $type, 'reference' => $item['reference']]);

            if ($this->linkTypes->keepsPlaceholder($type)) {
                return null;
            }

            $resolved = '#';
        }

        $item['url'] = $resolved;
        $item['target'] = '_self';

        if (! $this->linkTypes->keepsPlaceholder($type) && $this->isDroppablePlaceholder($item)) {
            return null;
        }

        return $item;
    }

    /**
     * @return list<string>
     */
    private function entitlements(mixed $entitlements): array
    {
        if (! is_array($entitlements)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $entitlement): string => trim((string) $entitlement), $entitlements),
            static fn (string $entitlement): bool => $entitlement !== '',
        )));
    }

    /**
     * @param  list<string>  $entitlements
     */
    private function visible(array $entitlements): bool
    {
        if ($entitlements === []) {
            return true;
        }

        $user = auth()->user();

        return $user !== null && $this->authorization->allowsAny($user, $entitlements);
    }

    /**
     * A link to nowhere with nothing under it.
     *
     * @param  array<string, mixed>  $item
     */
    private function isDroppablePlaceholder(array $item): bool
    {
        return ($item['url'] ?? null) === '#' && ($item['children'] ?? null) === [];
    }

    private function sanitizeUrl(string $url): string
    {
        $candidate = trim($url);

        if ($candidate === '' || $candidate === '#') {
            return '#';
        }

        if (str_starts_with($candidate, '/')) {
            if (! str_starts_with($candidate, '//')) {
                return $candidate;
            }

            Log::warning('Rejected CMS navigation URL: protocol-relative.', ['value' => $candidate]);

            return '#';
        }

        if (in_array(strtolower((string) parse_url($candidate, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return $candidate;
        }

        Log::warning('Rejected CMS navigation URL: unsupported scheme.', ['value' => $candidate]);

        return '#';
    }
}
