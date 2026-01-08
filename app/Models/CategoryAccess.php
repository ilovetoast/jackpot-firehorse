<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CategoryAccess Model
 *
 * Manages access control for private categories.
 * Each record grants access to a category for either:
 * - A specific brand role (access_type = 'role', role = 'admin', 'member', etc.)
 * - A specific user (access_type = 'user', user_id = user ID)
 *
 * Rules:
 * - Either role OR user_id must be present (not both, not neither)
 * - Scoped by brand_id
 * - Cascade delete when category or brand is deleted
 */
class CategoryAccess extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'category_access';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'brand_id',
        'access_type',
        'role',
        'user_id',
    ];

    /**
     * Get the category that this access rule belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the brand that this access rule is scoped to.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the user that this access rule grants access to (if access_type = 'user').
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Validate that either role OR user_id is set (not both, not neither)
        static::creating(function ($categoryAccess) {
            self::validateAccessRule($categoryAccess);
        });

        static::updating(function ($categoryAccess) {
            self::validateAccessRule($categoryAccess);
        });
    }

    /**
     * Validate that access rule has exactly one of role or user_id.
     *
     * @param CategoryAccess $categoryAccess
     * @return void
     * @throws \InvalidArgumentException
     */
    protected static function validateAccessRule(CategoryAccess $categoryAccess): void
    {
        $hasRole = !empty($categoryAccess->role);
        $hasUserId = !empty($categoryAccess->user_id);

        if ($hasRole && $hasUserId) {
            throw new \InvalidArgumentException('Category access rule cannot have both role and user_id set.');
        }

        if (!$hasRole && !$hasUserId) {
            throw new \InvalidArgumentException('Category access rule must have either role or user_id set.');
        }

        // Ensure access_type matches the data
        if ($hasRole && $categoryAccess->access_type !== 'role') {
            throw new \InvalidArgumentException('Category access rule with role must have access_type = "role".');
        }

        if ($hasUserId && $categoryAccess->access_type !== 'user') {
            throw new \InvalidArgumentException('Category access rule with user_id must have access_type = "user".');
        }
    }
}
