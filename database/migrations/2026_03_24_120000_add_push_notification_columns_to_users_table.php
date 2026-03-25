<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'push_prompted_at')) {
                $table->timestamp('push_prompted_at')->nullable()->after('last_login_at');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'push_enabled')) {
                $after = Schema::hasColumn('users', 'push_prompted_at') ? 'push_prompted_at' : 'last_login_at';
                $table->boolean('push_enabled')->default(false)->after($after);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = array_values(array_filter(
                ['push_prompted_at', 'push_enabled'],
                fn (string $c) => Schema::hasColumn('users', $c)
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
