<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `kind` column to composition_versions so we can distinguish
 * manual (named/explicit) checkpoints from rolling autosave snapshots.
 * The column is indexed because we prune by kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('composition_versions', function (Blueprint $table) {
            $table->string('kind', 16)->default('manual')->after('label');
            $table->index(['composition_id', 'kind'], 'composition_versions_composition_id_kind_index');
        });
    }

    public function down(): void
    {
        Schema::table('composition_versions', function (Blueprint $table) {
            $table->dropIndex('composition_versions_composition_id_kind_index');
            $table->dropColumn('kind');
        });
    }
};
