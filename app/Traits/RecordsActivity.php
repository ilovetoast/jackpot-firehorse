<?php

namespace App\Traits;

use App\Enums\EventType;
use App\Models\ActivityEvent;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * RecordsActivity Trait
 * 
 * Automatically records model lifecycle events (created, updated, deleted, restored).
 * 
 * Features:
 * - Only logs diffs for updates (not full model payloads)
 * - Allows opting out per model
 * - Allows custom event names per model
 * - Respects tenant context
 * 
 * Usage:
 * ```php
 * class Asset extends Model
 * {
 *     use RecordsActivity;
 *     
 *     // Optional: customize event names
 *     protected static $activityEventNames = [
 *         'created' => EventType::ASSET_UPLOADED,
 *         'updated' => EventType::ASSET_METADATA_UPDATED,
 *     ];
 *     
 *     // Optional: disable automatic logging
 *     protected static $recordActivity = false;
 * }
 * ```
 */
trait RecordsActivity
{
    /**
     * Boot the trait.
     */
    protected static function bootRecordsActivity(): void
    {
        // Check if activity recording is disabled
        if (static::shouldRecordActivity() === false) {
            return;
        }

        // Record created event
        static::created(function (Model $model) {
            static::recordModelEvent($model, 'created');
        });

        // Record updated event (with diff)
        static::updated(function (Model $model) {
            static::recordModelEvent($model, 'updated');
        });

        // Record deleted event
        static::deleted(function (Model $model) {
            static::recordModelEvent($model, 'deleted');
        });

        // Record restored event (if using soft deletes)
        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                static::recordModelEvent($model, 'restored');
            });
        }
    }

    /**
     * Record a model event.
     * 
     * @param Model $model
     * @param string $event (created, updated, deleted, restored)
     * @return void
     */
    protected static function recordModelEvent(Model $model, string $event): void
    {
        try {
            // Get tenant ID from model
            $tenantId = static::getTenantIdFromModel($model);
            
            // For AI agent runs in system context, skip activity logging
            // System runs don't have a tenant, and ActivityRecorder requires tenant
            // Agent runs are still tracked in ai_agent_runs table for audit
            if ($model instanceof \App\Models\AIAgentRun && 
                ($model->triggering_context ?? null) === 'system' && 
                !$tenantId) {
                // System context runs are tracked in ai_agent_runs table only
                return;
            }
            
            if (!$tenantId) {
                // Can't record without tenant
                return;
            }

            // Get event type
            $eventType = static::getEventTypeForModel($model, $event);
            
            if (!$eventType) {
                return;
            }

            // Get brand ID if model has brand relationship
            $brandId = static::getBrandIdFromModel($model);

            // Prepare metadata
            $metadata = [];
            
            if ($event === 'updated') {
                // Only log changed attributes (diff)
                $metadata = [
                    'changed' => $model->getChanges(),
                    'original' => Arr::only($model->getOriginal(), array_keys($model->getChanges())),
                ];
            }
            
            // Always store subject name in metadata for easier retrieval
            // This helps when the subject relationship isn't loaded or the model is deleted
            if (method_exists($model, 'getNameAttribute') || isset($model->name)) {
                $metadata['subject_name'] = $model->name ?? null;
            } elseif (method_exists($model, 'getTitleAttribute') || isset($model->title)) {
                $metadata['subject_name'] = $model->title ?? null;
            }

            // Record the event
            ActivityRecorder::record(
                tenant: $tenantId,
                eventType: $eventType,
                subject: $model,
                actor: null, // Auto-detect from Auth
                brand: $brandId,
                metadata: $metadata
            );
        } catch (\Exception $e) {
            // Don't let activity logging break the main operation
            \Log::error('Failed to record activity event', [
                'model' => get_class($model),
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get tenant ID from model.
     * 
     * @param Model $model
     * @return int|null
     */
    protected static function getTenantIdFromModel(Model $model): ?int
    {
        // Check if model has tenant_id directly
        if (isset($model->tenant_id)) {
            return $model->tenant_id;
        }

        // Check if model has tenant relationship
        if (method_exists($model, 'tenant')) {
            try {
                $tenant = $model->tenant;
                if ($tenant) {
                    return $tenant->id;
                }
            } catch (\Exception $e) {
                // Relationship might not be loaded or might not exist
            }
        }

        // Check if model has brand relationship (brands have tenant_id)
        if (method_exists($model, 'brand')) {
            try {
                $brand = $model->brand;
                if ($brand && isset($brand->tenant_id)) {
                    return $brand->tenant_id;
                }
            } catch (\Exception $e) {
                // Relationship might not be loaded or might not exist
            }
        }

        // Try to get from app context (if tenant is resolved)
        if (app()->bound('tenant')) {
            $tenant = app('tenant');
            return $tenant?->id;
        }

        return null;
    }

    /**
     * Get brand ID from model.
     * 
     * @param Model $model
     * @return int|null
     */
    protected static function getBrandIdFromModel(Model $model): ?int
    {
        // Check if model has brand_id directly
        if (isset($model->brand_id)) {
            return $model->brand_id;
        }

        // Check if model has brand relationship
        if (method_exists($model, 'brand') && $model->brand) {
            return $model->brand->id;
        }

        return null;
    }

    /**
     * Get event type for model and event.
     * 
     * @param Model $model
     * @param string $event
     * @return string|null
     */
    protected static function getEventTypeForModel(Model $model, string $event): ?string
    {
        // Check for custom event names
        $customEvents = static::$activityEventNames ?? [];
        
        // If custom event is explicitly set (even if null), use it
        if (array_key_exists($event, $customEvents)) {
            return $customEvents[$event];
        }

        // Generate default event type from model class
        $modelName = static::getModelNameForEventType($model);
        
        return match ($event) {
            'created' => "{$modelName}.created",
            'updated' => "{$modelName}.updated",
            'deleted' => "{$modelName}.deleted",
            'restored' => "{$modelName}.restored",
            default => null,
        };
    }

    /**
     * Get model name for event type generation.
     * 
     * @param Model $model
     * @return string
     */
    protected static function getModelNameForEventType(Model $model): string
    {
        $className = class_basename($model);
        
        // Convert PascalCase to snake_case
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
        
        return $snakeCase;
    }

    /**
     * Check if activity should be recorded.
     * 
     * @return bool
     */
    protected static function shouldRecordActivity(): bool
    {
        // Check if recording is explicitly disabled
        if (isset(static::$recordActivity) && static::$recordActivity === false) {
            return false;
        }

        // Default to enabled
        return true;
    }

    /**
     * Disable activity recording for this model instance.
     * 
     * @return void
     */
    public function disableActivityRecording(): void
    {
        static::$recordActivity = false;
    }

    /**
     * Enable activity recording for this model instance.
     * 
     * @return void
     */
    public function enableActivityRecording(): void
    {
        static::$recordActivity = true;
    }
}
