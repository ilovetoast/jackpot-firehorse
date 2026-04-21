<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GDPR Art. 21 — objection to processing for marketing / lead data held in {@see \App\Models\ContactLead}.
 * Mirrors the `unsubscribed_at` pattern: public self-service POST sets this timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_leads', function (Blueprint $table) {
            $table->timestamp('processing_objected_at')->nullable()->after('unsubscribed_at');
        });
    }

    public function down(): void
    {
        Schema::table('contact_leads', function (Blueprint $table) {
            $table->dropColumn('processing_objected_at');
        });
    }
};
