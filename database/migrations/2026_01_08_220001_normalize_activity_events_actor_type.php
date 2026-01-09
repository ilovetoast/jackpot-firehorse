<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Map short names to full class names for actor_type
        $actorTypeMap = [
            'user' => 'App\\Models\\User',
        ];

        // Normalize all actor_type values that don't contain a backslash
        foreach ($actorTypeMap as $shortName => $fullClassName) {
            DB::table('activity_events')
                ->where('actor_type', $shortName)
                ->update(['actor_type' => $fullClassName]);
        }

        // Also handle subject_type if needed
        $subjectTypeMap = [
            'user' => 'App\\Models\\User',
            'ticket' => 'App\\Models\\Ticket',
        ];

        foreach ($subjectTypeMap as $shortName => $fullClassName) {
            DB::table('activity_events')
                ->where('subject_type', $shortName)
                ->whereNotLike('subject_type', '%\\%')
                ->update(['subject_type' => $fullClassName]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Map full class names back to short names (if needed for rollback)
        $actorTypeMap = [
            'App\\Models\\User' => 'user',
        ];

        foreach ($actorTypeMap as $fullClassName => $shortName) {
            DB::table('activity_events')
                ->where('actor_type', $fullClassName)
                ->update(['actor_type' => $shortName]);
        }

        $subjectTypeMap = [
            'App\\Models\\User' => 'user',
            'App\\Models\\Ticket' => 'ticket',
        ];

        foreach ($subjectTypeMap as $fullClassName => $shortName) {
            DB::table('activity_events')
                ->where('subject_type', $fullClassName)
                ->update(['subject_type' => $shortName]);
        }
    }
};
