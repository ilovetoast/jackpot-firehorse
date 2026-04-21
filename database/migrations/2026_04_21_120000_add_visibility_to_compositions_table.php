<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compositions', function (Blueprint $table) {
            $table->string('visibility', 16)->default('private')->after('user_id');
        });

        // Existing rows behaved as “everyone on the brand can open them” — keep that.
        DB::table('compositions')->update(['visibility' => 'shared']);
    }

    public function down(): void
    {
        Schema::table('compositions', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
