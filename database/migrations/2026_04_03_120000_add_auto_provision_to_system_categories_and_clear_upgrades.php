<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_categories', function (Blueprint $table) {
            $table->boolean('auto_provision')->default(true)->after('is_hidden');
        });

        DB::table('system_categories')->update(['auto_provision' => true]);

        DB::table('categories')->where('upgrade_available', true)->update(['upgrade_available' => false]);
    }

    public function down(): void
    {
        Schema::table('system_categories', function (Blueprint $table) {
            $table->dropColumn('auto_provision');
        });
    }
};
