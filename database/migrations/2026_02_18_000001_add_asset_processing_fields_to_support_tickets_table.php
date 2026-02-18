<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add asset processing ticket support to support_tickets.
 *
 * Enables source_type='asset', source_id, payload (diagnostic snapshot), auto_created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('source_type', 50)->nullable()->after('alert_candidate_id');
            $table->uuid('source_id')->nullable()->after('source_type');
            $table->json('payload')->nullable()->after('description');
            $table->boolean('auto_created')->default(false)->after('source');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id', 'payload', 'auto_created']);
        });
    }
};
