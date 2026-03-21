<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestion_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('suggestion_type', 16);
            $table->string('normalized_key', 512);
            $table->unsignedSmallInteger('rejected_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'suggestion_type', 'normalized_key'], 'asf_tenant_type_key_unique');
            $table->index(['tenant_id', 'suggestion_type']);
        });

        if (Schema::hasTable('ai_metadata_value_suggestions')) {
            Schema::table('ai_metadata_value_suggestions', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_metadata_value_suggestions', 'priority_score')) {
                    $table->decimal('priority_score', 10, 4)->nullable()->after('confidence');
                }
                if (! Schema::hasColumn('ai_metadata_value_suggestions', 'consistency_score')) {
                    $table->decimal('consistency_score', 7, 4)->nullable()->after('priority_score');
                }
            });
        }

        if (Schema::hasTable('ai_metadata_field_suggestions')) {
            Schema::table('ai_metadata_field_suggestions', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_metadata_field_suggestions', 'priority_score')) {
                    $table->decimal('priority_score', 10, 4)->nullable()->after('confidence');
                }
                if (! Schema::hasColumn('ai_metadata_field_suggestions', 'consistency_score')) {
                    $table->decimal('consistency_score', 7, 4)->nullable()->after('priority_score');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_metadata_field_suggestions')) {
            Schema::table('ai_metadata_field_suggestions', function (Blueprint $table) {
                if (Schema::hasColumn('ai_metadata_field_suggestions', 'consistency_score')) {
                    $table->dropColumn('consistency_score');
                }
                if (Schema::hasColumn('ai_metadata_field_suggestions', 'priority_score')) {
                    $table->dropColumn('priority_score');
                }
            });
        }

        if (Schema::hasTable('ai_metadata_value_suggestions')) {
            Schema::table('ai_metadata_value_suggestions', function (Blueprint $table) {
                if (Schema::hasColumn('ai_metadata_value_suggestions', 'consistency_score')) {
                    $table->dropColumn('consistency_score');
                }
                if (Schema::hasColumn('ai_metadata_value_suggestions', 'priority_score')) {
                    $table->dropColumn('priority_score');
                }
            });
        }

        Schema::dropIfExists('ai_suggestion_feedback');
    }
};
