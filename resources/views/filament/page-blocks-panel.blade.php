@if ($record)
    @livewire(
        \Filagora\Cms\Filament\Resources\CmsPages\RelationManagers\BlocksRelationManager::class,
        [
            'ownerRecord' => $record,
            'pageClass' => $pageClass,
        ],
        key('cms-page-blocks-'.$record->getKey())
    )
@endif
