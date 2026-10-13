<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsBlocks\Pages;

use Filagora\Cms\Filament\Blocks\BlockForm;
use Filagora\Cms\Filament\Resources\CmsBlocks\CmsBlockResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCmsBlock extends CreateRecord
{
    protected static string $resource = CmsBlockResource::class;

    /**
     * A new reusable block is created live (unpublished by default); editing it afterwards is staged.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data = BlockForm::prepare($data);
        $data['is_reusable'] = true;
        $data['cms_page_id'] = null;
        $data['slug'] = trim((string) $data['slug']);

        return static::getModel()::query()->create($data);
    }
}
