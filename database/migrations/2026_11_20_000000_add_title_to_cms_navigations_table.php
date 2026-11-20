<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A menu can carry a heading for the site to show above it (a footer column's "Legal"). Optional:
 * a site that sets none keeps whatever heading its templates fall back to. Guarded, because a site
 * that already has the column is adopted, not migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = Tables::name('cms_navigations');

        if (Schema::hasColumn($table, 'title')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->string('title')->nullable();
        });
    }

    public function down(): void
    {
        $table = Tables::name('cms_navigations');

        if (Schema::hasColumn($table, 'title')) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('title');
            });
        }
    }
};
