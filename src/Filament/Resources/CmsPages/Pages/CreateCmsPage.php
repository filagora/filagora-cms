<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsPages\Pages;

use Filagora\Cms\Enums\CmsPageStatus;
use Filagora\Cms\Filament\Resources\CmsPages\CmsPageResource;
use Filagora\Cms\Models\CmsPage;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCmsPage extends CreateRecord
{
    protected static string $resource = CmsPageResource::class;

    /**
     * New pages are created unpublished; the first version is recorded when the page is published.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tagIds = $data['tag_ids'] ?? [];
        unset($data['tag_ids']);

        $page = static::getModel()::query()->create($data + ['status' => CmsPageStatus::DRAFT]);
        assert($page instanceof CmsPage);
        $page->tags()->sync($tagIds);

        return $page;
    }
}
