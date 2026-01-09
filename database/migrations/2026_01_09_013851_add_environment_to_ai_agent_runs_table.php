<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Run the migrations.
     * 
     * Adds environment field to ai_agent_runs table for environment-aware filtering.
     * Populated from APP_ENV when run is created.
     */
    public function up(): void
    {
        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->string('environment')->nullable()->after('triggering_context')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->dropIndex(['environment']);
            $table->dropColumn('environment');
        });
    }
};
