<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (!Schema::hasColumn('brands', 'icon_style')) {
                $table->string('icon_style', 20)->nullable()->default('subtle')->after('icon_bg_color');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('icon_style');
        });
    }
};
