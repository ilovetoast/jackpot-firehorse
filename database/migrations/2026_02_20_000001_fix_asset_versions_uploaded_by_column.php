<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix uploaded_by column type: users.id is bigint, not uuid.
 * Ensures correct foreign key and relationship resolution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('uploaded_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('asset_versions', function (Blueprint $table) {
            $table->uuid('uploaded_by')->nullable()->change();
        });
    }
};
