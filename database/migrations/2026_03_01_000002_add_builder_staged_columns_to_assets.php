<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brand Guidelines Builder v1: Additive only.
     * Staged assets for builder (logo refs, photography refs, textures) — hidden from main grid.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('builder_staged')->default(false)->after('deleted_by_user_id');
            $table->string('builder_context', 64)->nullable()->after('builder_staged');
            $table->string('source', 32)->nullable()->after('builder_context');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->index('builder_staged');
            $table->index('builder_context');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['assets_builder_staged_index']);
            $table->dropIndex(['assets_builder_context_index']);
            $table->dropIndex(['assets_source_index']);
        });
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['builder_staged', 'builder_context', 'source']);
        });
    }
};
