<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Speeds up ORDER BY started_at DESC for admin AI activity lists (avoids large filesorts when combined with lean selects).
     */
    public function up(): void
    {
        if (! Schema::hasTable('ai_agent_runs')) {
            return;
        }

        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->index('started_at', 'ai_agent_runs_started_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_agent_runs')) {
            return;
        }

        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->dropIndex('ai_agent_runs_started_at_index');
        });
    }
};
