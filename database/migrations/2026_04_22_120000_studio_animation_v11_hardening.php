<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_animation_jobs', function (Blueprint $table) {
            $table->foreignId('source_composition_version_id')
                ->nullable()
                ->after('composition_id')
                ->constrained('composition_versions')
                ->nullOnDelete();
            $table->string('source_document_revision_hash', 64)->nullable()->after('source_composition_version_id');
            $table->json('animation_intent_json')->nullable()->after('generate_audio');
        });
    }

    public function down(): void
    {
        Schema::table('studio_animation_jobs', function (Blueprint $table) {
            $table->dropForeign(['source_composition_version_id']);
            $table->dropColumn([
                'source_composition_version_id',
                'source_document_revision_hash',
                'animation_intent_json',
            ]);
        });
    }
};
