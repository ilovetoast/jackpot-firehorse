<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Category-level settings (e.g. EBI toggles); nullable JSON blob.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'settings')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->json('settings')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('categories', 'settings')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('settings');
            });
        }
    }
};
