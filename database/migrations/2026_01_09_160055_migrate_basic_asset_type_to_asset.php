<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('categories')
            ->where('asset_type', 'basic')
            ->update(['asset_type' => 'asset']);
    }

    public function down(): void
    {
        DB::table('categories')
            ->where('asset_type', 'asset')
            ->update(['asset_type' => 'basic']);
    }
};
