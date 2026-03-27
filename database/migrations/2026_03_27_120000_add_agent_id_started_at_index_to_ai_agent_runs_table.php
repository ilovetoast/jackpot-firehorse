<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Covers: WHERE agent_id = ? ORDER BY started_at DESC LIMIT 1
 * Without this, MySQL may filesort all rows for that agent (sort buffer / 1038 errors on large tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_agent_runs')) {
            return;
        }

        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->index(
                ['agent_id', 'started_at'],
                'ai_agent_runs_agent_id_started_at_index'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_agent_runs')) {
            return;
        }

        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->dropIndex('ai_agent_runs_agent_id_started_at_index');
        });
    }
};
