<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Actions;

use Closure;
use Filagora\Cms\Models\CmsNavigation;
use Filagora\Cms\Preview\PreviewSession;
use Filagora\Cms\Preview\PreviewShares;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * "Share preview link" on the page and menu editors: creates a link that shows a chosen draft
 * variant to someone without a login. Works with `PageDrafts` and `NavigationDrafts`.
 */
final class SharePreviewLinkAction
{
    /**
     * @param  Closure(): Model  $record
     * @param  Closure(): object  $service
     */
    public static function make(Closure $record, Closure $service): Action
    {
        return Action::make('share_preview')
            ->label(__('filagora-cms::ui.share_preview_link_action.share_preview_link'))
            ->icon('heroicon-o-link')
            ->color('gray')
            ->visible(fn (): bool => $service()->variantNames($record()) !== [])
            ->modalHeading(__('filagora-cms::ui.share_preview_link_action.share_a_preview_link'))
            ->modalDescription(__('filagora-cms::ui.share_preview_link_action.anyone_with_the_link_can_see'))
            ->modalSubmitActionLabel(__('filagora-cms::ui.share_preview_link_action.create_link'))
            ->fillForm(fn (): array => ['variant' => $service()->activeVariantName($record()), 'days' => 7])
            ->schema(fn (): array => [
                Select::make('variant')
                    ->label(__('filagora-cms::ui.share_preview_link_action.draft_variant_to_show'))
                    ->options(array_combine($service()->variantNames($record()), $service()->variantNames($record())))
                    ->required(),
                TextInput::make('label')->label(__('filagora-cms::ui.share_preview_link_action.label_only_you_see_it'))->maxLength(120),
                Select::make('days')
                    ->label(__('filagora-cms::ui.share_preview_link_action.expires_after'))
                    ->options([1 => '1 day', 3 => '3 days', 7 => '7 days', 30 => '30 days'])
                    ->required(),
                Toggle::make('include_session')
                    ->label(__('filagora-cms::ui.share_preview_link_action.also_include_everything_in_my_current'))
                    ->helperText(__('filagora-cms::ui.share_preview_link_action.adds_the_menus_and_pages_in')),
            ])
            ->action(function (array $data) use ($record): void {
                $model = $record();
                $selection = ($data['include_session'] ?? false)
                    ? app(PreviewSession::class)->currentSelection()
                    : ['navigations' => [], 'pages' => []];

                if ($model instanceof CmsNavigation) {
                    $selection['navigations'][(string) $model->name] = (string) $data['variant'];
                } else {
                    $selection['pages'][(int) $model->getKey()] = (string) $data['variant'];
                }

                try {
                    $share = app(PreviewShares::class)->create(
                        auth()->user(),
                        $selection['navigations'],
                        $selection['pages'],
                        (int) $data['days'],
                        $data['label'] ?? null,
                    );
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(__('filagora-cms::ui.share_preview_link_action.cannot_create_link'))->body(collect($e->errors())->flatten()->implode(' '))->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('filagora-cms::ui.share_preview_link_action.preview_link_created'))
                    ->body($share->url() ?? 'Define the route named in filagora-cms.preview.share_route to open links. Token: '.$share->getRawOriginal('token'))
                    ->persistent()
                    ->send();
            });
    }
}
