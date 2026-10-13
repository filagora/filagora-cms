<?php

declare(strict_types=1);

namespace Filagora\Cms\Services;

use Filagora\Cms\Models\CmsNavigation;

/**
 * Describes what publishing a menu draft would change: its staged items compared with the live
 * ones, matched by label path ("Support › Donate").
 */
class NavigationDraftDiff
{
    private const FIELD_LABELS = [
        'url' => 'URL',
        'type' => 'type',
        'reference' => 'reference',
        'target' => 'target',
        'entitlements' => 'visible for',
    ];

    /**
     * @return array{changes: list<array{status: string, label: string, details: list<string>}>, count: int}
     */
    public function forNavigation(CmsNavigation $navigation): array
    {
        $draft = $navigation->draft()->first();

        if ($draft === null) {
            return ['changes' => [], 'count' => 0];
        }

        $live = $this->flatten(is_array($navigation->items) ? $navigation->items : []);
        $staged = $this->flatten($draft->items());
        $changes = [];

        foreach ($staged as $path => $item) {
            if (! isset($live[$path])) {
                $changes[] = ['status' => 'Added', 'label' => $path, 'details' => []];

                continue;
            }

            $details = $this->fieldDetails($live[$path], $item);

            if ($live[$path]['position'] !== $item['position']) {
                $details[] = 'position '.($live[$path]['position'] + 1).' → '.($item['position'] + 1);
            }

            if ($details !== []) {
                $changes[] = ['status' => 'Changed', 'label' => $path, 'details' => $details];
            }
        }

        foreach (array_keys($live) as $path) {
            if (! isset($staged[$path])) {
                $changes[] = ['status' => 'Removed', 'label' => $path, 'details' => []];
            }
        }

        return ['changes' => $changes, 'count' => count($changes)];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<string, array{url: string, type: string, reference: string, target: string, entitlements: string, position: int}>
     */
    private function flatten(array $items, string $prefix = ''): array
    {
        $flat = [];

        foreach (array_values($items) as $position => $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? '')) ?: '(untitled)';
            $path = $prefix === '' ? $label : $prefix.' › '.$label;

            // Two links with the same label under one parent stay distinguishable.
            if (isset($flat[$path])) {
                $path .= ' #'.($position + 1);
            }

            $entitlements = is_array($item['entitlements'] ?? null) ? array_map('strval', $item['entitlements']) : [];
            sort($entitlements);

            $flat[$path] = [
                'url' => trim((string) ($item['url'] ?? '')),
                'type' => trim((string) ($item['type'] ?? 'url')) ?: 'url',
                'reference' => trim((string) ($item['reference'] ?? '')),
                'target' => trim((string) ($item['target'] ?? '_self')) ?: '_self',
                'entitlements' => implode(', ', $entitlements),
                'position' => $position,
            ];

            if (is_array($item['children'] ?? null) && $item['children'] !== []) {
                $flat += $this->flatten($item['children'], $path);
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $live
     * @param  array<string, mixed>  $staged
     * @return list<string>
     */
    private function fieldDetails(array $live, array $staged): array
    {
        $details = [];

        foreach (self::FIELD_LABELS as $field => $label) {
            if ($live[$field] !== $staged[$field]) {
                $details[] = $label.': '.($live[$field] === '' ? '(none)' : $live[$field]).' → '.($staged[$field] === '' ? '(none)' : $staged[$field]);
            }
        }

        return $details;
    }
}
