<?php

declare(strict_types=1);

namespace ForumKit\Cms\Filament\Resources\CmsPreviewShares;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use ForumKit\Cms\Filament\Concerns\GatedByManage;
use ForumKit\Cms\Filament\Resources\CmsPreviewShares\Pages\ListCmsPreviewShares;
use ForumKit\Cms\Models\CmsPreviewShare;
use ForumKit\Cms\Preview\PreviewShares;
use ForumKit\Cms\Support\Models;
use ForumKit\Core\Navigation\NavigationGroupRegistry;
use ForumKit\Core\Support\UserModel;
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
        return app(NavigationGroupRegistry::class)->label('cms');
    }

    public static function getNavigationLabel(): string
    {
        return __('forumkit-cms::messages.shares.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('forumkit-cms::messages.shares.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forumkit-cms::messages.shares.plural_model_label');
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
                TextColumn::make('label')->placeholder('No label')->searchable()->weight('medium'),
                TextColumn::make('contents')
                    ->label('Shows')
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
                TextColumn::make('open_count')->label('Opens')->sortable(),
                TextColumn::make('last_opened_at')->dateTime()->placeholder('Never'),
                TextColumn::make('createdBy.'.UserModel::titleAttribute())->label('Created by'),
            ])
            ->recordActions([
                Action::make('show_link')
                    ->label('Show link')
                    ->icon('heroicon-o-link')
                    ->visible(fn (CmsPreviewShare $record): bool => $record->isUsable())
                    ->action(fn (CmsPreviewShare $record) => Notification::make()
                        ->title('Preview link')
                        ->body($record->url() ?? 'Define the route named in forumkit-cms.preview.share_route to open links. Token: '.$record->getRawOriginal('token'))
                        ->persistent()
                        ->send()),
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (CmsPreviewShare $record): bool => $record->revoked_at === null)
                    ->action(function (CmsPreviewShare $record): void {
                        app(PreviewShares::class)->revoke($record);
                        Notification::make()->success()->title('Link revoked')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCmsPreviewShares::route('/')];
    }
}
