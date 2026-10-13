@if ($record)
    @livewire(
        \ForumKit\Cms\Filament\Resources\CmsPages\RelationManagers\BlocksRelationManager::class,
        [
            'ownerRecord' => $record,
            'pageClass' => $pageClass,
        ],
        key('cms-page-blocks-'.$record->getKey())
    )
@endif
