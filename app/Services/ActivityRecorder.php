<?php

namespace App\Services;

use App\Enums\EventType;
use App\Models\ActivityEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * ActivityRecorder Service
 * 
 * Single entry point for recording activity events.
 * 
 * Features:
 * - Automatically captures IP address and user agent from request context
 * - Safe to call from HTTP requests, queued jobs, console commands, and system processes
 * - Validates event types
 * - Handles actor resolution automatically
 * 
 * Usage:
 * ```php
 * ActivityRecorder::record($tenant, EventType::ASSET_UPLOADED, $asset, $user, $brand, ['size' => 1024]);
 * ```
 */
class ActivityRecorder
{
    /**
     * Record an activity event.
     * 
     * @param int|\App\Models\Tenant $tenant Tenant ID or Tenant model
     * @param string $eventType Event type from EventType enum
     * @param Model|null $subject The model this event is about (e.g., Asset, User)
     * @param Model|\App\Models\User|string|null $actor The actor (User model, 'system', 'api', 'guest', or null for auto-detect)
     * @param int|\App\Models\Brand|null $brand Brand ID or Brand model (optional)
     * @param array $metadata Additional metadata to store
     * @return ActivityEvent
     * @throws \InvalidArgumentException
     */
    public static function record(
        int|Model $tenant,
        string $eventType,
        ?Model $subject = null,
        Model|string|null $actor = null,
        int|Model|null $brand = null,
        array $metadata = []
    ): ActivityEvent {
        // Validate event type
        if (!EventType::isValid($eventType)) {
            Log::warning("Invalid event type attempted: {$eventType}");
            // Don't throw in production, but log it
            // throw new \InvalidArgumentException("Invalid event type: {$eventType}");
        }

        // Resolve tenant ID
        $tenantId = $tenant instanceof Model ? $tenant->id : $tenant;
        
        if (!$tenantId) {
            throw new \InvalidArgumentException('Tenant ID is required');
        }

        // Resolve brand ID
        $brandId = null;
        if ($brand !== null) {
            $brandId = $brand instanceof Model ? $brand->id : $brand;
        }

        // Resolve actor
        [$actorType, $actorId] = self::resolveActor($actor, $subject);

        // Resolve subject
        [$subjectType, $subjectId] = self::resolveSubject($subject);

        // Capture request context (if available)
        $ipAddress = self::getIpAddress();
        $userAgent = self::getUserAgent();

        // Create the activity event
        $activityEvent = ActivityEvent::create([
            'tenant_id' => $tenantId,
            'brand_id' => $brandId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => !empty($metadata) ? $metadata : null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);

        return $activityEvent;
    }

    /**
     * Resolve actor type and ID.
     * 
     * @param Model|string|null $actor
     * @param Model|null $subject
     * @return array{string, int|null}
     */
    protected static function resolveActor(Model|string|null $actor, ?Model $subject): array
    {
        // Explicit actor provided
        if ($actor instanceof Model) {
            $modelClass = get_class($actor);
            return [
                self::getActorTypeFromModel($modelClass),
                $actor->id,
            ];
        }

        // String actor type (system, api, guest)
        if (is_string($actor) && in_array($actor, ['system', 'api', 'guest'], true)) {
            return [$actor, null];
        }

        // Auto-detect from authenticated user
        if (Auth::check()) {
            $user = Auth::user();
            return ['user', $user->id];
        }

        // Try to get from subject if it's a User
        if ($subject && $subject instanceof \App\Models\User) {
            return ['user', $subject->id];
        }

        // Default to system if no context
        return ['system', null];
    }

    /**
     * Get actor type from model class.
     * 
     * @param string $modelClass
     * @return string
     */
    protected static function getActorTypeFromModel(string $modelClass): string
    {
        return match ($modelClass) {
            \App\Models\User::class => 'user',
            default => 'system',
        };
    }

    /**
     * Resolve subject type and ID.
     * 
     * @param Model|null $subject
     * @return array{string, int}
     */
    protected static function resolveSubject(?Model $subject): array
    {
        if ($subject === null) {
            return ['unknown', 0];
        }

        return [
            $subject->getMorphClass(),
            $subject->id,
        ];
    }

    /**
     * Get IP address from request context.
     * Safe to call from any context (HTTP, jobs, commands).
     * 
     * @return string|null
     */
    protected static function getIpAddress(): ?string
    {
        try {
            $request = request();
            if ($request) {
                return $request->ip();
            }
        } catch (\Exception $e) {
            // Not in request context
        }

        return null;
    }

    /**
     * Get user agent from request context.
     * Safe to call from any context (HTTP, jobs, commands).
     * 
     * @return string|null
     */
    protected static function getUserAgent(): ?string
    {
        try {
            $request = request();
            if ($request) {
                return $request->userAgent();
            }
        } catch (\Exception $e) {
            // Not in request context
        }

        return null;
    }

    /**
     * Record a system event.
     * Convenience method for system-level events.
     * 
     * @param int|\App\Models\Tenant $tenant
     * @param string $eventType
     * @param Model|null $subject
     * @param array $metadata
     * @return ActivityEvent
     */
    public static function system(
        int|Model $tenant,
        string $eventType,
        ?Model $subject = null,
        array $metadata = []
    ): ActivityEvent {
        return self::record($tenant, $eventType, $subject, 'system', null, $metadata);
    }

    /**
     * Record an API event.
     * Convenience method for API-triggered events.
     * 
     * @param int|\App\Models\Tenant $tenant
     * @param string $eventType
     * @param Model|null $subject
     * @param array $metadata
     * @return ActivityEvent
     */
    public static function api(
        int|Model $tenant,
        string $eventType,
        ?Model $subject = null,
        array $metadata = []
    ): ActivityEvent {
        return self::record($tenant, $eventType, $subject, 'api', null, $metadata);
    }

    /**
     * Record a guest event.
     * Convenience method for guest/unauthenticated events.
     * 
     * @param int|\App\Models\Tenant $tenant
     * @param string $eventType
     * @param Model|null $subject
     * @param array $metadata
     * @return ActivityEvent
     */
    public static function guest(
        int|Model $tenant,
        string $eventType,
        ?Model $subject = null,
        array $metadata = []
    ): ActivityEvent {
        return self::record($tenant, $eventType, $subject, 'guest', null, $metadata);
    }
}
