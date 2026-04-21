<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_sets', function (Blueprint $table) {
            $table->foreignId('hero_composition_id')
                ->nullable()
                ->after('status')
                ->constrained('compositions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creative_sets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hero_composition_id');
        });
    }
};
