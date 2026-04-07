<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE ai_agent_runs MODIFY COLUMN status ENUM('success', 'failed', 'skipped') NOT NULL");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE ai_agent_runs SET status = 'failed' WHERE status = 'skipped'");
        DB::statement("ALTER TABLE ai_agent_runs MODIFY COLUMN status ENUM('success', 'failed') NOT NULL");
    }
};
