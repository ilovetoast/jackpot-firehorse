<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DownloadShareEmailRecipientHistory extends Model
{
    protected $table = 'download_share_email_recipient_histories';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'recipient_email',
        'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Remember a successful share-email recipient for autocomplete (per sender + tenant).
     */
    public static function recordSend(User $user, int $tenantId, string $email): void
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        static::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'recipient_email' => $normalized,
            ],
            [
                'last_sent_at' => now(),
            ]
        );
    }
}
