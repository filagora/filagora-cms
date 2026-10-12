<?php

declare(strict_types=1);

use ForumKit\Core\Support\Tables;
use ForumKit\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CMS tables, in the shape of the site they were extracted from, index for index. Columns
 * that point at a user are nullable and set null on delete, so removing a user never removes
 * content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('cms_pages'), function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('custom_route')->nullable()->unique();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->string('preview_token')->nullable()->unique();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->timestamps();
        });

        Schema::create(Tables::name('cms_blocks'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cms_page_id')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->string('type');
            $table->string('name')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_reusable')->default(false)->index();
            $table->boolean('is_published')->default(false)->index();
            $table->boolean('is_draft')->default(false)->index();
            $table->unsignedBigInteger('live_block_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->foreign('cms_page_id')->references('id')->on(Tables::name('cms_pages'))->cascadeOnDelete();
            $table->foreign('live_block_id')->references('id')->on(Tables::name('cms_blocks'))->nullOnDelete();
        });

        Schema::create(Tables::name('cms_block_versions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cms_block_id');
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->string('change_summary')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['cms_block_id', 'version_number']);
            $table->foreign('cms_block_id')->references('id')->on(Tables::name('cms_blocks'))->cascadeOnDelete();
            $table->foreign('created_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('cms_page_versions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cms_page_id');
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->string('change_summary')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['cms_page_id', 'version_number'], Tables::name('cms_page_versions').'_page_version_unique');
            $table->index(['cms_page_id', 'created_at'], Tables::name('cms_page_versions').'_page_created_at_index');
            $table->foreign('cms_page_id')->references('id')->on(Tables::name('cms_pages'))->cascadeOnDelete();
            $table->foreign('created_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('cms_page_drafts'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cms_page_id')->unique();
            $table->string('variant_name', 100)->default('Main');
            $table->json('payload');
            $table->unsignedBigInteger('base_version_id')->nullable();
            $table->boolean('is_dirty')->default(false)->index();
            $table->timestamp('scheduled_publish_at')->nullable()->index();
            $table->string('scheduled_summary')->nullable();
            $table->unsignedBigInteger('scheduled_by_id')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->timestamps();

            $table->foreign('cms_page_id')->references('id')->on(Tables::name('cms_pages'))->cascadeOnDelete();
            $table->foreign('scheduled_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
            $table->foreign('updated_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('cms_page_draft_variants'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cms_page_id');
            $table->string('name', 100);
            $table->json('snapshot');
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->timestamps();

            $table->unique(['cms_page_id', 'name'], Tables::name('cms_page_draft_variants').'_page_name_unique');
            $table->foreign('cms_page_id')->references('id')->on(Tables::name('cms_pages'))->cascadeOnDelete();
            $table->foreign('updated_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('cms_navigations'), function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->json('items')->nullable();
            $table->timestamps();
        });

        Schema::create(Tables::name('cms_navigation_versions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cms_navigation_id');
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->string('change_summary')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['cms_navigation_id', 'version_number'], Tables::name('cms_navigation_versions').'_navigation_version_unique');
            $table->foreign('cms_navigation_id')->references('id')->on(Tables::name('cms_navigations'))->cascadeOnDelete();
            $table->foreign('created_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('cms_navigation_drafts'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cms_navigation_id')->unique();
            $table->string('variant_name', 100)->default('Main');
            $table->json('payload');
            $table->unsignedBigInteger('base_version_id')->nullable();
            $table->boolean('is_dirty')->default(false)->index();
            $table->timestamp('scheduled_publish_at')->nullable()->index();
            $table->string('scheduled_summary')->nullable();
            $table->unsignedBigInteger('scheduled_by_id')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->timestamps();

            $table->foreign('cms_navigation_id')->references('id')->on(Tables::name('cms_navigations'))->cascadeOnDelete();
            $table->foreign('scheduled_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
            $table->foreign('updated_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('cms_navigation_draft_variants'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cms_navigation_id');
            $table->string('name', 100);
            $table->json('snapshot');
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->timestamps();

            $table->unique(['cms_navigation_id', 'name'], Tables::name('cms_navigation_draft_variants').'_navigation_name_unique');
            $table->foreign('cms_navigation_id')->references('id')->on(Tables::name('cms_navigations'))->cascadeOnDelete();
            $table->foreign('updated_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('cms_tags'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create(Tables::name('cms_page_tag'), function (Blueprint $table): void {
            $table->unsignedBigInteger('cms_page_id');
            $table->unsignedBigInteger('cms_tag_id');

            $table->primary(['cms_page_id', 'cms_tag_id']);
            $table->foreign('cms_page_id')->references('id')->on(Tables::name('cms_pages'))->cascadeOnDelete();
            $table->foreign('cms_tag_id')->references('id')->on(Tables::name('cms_tags'))->cascadeOnDelete();
        });

        Schema::create(Tables::name('cms_media_assets'), function (Blueprint $table): void {
            $table->id();
            $table->string('disk')->default('public');
            $table->string('path')->unique();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('alt_text')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->timestamps();

            $table->foreign('uploaded_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('cms_preview_shares'), function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('label', 120)->nullable();
            $table->json('items');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();

            $table->foreign('created_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'cms_preview_shares', 'cms_media_assets', 'cms_page_tag', 'cms_tags',
            'cms_navigation_draft_variants', 'cms_navigation_drafts', 'cms_navigation_versions', 'cms_navigations',
            'cms_page_draft_variants', 'cms_page_drafts', 'cms_page_versions', 'cms_block_versions',
            'cms_blocks', 'cms_pages',
        ] as $table) {
            Schema::dropIfExists(Tables::name($table));
        }
    }
};
