<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_set_variants', function (Blueprint $table) {
            $table->foreignId('studio_variant_group_id')
                ->nullable()
                ->after('creative_set_id')
                ->constrained('studio_variant_groups')
                ->nullOnDelete();
        });

        Schema::table('generation_job_items', function (Blueprint $table) {
            $table->foreignId('studio_variant_group_member_id')
                ->nullable()
                ->after('creative_set_variant_id')
                ->constrained('studio_variant_group_members')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generation_job_items', function (Blueprint $table) {
            $table->dropForeign(['studio_variant_group_member_id']);
        });

        Schema::table('creative_set_variants', function (Blueprint $table) {
            $table->dropForeign(['studio_variant_group_id']);
        });
    }
};
