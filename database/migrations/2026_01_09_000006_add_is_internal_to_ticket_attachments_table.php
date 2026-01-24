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
        if (!Schema::hasTable('ticket_attachments')) {
            return;
        }

        Schema::table('ticket_attachments', function (Blueprint $table) {
            if (!Schema::hasColumn('ticket_attachments', 'is_internal')) {
                $table->boolean('is_internal')->default(false)->after('mime_type')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('ticket_attachments')) {
            return;
        }

        Schema::table('ticket_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_attachments', 'is_internal')) {
                $table->dropIndex(['is_internal']);
                $table->dropColumn('is_internal');
            }
        });
    }
};
