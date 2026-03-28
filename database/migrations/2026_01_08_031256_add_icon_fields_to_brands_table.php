<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tile background color for letter / compact displays (uploadable brand icon removed later).
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'icon_bg_color')) {
                if (Schema::hasColumn('brands', 'accent_color')) {
                    $table->string('icon_bg_color')->nullable()->after('accent_color');
                } else {
                    $table->string('icon_bg_color')->nullable();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'icon_bg_color')) {
                $table->dropColumn('icon_bg_color');
            }
        });
    }
};
