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
        if (Schema::hasTable('metadata_field_permissions')) {
            return; // Table already exists, skip
        }

        Schema::create('metadata_field_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metadata_field_id')->constrained('metadata_fields')->onDelete('restrict');
            $table->string('role'); // User role (owner, admin, manager, editor, viewer, member)
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('cascade');
            $table->boolean('can_edit')->default(false);
            $table->timestamps();

            // Indexes for efficient queries (using short names to avoid MySQL 64-char limit)
            $table->index(['metadata_field_id', 'role', 'tenant_id'], 'mfp_field_role_tenant_idx');
            $table->index(['tenant_id', 'brand_id', 'category_id'], 'mfp_scope_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metadata_field_permissions');
    }
};
