<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * logo_dark_id and logo_horizontal_id were mistakenly added as unsignedBigInteger;
     * they reference assets.id (UUID). MySQL truncates UUID strings into bigint (warning 1265).
     */
    public function up(): void
    {
        if (! Schema::hasTable('brands')) {
            return;
        }

        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'logo_dark_id')) {
                $table->uuid('logo_dark_id')->nullable()->change();
            }
            if (Schema::hasColumn('brands', 'logo_horizontal_id')) {
                $table->uuid('logo_horizontal_id')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('brands')) {
            return;
        }

        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'logo_dark_id')) {
                $table->unsignedBigInteger('logo_dark_id')->nullable()->change();
            }
            if (Schema::hasColumn('brands', 'logo_horizontal_id')) {
                $table->unsignedBigInteger('logo_horizontal_id')->nullable()->change();
            }
        });
    }
};
