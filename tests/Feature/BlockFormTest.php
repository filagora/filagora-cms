<?php

declare(strict_types=1);

use Filagora\Cms\BlockTypes\BlockType;
use Filagora\Cms\BlockTypes\BlockTypeRegistry;
use Filagora\Cms\BlockTypes\GenericBlockTypes;
use Filagora\Cms\Filament\Blocks\BlockForm;
use Filagora\Cms\Filament\Resources\CmsPages\Pages\EditCmsPage;
use Filagora\Cms\Filament\Resources\CmsPages\RelationManagers\BlocksRelationManager;
use Filagora\Cms\Models\CmsPage;
use Filagora\Cms\Services\PageDrafts;
use Filagora\Cms\Tests\Support\TestUser;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $user = TestUser::make('Editor');
    UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => 'filagora.cms.manage', 'grant_type' => 'manual']);
    actingAs($user);
});

it('turns a stored typed block into form state, upgrading and defaulting', function () {
    $form = BlockForm::fill(['type' => 'divider', 'name' => 'Line', 'data' => null]);
    $legacy = BlockForm::fill(['type' => 'stat_strip', 'data' => ['title' => 'T', 'items' => ['Plain']]]);

    expect($form['data'])->toBe(['style' => 'solid'])
        ->and($form['data_json'])->toBe('')
        ->and($legacy['data']['items'][0]['headline'])->toBe('Plain');
});

it('turns a block with no form into a JSON box and back', function () {
    app(BlockTypeRegistry::class)->register(new BlockType('profile_only', 'Profile only'));

    $form = BlockForm::fill(['type' => 'profile_only', 'data' => ['a' => ['b' => 'é']]]);
    $stored = BlockForm::prepare($form);
    $unknown = BlockForm::fill(['type' => 'never_heard_of', 'data' => null]);

    expect($form['data_json'])->toContain('"b": "é"')
        ->and($form['data'])->toBe([])
        ->and($stored['data'])->toBe(['a' => ['b' => 'é']])
        ->and($stored)->not->toHaveKey('data_json')
        ->and($unknown['data_json'])->toBe('')
        ->and(BlockForm::prepare(['type' => 'never_heard_of', 'data_json' => ''])['data'])->toBeNull()
        ->and(BlockForm::prepare(['type' => 'never_heard_of', 'data_json' => '[1,2]'])['data'])->toBe([1, 2]);
});

it('saves a typed block by cleaning the form state, ignoring a leftover JSON box', function () {
    $stored = BlockForm::prepare(['type' => 'divider', 'name' => 'Line', 'data' => ['style' => 'dashed', 'other' => 'dropped'], 'data_json' => '{"stale": true}']);

    expect($stored['data'])->toBe(['style' => 'dashed'])
        ->and($stored)->not->toHaveKey('data_json');
});

it('builds a form for every generic type that opens, lists its fields and saves what it shows', function (string $key) {
    $page = CmsPage::create(['slug' => 'p', 'title' => 'P'])->refresh();
    app(PageDrafts::class)->ensureDraft($page);
    $type = app(BlockTypeRegistry::class)->get($key);
    $values = $type->hydrate([]);

    $manager = Livewire::test(BlocksRelationManager::class, ['ownerRecord' => $page, 'pageClass' => EditCmsPage::class]);
    $manager->callTableAction('create', data: ['type' => $key, 'name' => $key, 'sort_order' => 1, 'data' => $values])->assertHasNoTableActionErrors();

    expect($page->draftBlocks()->where('name', $key)->sole()->data)->toBe($type->normalize($values));
})->with(array_map(fn (BlockType $type): string => $type->key, GenericBlockTypes::all()));

it('starts a block from its type\'s defaults when the type is chosen', function () {
    $page = CmsPage::create(['slug' => 'p', 'title' => 'P'])->refresh();
    app(PageDrafts::class)->ensureDraft($page);

    Livewire::test(BlocksRelationManager::class, ['ownerRecord' => $page, 'pageClass' => EditCmsPage::class])
        ->mountTableAction('create')
        ->fillForm(['type' => 'cta_band'])
        ->assertSchemaStateSet(['data.theme' => 'dark', 'data.title' => '']);
});
