<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'converted_from_ticket_id')) {
                $table->foreignId('converted_from_ticket_id')->nullable()->after('resolved_at')->constrained('tickets')->onDelete('set null');
            }
            if (!Schema::hasColumn('tickets', 'converted_at')) {
                $table->timestamp('converted_at')->nullable()->after('converted_from_ticket_id');
            }
            if (!Schema::hasColumn('tickets', 'converted_by_user_id')) {
                $table->foreignId('converted_by_user_id')->nullable()->after('converted_at')->constrained('users')->onDelete('set null');
            }
            
            // Index for bi-directional lookups
            if (!Schema::hasColumn('tickets', 'converted_from_ticket_id')) {
                $table->index('converted_from_ticket_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['converted_from_ticket_id']);
            $table->dropForeign(['converted_by_user_id']);
            $table->dropIndex(['converted_from_ticket_id']);
            $table->dropColumn(['converted_from_ticket_id', 'converted_at', 'converted_by_user_id']);
        });
    }
};
