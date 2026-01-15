<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (!Schema::hasColumn('brands', 'icon')) {
                if (Schema::hasColumn('brands', 'icon_path')) {
                    $table->string('icon')->nullable()->after('icon_path');
                } else {
                    $table->string('icon')->nullable();
                }
            }
            if (!Schema::hasColumn('brands', 'icon_bg_color')) {
                if (Schema::hasColumn('brands', 'icon')) {
                    $table->string('icon_bg_color')->nullable()->after('icon');
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
            $table->dropColumn(['icon', 'icon_bg_color']);
        });
    }
};
