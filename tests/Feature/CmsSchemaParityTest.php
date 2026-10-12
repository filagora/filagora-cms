<?php

declare(strict_types=1);

use ForumKit\Core\Schema\Adopter;
use ForumKit\Core\Schema\AdoptionStatus;
use ForumKit\Core\Schema\SchemaExtension;
use ForumKit\Core\Schema\SchemaRegistry;
use ForumKit\Core\Schema\TableShape;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const CMS_TABLES = [
    'cms_pages', 'cms_blocks', 'cms_block_versions', 'cms_page_versions', 'cms_page_drafts', 'cms_page_draft_variants',
    'cms_navigations', 'cms_navigation_versions', 'cms_navigation_drafts', 'cms_navigation_draft_variants',
    'cms_tags', 'cms_page_tag', 'cms_media_assets', 'cms_preview_shares',
];
const CMS_MIGRATION = '2026_10_12_100000_create_forumkit_cms_tables';

function cmsMigration(): object
{
    return require __DIR__.'/../../database/migrations/'.CMS_MIGRATION.'.php';
}

function cmsSnapshot()
{
    return app(SchemaRegistry::class)->get('cms');
}

afterEach(function () {
    config(['forumkit.table_prefix' => '']);
    cmsMigration()->down();
    config(['forumkit.table_prefix' => 'forumkit_']);
    cmsMigration()->down();
    cmsMigration()->up();
});

it('ships a snapshot of the fourteen tables that matches the migration, column order included', function () {
    expect(cmsSnapshot())->not->toBeNull()
        ->and(array_keys(cmsSnapshot()->tables))->toBe(CMS_TABLES)
        ->and(cmsSnapshot()->migrationNames())->toBe([CMS_MIGRATION]);

    foreach (cmsSnapshot()->tables as $base => $expected) {
        expect(TableShape::inspect($base)->differencesFrom($expected, compareColumnOrder: true))->toBe([], $base);
    }
});

it('pins the details a site depends on', function () {
    $pages = TableShape::inspect('cms_pages');
    $blocks = TableShape::inspect('cms_blocks');

    expect($pages->columns['status']['default'])->toBe('draft')
        ->and(array_keys($pages->indexes))->toEqualCanonicalizing(['primary', 'cms_pages_slug_unique', 'cms_pages_custom_route_unique', 'cms_pages_preview_token_unique', 'cms_pages_status_index', 'cms_pages_published_at_index'])
        ->and($blocks->columns['type']['nullable'])->toBeFalse()
        ->and($blocks->columns['data']['type'])->toBe('longtext')
        ->and(array_keys($blocks->foreignKeys))->toEqualCanonicalizing(['cms_blocks_cms_page_id_foreign', 'cms_blocks_live_block_id_foreign'])
        ->and(TableShape::inspect('cms_page_versions')->columns['created_at']['default'])->not->toBeNull()
        ->and(TableShape::inspect('cms_page_drafts')->columns['variant_name']['default'])->toBe('Main')
        ->and(TableShape::inspect('cms_preview_shares')->columns['open_count']['default'])->toBe('0')
        ->and(TableShape::inspect('cms_media_assets')->columns['disk']['default'])->toBe('public');
});

it('keeps the long identifiers within the database limit under the default prefix', function () {
    foreach (CMS_TABLES as $base) {
        $shape = TableShape::inspect($base);

        foreach ([...array_keys($shape->indexes), ...array_keys($shape->foreignKeys)] as $name) {
            expect(strlen('forumkit_'.$name))->toBeLessThanOrEqual(64, $name);
        }
    }
});

/**
 * The tables as a site that already has them: no prefix, migration not recorded, plus the one
 * extra column the support site added to its pages.
 */
function existingCmsTables(bool $withExtra): void
{
    config(['forumkit.table_prefix' => '']);
    cmsMigration()->up();
    DB::table('migrations')->where('migration', CMS_MIGRATION)->delete();

    if ($withExtra) {
        Schema::table('cms_pages', fn (Blueprint $table) => $table->unsignedBigInteger('legacy_content_id')->nullable()->unique('cms_pages_legacy_content_id_unique'));
    }
}

it('adopts the tables of an existing site and records the migration', function () {
    config(['forumkit.table_prefix' => '']);
    cmsMigration()->down();
    config(['forumkit.table_prefix' => 'forumkit_']);
    cmsMigration()->down();
    existingCmsTables(false);

    $plan = app(Adopter::class)->planFor(cmsSnapshot());

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toBe([CMS_MIGRATION]);

    $this->artisan('forumkit:adopt', ['--package' => 'cms'])->assertExitCode(0);

    expect(DB::table('migrations')->where('migration', CMS_MIGRATION)->exists())->toBeTrue();
});

it('names an undeclared extra column, and accepts it once a profile declares it exactly', function () {
    config(['forumkit.table_prefix' => '']);
    cmsMigration()->down();
    config(['forumkit.table_prefix' => 'forumkit_']);
    cmsMigration()->down();
    existingCmsTables(true);

    $plan = app(Adopter::class)->planFor(cmsSnapshot());

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toEqualCanonicalizing([
            'cms_pages: column legacy_content_id is not expected',
            'cms_pages: index cms_pages_legacy_content_id_unique is not expected',
        ]);

    app(SchemaRegistry::class)->extend(new SchemaExtension('site', 'cms', [
        'cms_pages' => [
            'columns' => ['legacy_content_id' => ['type' => 'bigint unsigned', 'nullable' => true, 'default' => null, 'collation' => null, 'auto_increment' => false, 'generation' => null]],
            'indexes' => ['cms_pages_legacy_content_id_unique' => ['columns' => ['legacy_content_id'], 'unique' => true, 'primary' => false, 'type' => 'btree']],
        ],
    ]));

    expect(app(Adopter::class)->planFor(cmsSnapshot())->status)->toBe(AdoptionStatus::Adoptable);
});

it('refuses a site that lost an index or a foreign key', function () {
    config(['forumkit.table_prefix' => '']);
    cmsMigration()->down();
    config(['forumkit.table_prefix' => 'forumkit_']);
    cmsMigration()->down();
    existingCmsTables(false);
    Schema::table('cms_blocks', fn (Blueprint $table) => $table->dropIndex('cms_blocks_is_draft_index'));
    Schema::table('cms_page_drafts', fn (Blueprint $table) => $table->dropForeign('cms_page_drafts_updated_by_id_foreign'));

    $plan = app(Adopter::class)->planFor(cmsSnapshot());

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and(implode('; ', $plan->differences))->toContain('cms_blocks: index cms_blocks_is_draft_index is missing')
        ->and(implode('; ', $plan->differences))->toContain('cms_page_drafts_updated_by_id_foreign');
});
