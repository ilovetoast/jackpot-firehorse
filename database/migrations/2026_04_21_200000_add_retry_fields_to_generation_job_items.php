<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_job_items', function (Blueprint $table) {
            $table->foreignId('retried_from_item_id')->nullable()->after('generation_job_id')->constrained('generation_job_items')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('generation_job_items', function (Blueprint $table) {
            $table->dropForeign(['retried_from_item_id']);
            $table->dropColumn(['retried_from_item_id', 'superseded_at']);
        });
    }
};
