<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes dedicated brand icon upload / heroicon selection (icon_path, icon_id, icon).
 * Tile + letter styling remains via icon_bg_color, icon_style, primary/secondary colors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'icon_id')) {
                $table->dropColumn('icon_id');
            }
            if (Schema::hasColumn('brands', 'icon_path')) {
                $table->dropColumn('icon_path');
            }
            if (Schema::hasColumn('brands', 'icon')) {
                $table->dropColumn('icon');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'icon_path') && Schema::hasColumn('brands', 'logo_path')) {
                $table->string('icon_path')->nullable()->after('logo_path');
            } elseif (! Schema::hasColumn('brands', 'icon_path')) {
                $table->string('icon_path')->nullable();
            }
            if (! Schema::hasColumn('brands', 'icon_id')) {
                $table->uuid('icon_id')->nullable()->after('icon_path');
            }
            if (! Schema::hasColumn('brands', 'icon')) {
                if (Schema::hasColumn('brands', 'icon_path')) {
                    $table->string('icon')->nullable()->after('icon_path');
                } else {
                    $table->string('icon')->nullable();
                }
            }
        });
    }
};
