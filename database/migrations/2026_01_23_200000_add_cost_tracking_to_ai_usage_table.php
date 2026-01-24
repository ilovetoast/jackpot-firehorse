<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds cost tracking columns to ai_usage table (Phase I.3).
     * These columns are nullable to maintain backward compatibility.
     */
    public function up(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->decimal('cost_usd', 10, 6)->nullable()->after('call_count');
            $table->integer('tokens_in')->nullable()->after('cost_usd');
            $table->integer('tokens_out')->nullable()->after('tokens_in');
            $table->string('model', 100)->nullable()->after('tokens_out');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->dropColumn(['cost_usd', 'tokens_in', 'tokens_out', 'model']);
        });
    }
};
