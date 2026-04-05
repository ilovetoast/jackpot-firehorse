<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('access_mode', 32)->default('all_brand')->after('visibility');
            $table->json('allowed_brand_roles')->nullable()->after('access_mode');
            $table->boolean('allows_external_guests')->default(false)->after('allowed_brand_roles');
        });

        // Legacy visibility → new fields (restricted/private shared the same membership rules)
        DB::table('collections')->where('visibility', 'brand')->update([
            'access_mode' => 'all_brand',
            'allows_external_guests' => false,
            'allowed_brand_roles' => null,
        ]);

        DB::table('collections')->whereIn('visibility', ['restricted', 'private'])->update([
            'access_mode' => 'invite_only',
            'allows_external_guests' => true,
            'allowed_brand_roles' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn(['access_mode', 'allowed_brand_roles', 'allows_external_guests']);
        });
    }
};
