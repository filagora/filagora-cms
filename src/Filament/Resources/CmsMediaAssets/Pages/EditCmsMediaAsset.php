<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsMediaAssets\Pages;

use Filagora\Cms\Filament\Resources\CmsMediaAssets\CmsMediaAssetResource;
use Filagora\Cms\Media\MediaAssets;
use Filagora\Cms\Models\CmsMediaAsset;
use Filagora\Cms\Services\MediaAssetUsage;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCmsMediaAsset extends EditRecord
{
    protected static string $resource = CmsMediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_file')
                ->label('Open file')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): ?string => $this->asset()->publicUrl(), shouldOpenInNewTab: true)
                ->visible(fn (): bool => filled($this->asset()->publicUrl())),
            DeleteAction::make()
                ->schema([
                    Toggle::make('confirm_in_use_delete')
                        ->label('I understand this asset may still be referenced by CMS blocks')
                        ->helperText('Required only when the asset is still in use.')
                        ->visible(fn (): bool => app(MediaAssetUsage::class)->countForAsset($this->asset()) > 0),
                ])
                ->modalDescription(function (): string {
                    $uses = app(MediaAssetUsage::class)->countForAsset($this->asset());

                    return $uses <= 0
                        ? 'This deletes the media asset record and removes the stored file.'
                        : 'This deletes the media asset record and removes the stored file. Warning: this asset is currently used by '.$uses.' CMS block'.($uses === 1 ? '' : 's').'.';
                })
                ->before(function (DeleteAction $action, array $data): void {
                    $uses = app(MediaAssetUsage::class)->countForAsset($this->asset());

                    if ($uses > 0 && ! (bool) ($data['confirm_in_use_delete'] ?? false)) {
                        Notification::make()
                            ->warning()
                            ->title('Confirm deletion of in-use asset')
                            ->body('This media asset is still used by '.$uses.' CMS block'.($uses === 1 ? '' : 's').'. Tick the confirmation box to delete it anyway.')
                            ->send();
                        $action->halt();

                        return;
                    }

                    app(MediaAssets::class)->deleteFile($this->asset());
                }),
        ];
    }

    private function asset(): CmsMediaAsset
    {
        assert($this->record instanceof CmsMediaAsset);

        return $this->record;
    }
}
