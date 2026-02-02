<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add access_count to track how many times a download was actually delivered (ZIP or single-asset).
     */
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->unsignedInteger('access_count')->default(0)->after('zip_size_bytes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn('access_count');
        });
    }
};
