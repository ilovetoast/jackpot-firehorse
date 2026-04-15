<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_campaign_identities', function (Blueprint $table) {
            $table->char('featured_asset_id', 36)->nullable()->after('scoring_enabled');

            $table->foreign('featured_asset_id')
                ->references('id')
                ->on('assets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('collection_campaign_identities', function (Blueprint $table) {
            $table->dropForeign(['featured_asset_id']);
            $table->dropColumn('featured_asset_id');
        });
    }
};
