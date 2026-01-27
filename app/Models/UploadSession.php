<?php

namespace App\Models;

use App\Enums\UploadStatus;
use App\Enums\UploadType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UploadSession extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'storage_bucket_id',
        'status',
        'type',
        'mode', // Phase J.3.1: 'create' or 'replace'
        'asset_id', // Phase J.3.1: Asset ID when mode = 'replace'
        'expected_size',
        'uploaded_size',
        'expires_at',
        'failure_reason',
        'client_reference',
        'multipart_upload_id', // S3 multipart upload ID for resume support
        'multipart_state', // JSON state tracking for multipart uploads
        'part_size', // Part size for multipart uploads (10MB default)
        'total_parts', // Total number of parts for multipart uploads
        'last_activity_at', // Last activity timestamp for abandoned session detection
        'last_cleanup_attempt_at', // Optional: Track when cleanup was last attempted (for observability)
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UploadStatus::class,
            'type' => UploadType::class,
            'expected_size' => 'integer',
            'uploaded_size' => 'integer',
            'expires_at' => 'datetime',
            'multipart_state' => 'array', // JSON state tracking for multipart uploads
            'part_size' => 'integer',
            'total_parts' => 'integer',
            'last_activity_at' => 'datetime',
            'last_cleanup_attempt_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns this upload session.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the brand that owns this upload session.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the storage bucket for this upload session.
     */
    public function storageBucket(): BelongsTo
    {
        return $this->belongsTo(StorageBucket::class);
    }

    /**
     * Get the assets created from this upload session.
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * Get the asset being replaced (when mode = 'replace').
     * Phase J.3.1: File-only replacement support
     */
    public function replaceAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * Check if the upload session can transition to the given status.
     *
     * Prevents invalid status transitions to maintain data integrity.
     * This is critical for resume/retry functionality and prevents state corruption.
     *
     * Valid transitions:
     * - INITIATING → UPLOADING, COMPLETED, CANCELLED, FAILED
     * - UPLOADING → COMPLETED, CANCELLED, FAILED
     * - COMPLETED → (terminal, no transitions allowed)
     * - FAILED → (terminal, no transitions allowed)
     * - CANCELLED → (terminal, no transitions allowed)
     *
     * Invalid transitions (explicitly blocked):
     * - COMPLETED → CANCELLED ❌
     * - FAILED → UPLOADING ❌
     * - CANCELLED → COMPLETED ❌
     * - EXPIRED sessions → any transition ❌
     *
     * @param UploadStatus $newStatus The status to transition to
     * @return bool True if transition is allowed, false otherwise
     */
    public function canTransitionTo(UploadStatus $newStatus): bool
    {
        // Check if session is expired - expired sessions cannot transition
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        // Same status is always allowed (idempotent)
        if ($this->status === $newStatus) {
            return true;
        }

        // Terminal states cannot transition to any other state
        $terminalStates = [
            UploadStatus::COMPLETED,
            UploadStatus::FAILED,
            UploadStatus::CANCELLED,
        ];

        if (in_array($this->status, $terminalStates)) {
            return false;
        }

        // Define allowed transitions from each non-terminal state
        $allowedTransitions = [
            UploadStatus::INITIATING->value => [
                UploadStatus::UPLOADING->value,
                UploadStatus::COMPLETED->value,
                UploadStatus::CANCELLED->value,
                UploadStatus::FAILED->value,
            ],
            UploadStatus::UPLOADING->value => [
                UploadStatus::COMPLETED->value,
                UploadStatus::CANCELLED->value,
                UploadStatus::FAILED->value,
            ],
        ];

        $currentStatusValue = $this->status->value;
        $newStatusValue = $newStatus->value;

        // Check if transition is allowed
        return isset($allowedTransitions[$currentStatusValue]) &&
               in_array($newStatusValue, $allowedTransitions[$currentStatusValue]);
    }

    /**
     * Check if the upload session is in a terminal state.
     *
     * @return bool True if in terminal state (COMPLETED, FAILED, CANCELLED) or expired
     */
    public function isTerminal(): bool
    {
        $terminalStates = [
            UploadStatus::COMPLETED,
            UploadStatus::FAILED,
            UploadStatus::CANCELLED,
        ];

        return in_array($this->status, $terminalStates) ||
               ($this->expires_at && $this->expires_at->isPast());
    }
}
