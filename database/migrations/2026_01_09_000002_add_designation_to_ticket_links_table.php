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
        Schema::table('ticket_links', function (Blueprint $table) {
            // Link designation for categorizing diagnostic links
            $table->string('designation')->default('related')->after('link_type'); // primary, related, duplicate
            $table->json('metadata')->nullable()->after('designation'); // Link-specific metadata
            
            // Index for filtering by designation
            $table->index('designation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticket_links', function (Blueprint $table) {
            $table->dropIndex(['designation']);
            $table->dropColumn(['designation', 'metadata']);
        });
    }
};
