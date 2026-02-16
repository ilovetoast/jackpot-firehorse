<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brand Bootstrap Run — foundation for URL-based Brand DNA extraction.
 * Scoped to brand. Status: pending | running | completed | inferred | failed.
 * Phase 7: Multi-stage pipeline with progress tracking.
 */
class BrandBootstrapRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'ai_output_payload' => 'array',
            'stage_log' => 'array',
        ];
    }

    /**
     * Append a message to stage_log. Safe JSON merging.
     */
    public function appendLog(string $message): void
    {
        $log = $this->stage_log ?? [];
        $log[] = ['at' => now()->toIso8601String(), 'message' => $message];
        $this->update(['stage_log' => $log]);
    }

    /**
     * Set current stage and progress percent.
     */
    public function setStage(string $stage, int $progress): void
    {
        $this->update([
            'stage' => $stage,
            'progress_percent' => min(100, max(0, $progress)),
        ]);
    }

    /**
     * Increment current_stage_index and return new value.
     */
    public function incrementStage(): int
    {
        $next = $this->current_stage_index + 1;
        $this->update(['current_stage_index' => $next]);

        return $next;
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedVersion(): BelongsTo
    {
        return $this->belongsTo(BrandModelVersion::class, 'approved_version_id');
    }
}
