<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add conversion columns to tickets table if missing.
 * The original conversion migration (2026_01_08_213653) runs before create_tickets_table,
 * so it no-ops; this migration ensures the columns exist after tickets is created.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'converted_from_ticket_id')) {
                $table->foreignId('converted_from_ticket_id')->nullable()->after('resolved_at')->constrained('tickets')->onDelete('set null');
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'converted_at')) {
                $table->timestamp('converted_at')->nullable()->after('converted_from_ticket_id');
            }
            if (! Schema::hasColumn('tickets', 'converted_by_user_id')) {
                $table->foreignId('converted_by_user_id')->nullable()->after('converted_at')->constrained('users')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'converted_from_ticket_id')) {
                $table->dropForeign(['converted_from_ticket_id']);
            }
            if (Schema::hasColumn('tickets', 'converted_by_user_id')) {
                $table->dropForeign(['converted_by_user_id']);
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('tickets', 'converted_from_ticket_id')) {
                $columns[] = 'converted_from_ticket_id';
            }
            if (Schema::hasColumn('tickets', 'converted_at')) {
                $columns[] = 'converted_at';
            }
            if (Schema::hasColumn('tickets', 'converted_by_user_id')) {
                $columns[] = 'converted_by_user_id';
            }
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
