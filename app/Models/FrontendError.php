<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * FrontendError Model
 * 
 * Represents client-side JavaScript errors that can be linked to tickets for diagnostic context.
 * 
 * This model supports polymorphic relationships for ticket linking.
 */
class FrontendError extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'frontend_errors';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'error_type',
        'message',
        'stack_trace',
        'url',
        'user_agent',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * Get the tenant that owns this frontend error (if any).
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the user who encountered this error (if any).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all tickets that link to this frontend error.
     */
    public function tickets(): MorphMany
    {
        return $this->morphMany(TicketLink::class, 'linkable');
    }
}
