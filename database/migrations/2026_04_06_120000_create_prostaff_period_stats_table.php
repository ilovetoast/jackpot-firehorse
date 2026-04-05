<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creator Phase 4: prostaff upload performance counters per calendar period (no asset aggregation).
     */
    public function up(): void
    {
        Schema::create('prostaff_period_stats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('prostaff_membership_id')
                ->constrained('prostaff_memberships')
                ->cascadeOnDelete();

            $table->string('period_type'); // month, quarter, year

            $table->date('period_start');
            $table->date('period_end');

            $table->unsignedInteger('target_uploads')->nullable();
            $table->unsignedInteger('actual_uploads')->default(0);

            $table->decimal('completion_percentage', 5, 2)->default(0);

            $table->timestamp('last_calculated_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['prostaff_membership_id', 'period_type', 'period_start'],
                'prostaff_period_stats_membership_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prostaff_period_stats');
    }
};
