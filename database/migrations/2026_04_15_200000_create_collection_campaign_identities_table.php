<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_campaign_identities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('collection_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('campaign_name');
            $table->string('campaign_slug')->nullable();
            $table->string('campaign_status')->default('draft');
            $table->text('campaign_goal')->nullable();
            $table->text('campaign_description')->nullable();

            $table->json('identity_payload')->nullable();

            $table->string('readiness_status')->default('incomplete');
            $table->boolean('scoring_enabled')->default(false);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_campaign_identities');
    }
};
