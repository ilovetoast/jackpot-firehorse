<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password-protected collection share links (V1).
 *
 * is_public remains the “external share enabled” flag. public_share_token is the
 * stable segment for /share/collections/{token}. Legacy is_public rows without
 * public_password_hash are not served until a password is set (see controllers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('public_share_token', 64)->nullable()->unique()->after('is_public');
            $table->string('public_password_hash')->nullable()->after('public_share_token');
            $table->timestamp('public_password_set_at')->nullable()->after('public_password_hash');
            $table->boolean('public_downloads_enabled')->default(true)->after('public_password_set_at');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn([
                'public_share_token',
                'public_password_hash',
                'public_password_set_at',
                'public_downloads_enabled',
            ]);
        });
    }
};
