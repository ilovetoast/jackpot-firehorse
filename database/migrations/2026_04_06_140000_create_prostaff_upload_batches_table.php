<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creator Phase 5: group prostaff upload alerts for approvers (batched in-app notifications).
     */
    public function up(): void
    {
        Schema::create('prostaff_upload_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prostaff_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('batch_key')->unique();

            $table->unsignedInteger('upload_count')->default(0);

            $table->foreignUuid('first_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignUuid('last_asset_id')->nullable()->constrained('assets')->nullOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prostaff_upload_batches');
    }
};
