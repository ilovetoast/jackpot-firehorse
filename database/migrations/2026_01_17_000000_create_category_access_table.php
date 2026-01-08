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
        Schema::create('category_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->enum('access_type', ['role', 'user']);
            $table->string('role')->nullable(); // Brand role name (when access_type = 'role')
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // User ID (when access_type = 'user')
            $table->timestamps();

            // Indexes
            $table->index('category_id');
            $table->index('brand_id');
            $table->index('user_id');
            $table->index(['category_id', 'access_type', 'role']);
            $table->index(['category_id', 'access_type', 'user_id']);

            // Ensure either role OR user_id is set (not both, not neither)
            // This is enforced at the application level via validation
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_access');
    }
};
