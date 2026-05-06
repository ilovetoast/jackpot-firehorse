<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_demo_template')->default(false);
            $table->boolean('is_demo')->default(false);
            $table->foreignId('demo_template_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->timestamp('demo_expires_at')->nullable();
            $table->string('demo_status')->nullable();
            $table->foreignId('demo_created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('demo_label')->nullable();
            $table->string('demo_plan_key')->nullable();
            $table->text('demo_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['demo_template_id']);
            $table->dropForeign(['demo_created_by_user_id']);
            $table->dropColumn([
                'is_demo_template',
                'is_demo',
                'demo_template_id',
                'demo_expires_at',
                'demo_status',
                'demo_created_by_user_id',
                'demo_label',
                'demo_plan_key',
                'demo_notes',
            ]);
        });
    }
};
