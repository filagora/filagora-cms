<?php

declare(strict_types=1);

use Filagora\Cms\Navigation\NavigationItems;
use Filagora\Cms\Navigation\NavigationLinkTypes;
use Filagora\Cms\Tests\Support\TestUser;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Entitlements\Services\EntitlementResolver;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\actingAs;

function nav(array $items): array
{
    return app(NavigationItems::class)->normalize($items);
}

it('keeps a plain link and fills in the defaults', function () {
    $items = nav([['label' => ' Home ', 'url' => '/']]);

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['label' => 'Home', 'url' => '/', 'type' => 'url', 'target' => '_self', 'entitlements' => [], 'reference' => '', 'children' => []]);
});

it('allows relative paths and http(s) only', function (string $given, string $kept) {
    Log::spy();
    $items = nav([['label' => 'X', 'url' => $given], ['label' => 'Keeper', 'url' => '/keeper']]);

    expect($items[0]['url'] ?? null)->toBe($kept === '#' ? '/keeper' : $kept);
})->with([
    'relative' => ['/docs', '/docs'],
    'https' => ['https://example.test/a', 'https://example.test/a'],
    'http' => ['http://example.test', 'http://example.test'],
    'protocol relative' => ['//evil.test', '#'],
    'javascript' => ['javascript:alert(1)', '#'],
    'mailto' => ['mailto:a@example.test', '#'],
    'data' => ['data:text/html,x', '#'],
    'no scheme word' => ['example.test/a', '#'],
]);

it('drops a link to nowhere that has nothing under it, but keeps one that has children', function () {
    $items = nav([
        ['label' => 'Empty', 'url' => '#'],
        ['label' => 'Blank'],
        ['label' => 'Group', 'url' => '#', 'children' => [['label' => 'Child', 'url' => '/c']]],
    ]);

    expect(array_column($items, 'label'))->toBe(['Group'])
        ->and($items[0]['children'][0]['url'])->toBe('/c');
});

it('limits the target to the four allowed values', function () {
    $items = nav([['label' => 'A', 'url' => '/a', 'target' => '_blank'], ['label' => 'B', 'url' => '/b', 'target' => 'popup']]);

    expect($items[0]['target'])->toBe('_blank')
        ->and($items[1]['target'])->toBe('_self');
});

it('rescues a URL that was stored in the target field', function () {
    $items = nav([['label' => 'A', 'target' => '/saved-here']]);

    expect($items[0]['url'])->toBe('/saved-here')
        ->and($items[0]['target'])->toBe('_self');
});

it('ignores things that are not items, and anything that is not a list', function () {
    expect(nav(['junk', 3, null, ['label' => 'Ok', 'url' => '/ok']]))->toHaveCount(1)
        ->and(app(NavigationItems::class)->normalize('nope'))->toBe(app(NavigationItems::class)->normalize(null))->toBe([]);
});

it('hides items from visitors without the entitlement, and shows them to holders of any one', function () {
    $item = [['label' => 'Staff', 'url' => '/staff', 'entitlements' => [' staff.area ', 'other.key', '']]];

    expect(nav($item))->toBe([]);

    $user = TestUser::make();
    actingAs($user);
    expect(nav($item))->toBe([]);

    UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => 'other.key', 'grant_type' => 'manual']);
    app()->forgetInstance(EntitlementResolver::class);

    $shown = nav($item);
    expect($shown)->toHaveCount(1)->and($shown[0]['entitlements'])->toBe(['staff.area', 'other.key']);
});

it('hides the children a visitor may not see, and drops a parent left empty', function () {
    $items = nav([['label' => 'Parent', 'url' => '#', 'children' => [['label' => 'Secret', 'url' => '/s', 'entitlements' => ['x']]]]]);

    expect($items)->toBe([]);
});

it('falls back to a plain link for a kind nobody registered', function () {
    $items = nav([['label' => 'A', 'type' => 'app_page', 'reference' => 'profile', 'url' => '/fallback']]);

    expect($items[0]['type'])->toBe('url')
        ->and($items[0]['url'])->toBe('/fallback');
});

it('resolves a registered kind through its resolver, forcing the same-window target', function () {
    Log::spy();
    app(NavigationLinkTypes::class)->register('app_page', fn (string $reference): ?string => ['profile' => '/me'][$reference] ?? null);

    $items = nav([
        ['label' => 'Me', 'type' => 'app_page', 'reference' => 'profile', 'target' => '_blank'],
        ['label' => 'Bad', 'type' => 'app_page', 'reference' => 'nope'],
        ['label' => 'Parent', 'type' => 'app_page', 'reference' => 'nope', 'children' => [['label' => 'Kid', 'url' => '/kid']]],
    ]);

    expect(array_column($items, 'label'))->toBe(['Me', 'Parent'])
        ->and($items[0])->toMatchArray(['url' => '/me', 'target' => '_self'])
        ->and($items[1]['url'])->toBe('#');
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('keeps an action item that resolves to #, and drops one whose reference is refused', function () {
    Log::spy();
    app(NavigationLinkTypes::class)->register('action', fn (string $reference): ?string => $reference === 'logout' ? '#' : null, keepsPlaceholder: true);

    $items = nav([
        ['label' => 'Log out', 'type' => 'action', 'reference' => 'logout'],
        ['label' => 'Bad', 'type' => 'action', 'reference' => 'shutdown'],
    ]);

    expect(array_column($items, 'label'))->toBe(['Log out'])
        ->and($items[0]['url'])->toBe('#');
});
