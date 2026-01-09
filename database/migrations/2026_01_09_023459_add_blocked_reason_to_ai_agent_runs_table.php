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
     * Adds blocked_reason column to ai_agent_runs table.
     * Stores reason if execution was blocked by hard budget limit.
     */
    public function up(): void
    {
        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->text('blocked_reason')->nullable()->after('error_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->dropColumn('blocked_reason');
        });
    }
};
