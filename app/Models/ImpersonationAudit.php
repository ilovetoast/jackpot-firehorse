<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpersonationAudit extends Model
{
    public const EVENT_STARTED = 'session_started';

    public const EVENT_ENDED = 'session_ended';

    public const EVENT_REQUEST = 'request';

    protected $fillable = [
        'impersonation_session_id',
        'event',
        'http_method',
        'path',
        'route_name',
        'initiator_user_id',
        'acting_user_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ImpersonationSession::class, 'impersonation_session_id');
    }
}
