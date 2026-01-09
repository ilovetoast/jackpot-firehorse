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
        Schema::table('assets', function (Blueprint $table) {
            // Rename fields to match spec
            $table->renameColumn('file_name', 'original_filename');
            $table->renameColumn('file_size', 'size_bytes');
            $table->renameColumn('path', 'storage_root_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Restore original field names
            $table->renameColumn('original_filename', 'file_name');
            $table->renameColumn('size_bytes', 'file_size');
            $table->renameColumn('storage_root_path', 'path');
        });
    }
};
