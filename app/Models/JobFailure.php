<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * JobFailure Model
 * 
 * Wrapper around Laravel's failed_jobs table for linking job failures to tickets.
 * 
 * This model supports polymorphic relationships for ticket linking.
 * Maps to the existing `failed_jobs` table structure.
 */
class JobFailure extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'failed_jobs';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'connection',
        'queue',
        'payload',
        'exception',
        'failed_at',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Get all tickets that link to this job failure.
     */
    public function tickets(): MorphMany
    {
        return $this->morphMany(TicketLink::class, 'linkable');
    }
}
