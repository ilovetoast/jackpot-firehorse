<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes dedicated brand icon upload / heroicon selection (icon_path, icon_id, icon).
 * Tile + letter styling remains via icon_bg_color, icon_style, primary/secondary colors.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropColumnIfExists('brands', 'icon_id');
        $this->dropColumnIfExists('brands', 'icon_path');
        $this->dropColumnIfExists('brands', 'icon');
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'icon_path') && Schema::hasColumn('brands', 'logo_path')) {
                $table->string('icon_path')->nullable()->after('logo_path');
            } elseif (! Schema::hasColumn('brands', 'icon_path')) {
                $table->string('icon_path')->nullable();
            }
            if (! Schema::hasColumn('brands', 'icon_id')) {
                $table->uuid('icon_id')->nullable()->after('icon_path');
            }
            if (! Schema::hasColumn('brands', 'icon')) {
                if (Schema::hasColumn('brands', 'icon_path')) {
                    $table->string('icon')->nullable()->after('icon_path');
                } else {
                    $table->string('icon')->nullable();
                }
            }
        });
    }

    /**
     * Drop a column only when present, and tolerate concurrent migrators
     * that may remove it between the hasColumn() check and ALTER TABLE.
     */
    private function dropColumnIfExists(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        } catch (QueryException $exception) {
            $driverErrorCode = $exception->errorInfo[1] ?? null;

            // MySQL/MariaDB "Can't DROP ... check that column/key exists"
            if ((int) $driverErrorCode === 1091) {
                return;
            }

            throw $exception;
        }
    }
};
