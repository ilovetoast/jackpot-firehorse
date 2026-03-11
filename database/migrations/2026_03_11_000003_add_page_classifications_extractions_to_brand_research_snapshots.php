<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_research_snapshots', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_research_snapshots', 'page_classifications_json')) {
                $table->json('page_classifications_json')->nullable()->after('sections_json');
            }
            if (! Schema::hasColumn('brand_research_snapshots', 'page_extractions_json')) {
                $table->json('page_extractions_json')->nullable()->after('page_classifications_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brand_research_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('brand_research_snapshots', 'page_classifications_json')) {
                $table->dropColumn('page_classifications_json');
            }
            if (Schema::hasColumn('brand_research_snapshots', 'page_extractions_json')) {
                $table->dropColumn('page_extractions_json');
            }
        });
    }
};
