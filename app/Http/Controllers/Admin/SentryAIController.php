<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SentryIssue;
use App\Services\SentryAI\SentryAIAnalyzer;
use App\Services\SentryAI\SentryAIConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Control Center for AI Error Monitoring (Sentry issues).
 *
 * Admin only. No tenant scoping. No auto-heal (stub only).
 */
class SentryAIController extends Controller
{
    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        if (! $isSiteOwner && ! $isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }
    }

    public function index(Request $request, SentryAIConfigService $config): Response
    {
        $this->authorizeAdmin();

        $pullEnabled = $config->pullEnabled();
        $autoHealEnabled = $config->autoHealEnabled();
        $requireConfirmation = $config->requireConfirmation();
        $model = $config->model();
        $emergencyDisable = $config->isEmergencyDisabled();
        $monthlyLimit = $config->monthlyLimit();
        $environment = $config->environment();

        $currentMonthCost = (float) SentryIssue::query()
            ->whereNotNull('ai_cost')
            ->whereYear('updated_at', now()->year)
            ->whereMonth('updated_at', now()->month)
            ->sum('ai_cost');

        $lastSyncAt = Cache::get('sentry_ai.last_sync_at');

        $issues = SentryIssue::query()
            ->orderBy('last_seen', 'desc')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SentryIssue $issue) => [
                'id' => $issue->id,
                'sentry_issue_id' => $issue->sentry_issue_id,
                'level' => $issue->level,
                'title' => $issue->title,
                'occurrence_count' => $issue->occurrence_count,
                'environment' => $issue->environment,
                'last_seen' => $issue->last_seen?->toIso8601String(),
                'status' => $issue->status,
                'selected_for_heal' => $issue->selected_for_heal,
                'confirmed_for_heal' => $issue->confirmed_for_heal,
                'ai_summary' => $issue->ai_summary,
                'ai_root_cause' => $issue->ai_root_cause,
                'ai_fix_suggestion' => $issue->ai_fix_suggestion,
                'stack_trace' => $issue->stack_trace,
                'ai_token_input' => $issue->ai_token_input,
                'ai_token_output' => $issue->ai_token_output,
                'ai_cost' => $issue->ai_cost !== null ? (string) $issue->ai_cost : null,
                'ai_analyzed_at' => $issue->ai_analyzed_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/AIErrorMonitoring/Index', [
            'config' => [
                'pull_enabled' => $pullEnabled,
                'auto_heal_enabled' => $autoHealEnabled,
                'require_confirmation' => $requireConfirmation,
                'model' => $model,
                'emergency_disable' => $emergencyDisable,
                'monthly_ai_limit' => $monthlyLimit,
                'current_month_cost' => $currentMonthCost,
                'environment' => $environment,
                'last_sync_at' => $lastSyncAt,
            ],
            'issues' => $issues,
        ]);
    }

    public function toggleHeal(SentryIssue $issue): \Illuminate\Http\JsonResponse
    {
        $this->authorizeAdmin();
        $issue->update(['selected_for_heal' => ! $issue->selected_for_heal]);

        return response()->json(['selected_for_heal' => $issue->selected_for_heal]);
    }

    public function dismiss(SentryIssue $issue): \Illuminate\Http\JsonResponse
    {
        $this->authorizeAdmin();
        $issue->update(['status' => 'dismissed']);

        return response()->json(['status' => 'dismissed']);
    }

    public function resolve(SentryIssue $issue): \Illuminate\Http\JsonResponse
    {
        $this->authorizeAdmin();
        $issue->update(['status' => 'resolved']);

        return response()->json(['status' => 'resolved']);
    }

    public function reanalyze(SentryIssue $issue, SentryAIAnalyzer $analyzer): \Illuminate\Http\JsonResponse
    {
        $this->authorizeAdmin();
        $issue->refresh();
        $ran = $analyzer->analyze($issue);

        return response()->json(['analyzed' => $ran]);
    }

    public function confirm(SentryIssue $issue): \Illuminate\Http\JsonResponse
    {
        $this->authorizeAdmin();
        $issue->update(['confirmed_for_heal' => true]);

        return response()->json(['confirmed_for_heal' => true]);
    }

    public function bulkAction(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorizeAdmin();
        $validated = $request->validate([
            'action' => ['required', Rule::in(['resolve', 'dismiss'])],
            'ids' => ['required', 'array'],
            'ids.*' => ['required', 'uuid', 'exists:sentry_issues,id'],
        ]);

        $action = $validated['action'];
        $status = $action === 'resolve' ? 'resolved' : 'dismissed';
        SentryIssue::whereIn('id', $validated['ids'])->update(['status' => $status]);

        return response()->json(['updated' => count($validated['ids']), 'status' => $status]);
    }
}
