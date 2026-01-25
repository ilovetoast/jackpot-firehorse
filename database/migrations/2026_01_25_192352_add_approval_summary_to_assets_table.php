<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Run the migrations.
     * 
     * Phase AF-6: Add approval summary columns for AI-generated summaries.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->text('approval_summary')->nullable()->after('rejection_reason');
            $table->timestamp('approval_summary_generated_at')->nullable()->after('approval_summary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['approval_summary', 'approval_summary_generated_at']);
        });
    }
};
