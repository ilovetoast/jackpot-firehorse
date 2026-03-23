<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 255);
            $table->longText('document_json');
            $table->string('thumbnail_path', 512)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'brand_id']);
        });

        Schema::create('composition_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('composition_id')->constrained('compositions')->cascadeOnDelete();
            $table->longText('document_json');
            $table->string('label', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('composition_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composition_versions');
        Schema::dropIfExists('compositions');
    }
};
