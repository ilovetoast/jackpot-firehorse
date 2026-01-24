<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Deletion Error Model
 * 
 * Tracks errors that occur during asset deletion operations.
 * This allows errors to be presented to users and tracked for system health.
 */
class DeletionError extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'original_filename',
        'deletion_type',
        'error_type',
        'error_message',
        'error_details',
        'attempts',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
    ];

    protected $casts = [
        'error_details' => 'array',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns the deletion error.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the user who resolved the error (if any).
     */
    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Scope to only unresolved errors.
     */
    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Scope to only resolved errors.
     */
    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    /**
     * Mark this error as resolved.
     */
    public function markResolved($userId = null, $notes = null)
    {
        $this->update([
            'resolved_at' => now(),
            'resolved_by' => $userId,
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * Get a user-friendly error message.
     */
    public function getUserFriendlyMessage()
    {
        return match($this->error_type) {
            'storage_verification_failed' => 'Failed to verify file location before deletion',
            'storage_deletion_failed' => 'Failed to delete files from storage',
            'database_deletion_failed' => 'Failed to remove record from database',
            'permission_denied' => 'Permission denied while accessing storage',
            'network_error' => 'Network connection error during deletion',
            'timeout' => 'Deletion operation timed out',
            default => 'An unexpected error occurred during deletion'
        };
    }

    /**
     * Get error severity level.
     */
    public function getSeverityLevel()
    {
        return match($this->error_type) {
            'storage_verification_failed' => 'warning',
            'network_error', 'timeout' => 'warning',
            'storage_deletion_failed', 'database_deletion_failed' => 'error',
            'permission_denied' => 'critical',
            default => 'error'
        };
    }
}