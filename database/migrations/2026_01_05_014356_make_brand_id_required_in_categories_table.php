<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, delete any categories without a brand_id (if any exist)
        DB::table('categories')->whereNull('brand_id')->delete();

        // Make brand_id required
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->change();
        });
    }
};
