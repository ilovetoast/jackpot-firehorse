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
        Schema::table('brand_research_snapshots', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_research_snapshots', 'report')) {
                $table->json('report')->nullable()->after('alignment');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_research_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('brand_research_snapshots', 'report')) {
                $table->dropColumn('report');
            }
        });
    }
};
