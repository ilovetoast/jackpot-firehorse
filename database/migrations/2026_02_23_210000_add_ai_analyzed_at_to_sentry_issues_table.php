<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated timestamp for when AI analysis ran. Used for monthly cost accounting
 * so reanalyze/other updates (updated_at) do not shift spend to a different month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sentry_issues', function (Blueprint $table) {
            $table->timestamp('ai_analyzed_at')->nullable()->after('ai_cost');
        });
    }

    public function down(): void
    {
        Schema::table('sentry_issues', function (Blueprint $table) {
            $table->dropColumn('ai_analyzed_at');
        });
    }
};
