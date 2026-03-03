<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Set color = null when color equals system default AND user_defined is false.
     * Does not wipe legitimate user-defined values.
     */
    public function up(): void
    {
        foreach (['primary_color', 'secondary_color', 'accent_color'] as $colorCol) {
            $userDefinedCol = $colorCol . '_user_defined';
            $default = match ($colorCol) {
                'primary_color' => '#6366f1',
                'secondary_color' => '#8b5cf6',
                'accent_color' => '#06b6d4',
                default => null,
            };
            if ($default === null) {
                continue;
            }

            DB::table('brands')
                ->where($colorCol, $default)
                ->where($userDefinedCol, false)
                ->update([$colorCol => null]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot safely restore; no-op
    }
};
