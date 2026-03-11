<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_research_snapshots', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_research_snapshots', 'sections_json')) {
                $table->json('sections_json')->nullable()->after('report');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brand_research_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('brand_research_snapshots', 'sections_json')) {
                $table->dropColumn('sections_json');
            }
        });
    }
};
