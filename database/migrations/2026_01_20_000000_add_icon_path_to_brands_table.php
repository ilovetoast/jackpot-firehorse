<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Legacy: added icon_path for brand icon uploads. Superseded — column creation removed;
 * use letter tile + logo variants instead. Kept as no-op so migration history stays ordered.
 */
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
