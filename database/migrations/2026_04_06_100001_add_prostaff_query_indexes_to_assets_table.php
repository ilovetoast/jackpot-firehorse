<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function brandProstaffIndexName(): string
    {
        return 'assets_brand_id_submitted_by_prostaff_index';
    }

    private function brandProstaffIndexExists(): bool
    {
        foreach (Schema::getIndexes('assets') as $index) {
            if (($index['name'] ?? '') === $this->brandProstaffIndexName()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dashboards / filters / analytics: scope by brand + prostaff flag.
     * Note: prostaff_user_id is already indexed by the foreign key on that column.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('assets', 'submitted_by_prostaff')) {
            return;
        }

        if ($this->brandProstaffIndexExists()) {
            return;
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->index(['brand_id', 'submitted_by_prostaff']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('assets', 'submitted_by_prostaff')) {
            return;
        }

        if (! $this->brandProstaffIndexExists()) {
            return;
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['brand_id', 'submitted_by_prostaff']);
        });
    }
};
