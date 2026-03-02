<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_research_snapshots', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_research_snapshots', 'suggestions')) {
                $table->json('suggestions')->nullable();
            }
            if (! Schema::hasColumn('brand_research_snapshots', 'coherence')) {
                $table->json('coherence')->nullable();
            }
            if (! Schema::hasColumn('brand_research_snapshots', 'alignment')) {
                $table->json('alignment')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('brand_research_snapshots', function (Blueprint $table) {
            $cols = array_filter(['suggestions', 'coherence', 'alignment'], fn ($c) => Schema::hasColumn('brand_research_snapshots', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
