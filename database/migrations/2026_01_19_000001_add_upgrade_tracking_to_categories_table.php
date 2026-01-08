<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('system_category_id')->nullable()->after('brand_id')->constrained('system_categories')->onDelete('set null');
            $table->integer('system_version')->nullable()->after('system_category_id');
            $table->boolean('upgrade_available')->default(false)->after('system_version');
            
            // Add indexes for efficient queries
            $table->index('system_category_id');
            $table->index('system_version');
            $table->index('upgrade_available');
            $table->index(['system_category_id', 'system_version'], 'category_upgrade_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('category_upgrade_lookup');
            $table->dropIndex(['upgrade_available']);
            $table->dropIndex(['system_version']);
            $table->dropIndex(['system_category_id']);
            $table->dropForeign(['system_category_id']);
            $table->dropColumn(['system_category_id', 'system_version', 'upgrade_available']);
        });
    }
};
