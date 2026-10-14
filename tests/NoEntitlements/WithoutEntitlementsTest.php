<?php

declare(strict_types=1);

use Filagora\Cms\Filament\Resources\CmsNavigations\Pages\CreateCmsNavigation;
use Filagora\Cms\Filament\Resources\CmsPages\CmsPageResource;
use Filagora\Cms\Filament\Resources\CmsPages\Pages\ListCmsPages;
use Filagora\Cms\Navigation\NavigationItems;
use Filagora\Cms\Support\Abilities;
use Filagora\Cms\Tests\Support\HostAccess;
use Filagora\Cms\Tests\Support\TestUser;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Entitlements\Registry\EntitlementRegistry;
use Filament\Facades\Filament;
use Filament\Forms\Components\TagsInput;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    HostAccess::$granted = [];
});

function cmsHostUser(string ...$keys): TestUser
{
    $user = TestUser::make('Host');
    HostAccess::$granted[$user->id] = array_values($keys);

    return $user;
}

it('runs with no entitlements package loaded, needing only core', function () {
    $modules = app(ModuleRegistry::class);

    expect($modules->get('cms')->requires)->toBe(['core'])
        ->and($modules->has('entitlements'))->toBeFalse()
        ->and($modules->missingRequirements())->toBe([])
        ->and(app()->bound(EntitlementRegistry::class))->toBeFalse();
});

it('follows the host\'s own checker for the editor and preview abilities', function () {
    $editor = cmsHostUser('filagora.cms.manage');

    expect(Gate::forUser($editor)->allows(Abilities::MANAGE))->toBeTrue()
        ->and(Gate::forUser($editor)->allows(Abilities::PREVIEW))->toBeFalse()
        ->and(Gate::forUser(cmsHostUser())->allows(Abilities::MANAGE))->toBeFalse();
});

it('shows the screens to the host\'s editors only', function () {
    actingAs(cmsHostUser());
    expect(CmsPageResource::canViewAny())->toBeFalse();
    Livewire::test(ListCmsPages::class)->assertForbidden();

    actingAs(cmsHostUser('filagora.cms.manage'));
    expect(CmsPageResource::canViewAny())->toBeTrue();
    Livewire::test(ListCmsPages::class)->assertSuccessful();
});

it('shows menu items to the visitors the host\'s checker allows, by key', function () {
    $item = [['label' => 'Staff', 'url' => '/staff', 'entitlements' => ['staff.area', 'other.key']]];
    $items = fn (): array => app(NavigationItems::class)->normalize($item);

    expect($items())->toBe([]);

    actingAs(cmsHostUser());
    expect($items())->toBe([]);

    actingAs(cmsHostUser('other.key'));
    expect($items())->toHaveCount(1);
});

it('lets the menu editor type the keys a link is visible for', function () {
    actingAs(cmsHostUser('filagora.cms.manage'));

    Livewire::test(CreateCmsNavigation::class)
        ->fillForm(['name' => 'header', 'items' => [['label' => 'Staff', 'type' => 'url', 'url' => '/staff', 'target' => '_self', 'entitlements' => ['staff.area']]]])
        ->assertFormFieldExists('items.0.entitlements', fn ($field) => $field instanceof TagsInput)
        ->call('create')
        ->assertHasNoFormErrors();
});
