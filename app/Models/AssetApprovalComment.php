<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase AF-2: Asset Approval Comment Model
 * 
 * Immutable audit trail for approval workflow.
 * Records all approval actions with optional comments.
 */
class AssetApprovalComment extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'asset_id',
        'user_id',
        'action',
        'comment',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
        ];
    }

    /**
     * Get the asset that this comment belongs to.
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Get the user who created this comment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
