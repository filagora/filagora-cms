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
            ->label('Share preview link')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->visible(fn (): bool => $service()->variantNames($record()) !== [])
            ->modalHeading('Share a preview link')
            ->modalDescription('Anyone with the link can see this draft, without logging in, until it expires or you revoke it from Preview links. Visitors still see the published site.')
            ->modalSubmitActionLabel('Create link')
            ->fillForm(fn (): array => ['variant' => $service()->activeVariantName($record()), 'days' => 7])
            ->schema(fn (): array => [
                Select::make('variant')
                    ->label('Draft variant to show')
                    ->options(array_combine($service()->variantNames($record()), $service()->variantNames($record())))
                    ->required(),
                TextInput::make('label')->label('Label (only you see it)')->maxLength(120),
                Select::make('days')
                    ->label('Expires after')
                    ->options([1 => '1 day', 3 => '3 days', 7 => '7 days', 30 => '30 days'])
                    ->required(),
                Toggle::make('include_session')
                    ->label('Also include everything in my current preview')
                    ->helperText('Adds the menus and pages in your preview, each at its current working draft.'),
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
                    Notification::make()->danger()->title('Cannot create link')->body(collect($e->errors())->flatten()->implode(' '))->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Preview link created')
                    ->body($share->url() ?? 'Define the route named in filagora-cms.preview.share_route to open links. Token: '.$share->getRawOriginal('token'))
                    ->persistent()
                    ->send();
            });
    }
}
