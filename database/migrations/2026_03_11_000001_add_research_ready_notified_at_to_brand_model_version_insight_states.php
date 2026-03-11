<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_model_version_insight_states', function (Blueprint $table) {
            $table->timestamp('research_ready_notified_at')->nullable()->after('viewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('brand_model_version_insight_states', function (Blueprint $table) {
            $table->dropColumn('research_ready_notified_at');
        });
    }
};
