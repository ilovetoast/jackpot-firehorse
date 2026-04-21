<?php

namespace App\Services\Privacy;

use App\Models\ActivityEvent;
use App\Models\Consent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assembles a structured JSON payload of personal data tied to a user account (Art. 15 + 20).
 * Excludes secrets (password hashes). Customer Content in workspaces is summarized, not full binary exports.
 */
class UserPersonalDataExportService
{
    /**
     * @return array<string, mixed>
     */
    public function buildPayload(User $user): array
    {
        $uid = $user->id;

        $userRow = $user->only([
            'id', 'first_name', 'last_name', 'email', 'email_verified_at',
            'country', 'timezone', 'address', 'city', 'state', 'zip',
            'avatar_url', 'suspended_at', 'last_login_at', 'push_enabled',
            'push_prompted_at', 'notification_preferences', 'created_at', 'updated_at',
        ]);

        $tenantUsers = DB::table('tenant_user')
            ->where('user_id', $uid)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $brandUsers = DB::table('brand_user')
            ->where('user_id', $uid)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $collectionGrants = [];
        if (Schema::hasTable('collection_user')) {
            $collectionGrants = DB::table('collection_user')
                ->where('user_id', $uid)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
        }

        $sessions = DB::table('sessions')
            ->where('user_id', $uid)
            ->orderByDesc('last_activity')
            ->limit(100)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($r) => (array) $r)
            ->all();

        $activityAsActor = ActivityEvent::query()
            ->where('actor_type', 'user')
            ->where('actor_id', $uid)
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get()
            ->map(fn (ActivityEvent $e) => $this->serializeActivityEvent($e))
            ->all();

        $activityAsSubject = ActivityEvent::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $uid)
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get()
            ->map(fn (ActivityEvent $e) => $this->serializeActivityEvent($e))
            ->all();

        $consents = Consent::query()
            ->where('user_id', $uid)
            ->orderByDesc('granted_at')
            ->get()
            ->map(fn (Consent $c) => $c->toArray())
            ->all();

        $contactLeads = [];
        if (Schema::hasTable('contact_leads')) {
            $hasObjected = Schema::hasColumn('contact_leads', 'processing_objected_at');
            $contactLeads = DB::table('contact_leads')
                ->where('email', $user->email)
                ->orderByDesc('created_at')
                ->limit(500)
                ->get()
                ->map(function ($r) use ($hasObjected) {
                    $arr = (array) $r;
                    if ($hasObjected && ! empty($arr['processing_objected_at'])) {
                        return [
                            'id' => $arr['id'] ?? null,
                            'kind' => $arr['kind'] ?? null,
                            'processing_objected_at' => $arr['processing_objected_at'],
                            'note' => 'Personal data in this pre-account lead record is restricted following your objection to processing.',
                        ];
                    }

                    return $arr;
                })
                ->all();
        }

        $aiAgentRuns = [];
        if (Schema::hasTable('ai_agent_runs')) {
            $aiAgentRuns = DB::table('ai_agent_runs')
                ->where('user_id', $uid)
                ->orderByDesc('started_at')
                ->limit(500)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
        }

        $frontendErrors = [];
        if (Schema::hasTable('frontend_errors')) {
            $frontendErrors = DB::table('frontend_errors')
                ->where('user_id', $uid)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
        }

        $notifications = [];
        if (Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'user_id')) {
            $notifications = DB::table('notifications')
                ->where('user_id', $uid)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
        }

        $workspaceSummary = $this->workspaceSummary($uid);

        return [
            'export' => [
                'generated_at' => now()->toIso8601String(),
                'export_version' => '1',
                'schema' => 'jackpot-user-data-export-v1',
            ],
            'user' => $userRow,
            'memberships' => [
                'tenant_user' => $tenantUsers,
                'brand_user' => $brandUsers,
                'collection_user' => $collectionGrants,
            ],
            'workspace_summary' => $workspaceSummary,
            'sessions' => $sessions,
            'activity_events' => [
                'as_actor' => $activityAsActor,
                'as_subject' => $activityAsSubject,
            ],
            'cookie_consents' => $consents,
            'contact_leads_matching_email' => $contactLeads,
            'ai_agent_runs' => $aiAgentRuns,
            'frontend_errors' => $frontendErrors,
            'notifications' => $notifications,
            'note' => 'Customer Content (assets, files in S3) is not included as bulk binary in this export. Request workspace copies from your organization administrator if needed.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeActivityEvent(ActivityEvent $e): array
    {
        return [
            'id' => $e->id,
            'tenant_id' => $e->tenant_id,
            'brand_id' => $e->brand_id,
            'actor_type' => $e->actor_type,
            'actor_id' => $e->actor_id,
            'event_type' => $e->event_type,
            'subject_type' => $e->subject_type,
            'subject_id' => $e->subject_id,
            'metadata' => $e->metadata,
            'ip_address' => $e->ip_address,
            'user_agent' => $e->user_agent,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceSummary(int $userId): array
    {
        $assetCount = 0;
        if (Schema::hasTable('assets')) {
            $assetCount = (int) DB::table('assets')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->count();
        }

        return [
            'assets_uploaded_count' => $assetCount,
        ];
    }
}
