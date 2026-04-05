<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creator / prostaff — Phase 1: data foundation only (no approval/upload/UI hooks).
     */
    public function up(): void
    {
        Schema::create('prostaff_memberships', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('status')->default('active'); // active, paused, removed

            $table->integer('target_uploads')->nullable();
            $table->string('period_type')->nullable(); // month, quarter, year
            $table->date('period_start')->nullable();

            $table->boolean('requires_approval')->default(true);

            $table->json('custom_fields')->nullable();

            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prostaff_memberships');
    }
};
