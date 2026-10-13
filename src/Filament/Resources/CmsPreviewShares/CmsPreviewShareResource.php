<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Resources\CmsPreviewShares;

use BackedEnum;
use Filagora\Cms\Filament\Concerns\GatedByManage;
use Filagora\Cms\Filament\Resources\CmsPreviewShares\Pages\ListCmsPreviewShares;
use Filagora\Cms\Models\CmsPreviewShare;
use Filagora\Cms\Preview\PreviewShares;
use Filagora\Cms\Support\Models;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Core\Support\UserModel;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The shareable preview links editors have made, so they can be copied or revoked.
 */
class CmsPreviewShareResource extends Resource
{
    use GatedByManage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?int $navigationSort = 35;

    public static function getModel(): string
    {
        return Models::class('preview_share');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label(__('filagora-cms::ui.cms_preview_share_resource.cms'));
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-cms::messages.shares.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-cms::messages.shares.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-cms::messages.shares.plural_model_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('label')->placeholder(__('filagora-cms::ui.cms_preview_share_resource.no_label'))->searchable()->weight('medium'),
                TextColumn::make('contents')
                    ->label(__('filagora-cms::ui.cms_preview_share_resource.shows'))
                    ->state(function (CmsPreviewShare $record): string {
                        $parts = [];

                        foreach ($record->navigations() as $name => $variant) {
                            $parts[] = "{$name} menu ({$variant})";
                        }

                        foreach ($record->pages() as $id => $variant) {
                            $parts[] = "Page #{$id} ({$variant})";
                        }

                        return implode(', ', $parts);
                    }),
                TextColumn::make('status')
                    ->state(fn (CmsPreviewShare $record): string => match (true) {
                        $record->revoked_at !== null => 'Revoked',
                        ! $record->expires_at->isFuture() => 'Expired',
                        default => 'Active',
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
                TextColumn::make('expires_at')->dateTime()->sortable(),
                TextColumn::make('open_count')->label(__('filagora-cms::ui.cms_preview_share_resource.opens'))->sortable(),
                TextColumn::make('last_opened_at')->dateTime()->placeholder(__('filagora-cms::ui.cms_preview_share_resource.never')),
                TextColumn::make('createdBy.'.UserModel::titleAttribute())->label(__('filagora-cms::ui.cms_preview_share_resource.created_by')),
            ])
            ->recordActions([
                Action::make('show_link')
                    ->label(__('filagora-cms::ui.cms_preview_share_resource.show_link'))
                    ->icon('heroicon-o-link')
                    ->visible(fn (CmsPreviewShare $record): bool => $record->isUsable())
                    ->action(fn (CmsPreviewShare $record) => Notification::make()
                        ->title(__('filagora-cms::ui.cms_preview_share_resource.preview_link'))
                        ->body($record->url() ?? 'Define the route named in filagora-cms.preview.share_route to open links. Token: '.$record->getRawOriginal('token'))
                        ->persistent()
                        ->send()),
                Action::make('revoke')
                    ->label(__('filagora-cms::ui.cms_preview_share_resource.revoke'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (CmsPreviewShare $record): bool => $record->revoked_at === null)
                    ->action(function (CmsPreviewShare $record): void {
                        app(PreviewShares::class)->revoke($record);
                        Notification::make()->success()->title(__('filagora-cms::ui.cms_preview_share_resource.link_revoked'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCmsPreviewShares::route('/')];
    }
}
