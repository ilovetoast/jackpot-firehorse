<?php

namespace App\Services\Insights;

use App\Enums\EventType;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Brand-scoped activity feed for dashboard widget and Insights → Activity.
 */
class BrandActivityFeedService
{
    /**
     * @param  array{actor_id?: int|null, event_type?: string|null, subject_id?: string|null}  $filters
     * @return Collection<int, array<string, mixed>>|null null if user lacks activity_logs.view
     */
    public function getRecentActivity(Tenant $tenant, Brand $brand, User $user, int $limit = 5, array $filters = []): ?Collection
    {
        if (! $user->hasPermissionForTenant($tenant, 'activity_logs.view')) {
            return null;
        }

        $activityQuery = $this->scopedActivityQuery($tenant, $brand);
        $this->applyActivityFilters($activityQuery, $filters);

        $activityEvents = $activityQuery->orderBy('created_at', 'desc')
            ->limit($limit)
            ->with(['brand'])
            ->get();

        $this->batchLoadActivityEventSubjects($activityEvents);

        $activityEvents->each(function (ActivityEvent $event) {
            if (! $event->relationLoaded('subject')) {
                $event->setRelation('subject', null);
            }
        });

        $actorUserIds = $activityEvents
            ->filter(fn (ActivityEvent $e) => $e->actor_type === 'user' && $e->actor_id)
            ->pluck('actor_id')
            ->unique()
            ->values()
            ->all();
        $actorsById = $actorUserIds === []
            ? collect()
            : User::whereIn('id', $actorUserIds)->get()->keyBy('id');

        return $activityEvents->map(function ($event) use ($tenant, $actorsById) {
            $eventTypeLabel = $this->formatEventTypeLabel($event->event_type);

            $actorName = 'System';
            $actorAvatarUrl = null;
            $actorFirstName = null;
            $actorLastName = null;
            $actorEmail = null;
            $companyName = null;
            $actor = $event->getActorModel($actorsById);
            if ($actor) {
                $actorName = $actor->name;
                $actorAvatarUrl = $actor->avatar_url ?? null;
                $actorFirstName = $actor->first_name ?? null;
                $actorLastName = $actor->last_name ?? null;
                $actorEmail = $actor->email ?? null;
            } elseif (! empty($event->metadata['actor_name'])) {
                $actorName = $event->metadata['actor_name'];
            }
            if (in_array($event->actor_type, ['system', 'api', 'guest'], true)) {
                $companyName = $tenant->name ?? 'System';
            }

            $subjectName = 'Unknown';
            $subjectThumbnailUrl = null;
            $subject = $event->getRelation('subject');
            if ($subject instanceof Model) {
                // Use attributes / accessors — not method_exists('title'), which is false for normal Eloquent columns.
                $subjectName = $this->resolveSubjectDisplayName($subject);
                if ($subject instanceof Asset) {
                    $meta = $subject->metadata ?? [];
                    $thumbStatus = $subject->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                        ? $subject->thumbnail_status->value
                        : ($subject->thumbnail_status ?? 'pending');
                    if ($thumbStatus === 'completed') {
                        $version = $meta['thumbnails_generated_at'] ?? null;
                        $subjectThumbnailUrl = $subject->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED);
                        if ($subjectThumbnailUrl && $version) {
                            $subjectThumbnailUrl .= (str_contains($subjectThumbnailUrl, '?') ? '&' : '?').'v='.urlencode($version);
                        }
                    }
                }
            } elseif (! empty($event->metadata['subject_name'])) {
                $subjectName = $event->metadata['subject_name'];
            } elseif (! empty($event->metadata['asset_title'])) {
                $subjectName = $event->metadata['asset_title'];
            } elseif (! empty($event->metadata['asset_filename'])) {
                $subjectName = $event->metadata['asset_filename'];
            }

            $brandPayload = null;
            if ($event->brand) {
                $b = $event->brand;
                $brandPayload = [
                    'id' => $b->id,
                    'name' => $b->name,
                    'logo_path' => $b->logo_path,
                    'icon_bg_color' => $b->icon_bg_color,
                    'icon_style' => $b->icon_style ?? 'subtle',
                    'primary_color' => $b->primary_color ?? '#4f46e5',
                ];
            }

            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'event_type_label' => $eventTypeLabel,
                'description' => $event->metadata['description'] ?? null,
                'actor' => [
                    'type' => $event->actor_type,
                    'id' => $event->actor_type === 'user' && $event->actor_id ? (int) $event->actor_id : null,
                    'name' => $actorName,
                    'avatar_url' => $actorAvatarUrl,
                    'first_name' => $actorFirstName,
                    'last_name' => $actorLastName,
                    'email' => $actorEmail,
                ],
                'company_name' => $companyName,
                'subject' => [
                    'type' => $event->subject_type,
                    'name' => $subjectName,
                    'id' => $event->subject_id !== null && $event->subject_id !== '' ? (string) $event->subject_id : null,
                    'thumbnail_url' => $subjectThumbnailUrl,
                ],
                'brand' => $brandPayload,
                'metadata' => $event->metadata,
                'created_at' => $event->created_at->toISOString(),
                'created_at_human' => $event->created_at->diffForHumans(),
            ];
        });
    }

    /**
     * Distinct users and event types in this feed scope (for Insights Activity filters).
     *
     * @return array{actors: list<array{id: int, name: string, email: ?string}>, event_types: list<array{value: string, label: string}>}
     */
    public function getFilterOptions(Tenant $tenant, Brand $brand, User $user): array
    {
        if (! $user->hasPermissionForTenant($tenant, 'activity_logs.view')) {
            return ['actors' => [], 'event_types' => []];
        }

        $base = $this->scopedActivityQuery($tenant, $brand);

        $actorIds = (clone $base)
            ->where('actor_type', 'user')
            ->whereNotNull('actor_id')
            ->orderByDesc('created_at')
            ->limit(800)
            ->pluck('actor_id')
            ->unique()
            ->filter()
            ->values()
            ->all();

        $actors = [];
        if ($actorIds !== []) {
            $actors = User::query()
                ->whereIn('id', $actorIds)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->orderBy('email')
                ->get(['id', 'email', 'first_name', 'last_name'])
                ->map(fn (User $u) => [
                    'id' => (int) $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                ])
                ->sortBy(fn ($row) => strtolower((string) ($row['name'] ?? $row['email'] ?? '')))
                ->values()
                ->all();
        }

        $rawTypes = (clone $base)
            ->whereNotNull('event_type')
            ->orderByDesc('created_at')
            ->limit(1200)
            ->pluck('event_type')
            ->unique()
            ->filter()
            ->values()
            ->all();

        $eventTypes = collect($rawTypes)
            ->map(fn (string $t) => ['value' => $t, 'label' => $this->formatEventTypeLabel($t)])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'actors' => $actors,
            'event_types' => $eventTypes,
        ];
    }

    /**
     * @return Builder<ActivityEvent>
     */
    protected function scopedActivityQuery(Tenant $tenant, Brand $brand): Builder
    {
        $validSubjectTypes = [
            Asset::class,
            User::class,
            Tenant::class,
            Brand::class,
            Category::class,
        ];

        return ActivityEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', '!=', EventType::AI_SYSTEM_INSIGHT)
            ->whereNotNull('subject_type')
            ->whereIn('subject_type', $validSubjectTypes)
            ->where(function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)->orWhereNull('brand_id');
            });
    }

    /**
     * @param  array{actor_id?: int|null, event_type?: string|null, subject_id?: string|null}  $filters
     */
    protected function applyActivityFilters(Builder $query, array $filters): void
    {
        $actorId = $filters['actor_id'] ?? null;
        if ($actorId !== null && $actorId !== '' && (int) $actorId > 0) {
            $query->where('actor_type', 'user')->where('actor_id', (int) $actorId);
        }

        $eventType = isset($filters['event_type']) ? trim((string) $filters['event_type']) : '';
        if ($eventType !== '' && preg_match('/^[a-z0-9._-]{1,120}$/i', $eventType)) {
            $query->where('event_type', $eventType);
        }

        $subjectId = isset($filters['subject_id']) ? trim((string) $filters['subject_id']) : '';
        if ($subjectId !== '') {
            if (strlen($subjectId) > 64) {
                $subjectId = substr($subjectId, 0, 64);
            }
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $subjectId)) {
                $query->where('subject_id', $subjectId);
            } elseif (preg_match('/^\d+$/', $subjectId)) {
                $query->where('subject_id', $subjectId);
            } else {
                $like = '%'.addcslashes($subjectId, '%_\\').'%';
                $query->where('subject_id', 'like', $like);
            }
        }
    }

    /**
     * Human-readable label for activity feed (Insights, dashboard widget).
     * Must use {@see Model::getAttribute()} — not method_exists('title'), which is false for normal columns/accessors.
     */
    protected function resolveSubjectDisplayName(Model $subject): string
    {
        foreach (['title', 'original_filename', 'name'] as $attr) {
            $v = $subject->getAttribute($attr);
            if (filled($v)) {
                return is_string($v) ? trim($v) : (string) $v;
            }
        }

        $key = $subject->getKey();
        if ($key !== null && $key !== '') {
            $idStr = (string) $key;

            return ($subject instanceof Asset ? 'Asset' : 'Item').' #'.substr($idStr, 0, 8);
        }

        return 'Unknown';
    }

    protected function normalizeActivitySubjectType(?string $subjectType): ?string
    {
        if ($subjectType === null || $subjectType === '') {
            return null;
        }

        $t = trim($subjectType);
        if ($t === Asset::class || str_ends_with($t, '\\Asset') || $t === 'asset') {
            return Asset::class;
        }
        if ($t === User::class || str_ends_with($t, '\\User')) {
            return User::class;
        }
        if ($t === Tenant::class || str_ends_with($t, '\\Tenant')) {
            return Tenant::class;
        }
        if ($t === Brand::class || str_ends_with($t, '\\Brand')) {
            return Brand::class;
        }
        if ($t === Category::class || str_ends_with($t, '\\Category')) {
            return Category::class;
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ActivityEvent>|\Illuminate\Database\Eloquent\Collection<int, ActivityEvent>  $events
     */
    protected function batchLoadActivityEventSubjects($events): void
    {
        if ($events->isEmpty()) {
            return;
        }

        foreach ($events as $event) {
            $event->unsetRelation('subject');
        }

        /** @var array<string, list<ActivityEvent>> */
        $buckets = [
            Asset::class => [],
            User::class => [],
            Tenant::class => [],
            Brand::class => [],
            Category::class => [],
        ];

        foreach ($events as $event) {
            if ($event->subject_type === null || $event->subject_type === '' || ! $event->subject_id) {
                $event->setRelation('subject', null);

                continue;
            }

            $normalized = $this->normalizeActivitySubjectType($event->subject_type);
            if ($normalized === null || ! array_key_exists($normalized, $buckets)) {
                $event->setRelation('subject', null);

                continue;
            }

            $buckets[$normalized][] = $event;
        }

        foreach ($buckets as $class => $bucketEvents) {
            if ($bucketEvents === []) {
                continue;
            }

            // subject_id is string (UUID for Asset, numeric string for other models) — never cast UUIDs to int.
            $ids = collect($bucketEvents)
                ->pluck('subject_id')
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values()
                ->all();

            $models = match ($class) {
                Asset::class => Asset::withTrashed()->whereIn('id', $ids)->get()->keyBy(fn ($m) => (string) $m->getKey()),
                User::class => User::whereIn('id', $ids)->get()->keyBy(fn ($m) => (string) $m->getKey()),
                Tenant::class => Tenant::whereIn('id', $ids)->get()->keyBy(fn ($m) => (string) $m->getKey()),
                Brand::class => Brand::whereIn('id', $ids)->get()->keyBy(fn ($m) => (string) $m->getKey()),
                Category::class => Category::whereIn('id', $ids)->get()->keyBy(fn ($m) => (string) $m->getKey()),
                default => collect(),
            };

            foreach ($bucketEvents as $event) {
                $event->setRelation('subject', $models->get((string) $event->subject_id));
            }
        }
    }

    protected function formatEventTypeLabel(string $eventType): string
    {
        $parts = explode('.', $eventType);
        $formatted = array_map(function ($part) {
            $formatted = ucfirst(str_replace('_', ' ', $part));
            $formatted = str_replace('Ai ', 'AI ', $formatted);

            return $formatted;
        }, $parts);

        return implode(' ', $formatted);
    }
}
