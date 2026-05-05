<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_ai_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->string('response_kind', 32);
            $table->json('matched_action_keys')->nullable();
            $table->unsignedSmallInteger('best_score')->default(0);
            $table->string('confidence', 32)->nullable();
            $table->string('recommended_action_key', 191)->nullable();
            $table->unsignedBigInteger('agent_run_id')->nullable();
            $table->decimal('cost', 14, 8)->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->string('feedback_rating', 16)->nullable();
            $table->text('feedback_note')->nullable();
            $table->timestamp('feedback_submitted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index('response_kind');
            $table->index(['feedback_rating', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_ai_questions');
    }
};
