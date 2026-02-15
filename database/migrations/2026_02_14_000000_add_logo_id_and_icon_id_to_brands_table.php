<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Logo/icon can reference an asset (logo_id/icon_id) or legacy path (logo_path/icon_path).
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->uuid('logo_id')->nullable()->after('logo_path');
            $table->uuid('icon_id')->nullable()->after('icon_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['logo_id', 'icon_id']);
        });
    }
};
