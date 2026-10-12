<?php

declare(strict_types=1);

namespace ForumKit\Cms\Models;

use ForumKit\Core\Support\Tables;
use ForumKit\Core\Support\UserModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * An uploaded file. `meta` may hold `extension`, `width` and `height`.
 *
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string|null $mime_type
 * @property int|null $size
 * @property string|null $alt_text
 * @property array<string, mixed>|null $meta
 * @property int|null $uploaded_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CmsMediaAsset extends Model
{
    protected $fillable = [
        'disk',
        'path',
        'filename',
        'mime_type',
        'size',
        'alt_text',
        'meta',
        'uploaded_by_id',
    ];

    public function getTable(): string
    {
        return Tables::name('cms_media_assets');
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'uploaded_by_id');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    /**
     * The disk URL of the file, or null when it has no path, the disk cannot say, or the file is gone.
     */
    public function publicUrl(): ?string
    {
        if (blank($this->path)) {
            return null;
        }

        try {
            $disk = Storage::disk($this->disk);

            return $disk->exists($this->path) ? $disk->url($this->path) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function extension(): ?string
    {
        $extension = is_array($this->meta) ? ($this->meta['extension'] ?? null) : null;

        return filled($extension) ? (string) $extension : null;
    }

    public function width(): ?int
    {
        $width = is_array($this->meta) ? ($this->meta['width'] ?? null) : null;

        return is_numeric($width) ? (int) $width : null;
    }

    public function height(): ?int
    {
        $height = is_array($this->meta) ? ($this->meta['height'] ?? null) : null;

        return is_numeric($height) ? (int) $height : null;
    }

    public function dimensionsLabel(): ?string
    {
        if (($this->width() ?? 0) <= 0 || ($this->height() ?? 0) <= 0) {
            return null;
        }

        return $this->width().' x '.$this->height();
    }
}
