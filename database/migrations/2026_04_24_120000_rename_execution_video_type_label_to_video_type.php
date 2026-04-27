<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * UI label only: field key remains execution_video_type.
     */
    public function up(): void
    {
        DB::table('metadata_fields')
            ->where('key', 'execution_video_type')
            ->update([
                'system_label' => 'Video Type',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('metadata_fields')
            ->where('key', 'execution_video_type')
            ->update([
                'system_label' => 'Execution Video Type',
                'updated_at' => now(),
            ]);
    }
};
