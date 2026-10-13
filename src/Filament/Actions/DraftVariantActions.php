<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Actions;

use Closure;
use Filagora\Cms\Exceptions\DraftOperationFailed;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The "Variant" menu on the page and menu editors: switch between named drafts, start a new one,
 * rename the current one or delete a parked one. Works with `PageDrafts` and `NavigationDrafts`,
 * which share the same variant methods.
 */
final class DraftVariantActions
{
    /**
     * @param  Closure(): Model  $record
     * @param  Closure(): object  $service
     * @param  Closure(): void  $afterChange  Refreshes the editor once the working draft has changed.
     */
    public static function make(Closure $record, Closure $service, Closure $afterChange): ActionGroup
    {
        $names = fn (): array => $service()->variantNames($record());
        $active = fn (): ?string => $service()->activeVariantName($record());
        $parked = fn (): array => array_values(array_diff($names(), array_filter([$active()])));
        $options = fn (array $list): array => array_combine($list, $list);
        $run = function (Closure $change, string $success) use ($afterChange): void {
            try {
                $change();
            } catch (ValidationException $e) {
                Notification::make()->danger()->title('Cannot change variants')->body(collect($e->errors())->flatten()->implode(' '))->send();

                return;
            } catch (DraftOperationFailed $e) {
                Notification::make()->danger()->title('Cannot change variants')->body($e->getMessage())->send();

                return;
            }

            $afterChange();
            Notification::make()->success()->title($success)->send();
        };

        return ActionGroup::make([
            Action::make('switch_variant')
                ->label('Switch variant')
                ->icon('heroicon-o-arrows-right-left')
                ->visible(fn (): bool => $parked() !== [])
                ->modalDescription('The current draft is kept as a variant and the one you pick becomes the working draft.')
                ->schema(fn (): array => [
                    Select::make('name')->label('Variant')->options($options($parked()))->required(),
                ])
                ->action(fn (array $data) => $run(
                    fn () => $service()->switchVariant($record(), (string) $data['name'], auth()->user()),
                    'Switched variant',
                )),
            Action::make('new_variant')
                ->label('New variant')
                ->icon('heroicon-o-plus')
                ->modalDescription('The current draft is kept as a variant and the new one becomes the working draft.')
                ->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(100),
                    Toggle::make('from_live')->label('Start from the live version instead of copying the current draft'),
                ])
                ->action(fn (array $data) => $run(
                    fn () => $service()->createVariant($record(), (string) $data['name'], (bool) ($data['from_live'] ?? false), auth()->user()),
                    'Variant created',
                )),
            Action::make('rename_variant')
                ->label('Rename this variant')
                ->icon('heroicon-o-pencil')
                ->visible(fn (): bool => $active() !== null)
                ->fillForm(fn (): array => ['name' => $active()])
                ->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(100),
                ])
                ->action(fn (array $data) => $run(
                    fn () => $service()->renameVariant($record(), (string) $active(), (string) $data['name']),
                    'Variant renamed',
                )),
            Action::make('delete_variant')
                ->label('Delete a variant')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => $parked() !== [])
                ->requiresConfirmation()
                ->modalDescription('Permanently deletes the chosen variant. The working draft is not affected.')
                ->schema(fn (): array => [
                    Select::make('name')->label('Variant')->options($options($parked()))->required(),
                ])
                ->action(fn (array $data) => $run(
                    fn () => $service()->deleteVariant($record(), (string) $data['name']),
                    'Variant deleted',
                )),
        ])
            ->label(fn (): string => 'Variant: '.($active() ?? 'none yet'))
            ->icon('heroicon-o-square-3-stack-3d')
            ->color('gray')
            ->button();
    }
}
