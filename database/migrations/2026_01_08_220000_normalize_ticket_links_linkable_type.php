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
        // Map short names to full class names
        $typeMap = [
            'user' => 'App\\Models\\User',
            'ticket' => 'App\\Models\\Ticket',
            'event' => 'App\\Models\\Event',
            'error_log' => 'App\\Models\\ErrorLog',
        ];

        // Normalize all linkable_type values that don't contain a backslash
        foreach ($typeMap as $shortName => $fullClassName) {
            DB::table('ticket_links')
                ->where('linkable_type', $shortName)
                ->update(['linkable_type' => $fullClassName]);
        }

        // Also handle any other short names by trying to construct the full class name
        $links = DB::table('ticket_links')
            ->whereNotLike('linkable_type', '%\\%')
            ->get();

        foreach ($links as $link) {
            $normalized = 'App\\Models\\' . ucfirst($link->linkable_type);
            if (class_exists($normalized)) {
                DB::table('ticket_links')
                    ->where('id', $link->id)
                    ->update(['linkable_type' => $normalized]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Map full class names back to short names (if needed for rollback)
        $typeMap = [
            'App\\Models\\User' => 'user',
            'App\\Models\\Ticket' => 'ticket',
            'App\\Models\\Event' => 'event',
            'App\\Models\\ErrorLog' => 'error_log',
        ];

        foreach ($typeMap as $fullClassName => $shortName) {
            DB::table('ticket_links')
                ->where('linkable_type', $fullClassName)
                ->update(['linkable_type' => $shortName]);
        }
    }
};
