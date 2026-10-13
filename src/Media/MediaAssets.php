<?php

declare(strict_types=1);

namespace ForumKit\Cms\Media;

use ForumKit\Cms\Models\CmsMediaAsset;
use ForumKit\Cms\Support\Models;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The file side of media assets: reading a stored file's type, size and dimensions into the
 * record, making records for freshly uploaded files, and removing the stored file.
 */
class MediaAssets
{
    /**
     * Fill in what the stored file can tell us (mime type, size, extension, image dimensions).
     * Anything the storage cannot resolve is left as it was.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function enrich(array $data, int|string|null $defaultUploaderId = null): array
    {
        $disk = trim((string) ($data['disk'] ?? 'public'));
        $path = trim((string) ($data['path'] ?? ''));
        $data['disk'] = $disk !== '' ? $disk : 'public';

        if (($data['uploaded_by_id'] ?? null) === null && $defaultUploaderId !== null) {
            $data['uploaded_by_id'] = $defaultUploaderId;
        }

        if ($path === '') {
            return $data;
        }

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        if (blank($data['filename'] ?? null)) {
            $data['filename'] = basename($path);
        }

        Arr::set($meta, 'extension', strtolower(pathinfo((string) $data['filename'], PATHINFO_EXTENSION)));

        try {
            $storage = Storage::disk($data['disk']);

            if ($storage->exists($path)) {
                $mimeType = $storage->mimeType($path);
                $size = $storage->size($path);

                if (is_string($mimeType) && $mimeType !== '') {
                    $data['mime_type'] = $mimeType;
                }

                $data['size'] = $size;

                if (str_starts_with((string) ($data['mime_type'] ?? ''), 'image/')) {
                    $dimensions = @getimagesizefromstring((string) $storage->get($path));

                    if (is_array($dimensions)) {
                        Arr::set($meta, 'width', (int) $dimensions[0]);
                        Arr::set($meta, 'height', (int) $dimensions[1]);
                    }
                } else {
                    Arr::forget($meta, ['width', 'height']);
                }
            }
        } catch (Throwable) {
            // Leave any existing metadata alone if the storage backend cannot resolve it.
        }

        $data['meta'] = $meta;

        return $data;
    }

    /**
     * One media record for each uploaded file.
     *
     * @param  array<int|string, string>  $paths
     * @param  array<int|string, string>  $originalFileNames  Keyed by path or by position.
     * @return Collection<int, CmsMediaAsset>
     */
    public function createFromUploadedPaths(array $paths, array $originalFileNames = [], int|string|null $uploadedById = null, string $disk = 'public'): Collection
    {
        /** @var Collection<int, CmsMediaAsset> $assets */
        $assets = new Collection;

        foreach (array_values($paths) as $index => $path) {
            $path = trim((string) $path);

            if ($path === '') {
                continue;
            }

            $filename = trim((string) ($originalFileNames[$path] ?? $originalFileNames[$index] ?? ''));

            /** @var CmsMediaAsset $asset */
            $asset = Models::class('media_asset')::create($this->enrich([
                'disk' => $disk,
                'path' => $path,
                'filename' => $filename !== '' ? $filename : basename($path),
                'meta' => [],
            ], $uploadedById));

            $assets->push($asset);
        }

        return $assets;
    }

    /**
     * Remove the stored file behind a record (not the record).
     */
    public function deleteFile(CmsMediaAsset $asset): void
    {
        $this->deletePath((string) ($asset->disk ?: 'public'), (string) $asset->path);
    }

    public function deletePath(string $disk, string $path): void
    {
        if (trim($path) === '') {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // A file that is already gone, or a disk that cannot be reached, must not block removing the record.
        }
    }

    /**
     * The sub-folders already used under the upload directory, for a folder picker.
     *
     * @return array<string, string>
     */
    public function folders(string $root = 'cms-media'): array
    {
        $folders = [];

        foreach (Models::class('media_asset')::query()->pluck('path') as $path) {
            $path = str_replace('\\', '/', trim((string) $path));

            if (! str_starts_with($path, $root.'/')) {
                continue;
            }

            $directory = trim(dirname($path), '/');

            if ($directory !== $root && $directory !== '.') {
                $folder = substr($directory, strlen($root) + 1);
                $folders[$folder] = $folder;
            }
        }

        ksort($folders);

        return $folders;
    }
}
