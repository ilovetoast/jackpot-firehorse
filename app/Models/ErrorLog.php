<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * ErrorLog Model
 * 
 * Represents application error logs that can be linked to tickets for diagnostic context.
 * 
 * This model supports polymorphic relationships for ticket linking.
 */
class ErrorLog extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'error_logs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id',
        'level',
        'message',
        'context',
        'file',
        'line',
        'trace',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'line' => 'integer',
        ];
    }

    /**
     * Get the tenant that owns this error log (if any).
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get all tickets that link to this error log.
     */
    public function tickets(): MorphMany
    {
        return $this->morphMany(TicketLink::class, 'linkable');
    }
}
