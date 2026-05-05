<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpAiQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Site admin diagnostics for in-app Help AI (all tenants).
 */
class HelpAiDiagnosticsController extends Controller
{
    public function index(Request $request): Response
    {
        if (! Auth::user()?->can('ai.dashboard.view')) {
            abort(403);
        }

        $days = min(90, max(7, (int) $request->query('days', 30)));

        $since = now()->subDays($days);

        $base = HelpAiQuestion::query()->where('created_at', '>=', $since);

        $totals = [
            'asks' => (clone $base)->count(),
            'no_strong_match' => (clone $base)->where('response_kind', 'no_strong_match')->count(),
            'ai_success' => (clone $base)->where('response_kind', 'ai')->count(),
            'ai_failed' => (clone $base)->where('response_kind', 'ai_failed')->count(),
            'ai_disabled' => (clone $base)->where('response_kind', 'ai_disabled')->count(),
            'feature_disabled' => (clone $base)->where('response_kind', 'feature_disabled')->count(),
            'feedback_helpful' => (clone $base)->where('feedback_rating', 'helpful')->count(),
            'feedback_not_helpful' => (clone $base)->where('feedback_rating', 'not_helpful')->count(),
        ];

        $totalCost = (float) (clone $base)->where('response_kind', 'ai')->sum('cost');

        $recent = HelpAiQuestion::query()
            ->with([
                'tenant:id,name,slug',
                'user:id,email,first_name,last_name',
                'brand:id,name',
            ])
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit(60)
            ->get()
            ->map(fn (HelpAiQuestion $r) => [
                'id' => $r->id,
                'created_at' => $r->created_at?->toIso8601String(),
                'tenant' => $r->tenant ? ['id' => $r->tenant->id, 'name' => $r->tenant->name, 'slug' => $r->tenant->slug] : null,
                'user_email' => $r->user?->email,
                'brand_name' => $r->brand?->name,
                'question' => $r->question,
                'response_kind' => $r->response_kind,
                'best_score' => $r->best_score,
                'confidence' => $r->confidence,
                'matched_action_keys' => $r->matched_action_keys ?? [],
                'recommended_action_key' => $r->recommended_action_key,
                'cost' => $r->cost !== null ? (float) $r->cost : null,
                'feedback_rating' => $r->feedback_rating,
            ]);

        $noMatchSample = HelpAiQuestion::query()
            ->with(['tenant:id,name'])
            ->where('created_at', '>=', $since)
            ->where('response_kind', 'no_strong_match')
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (HelpAiQuestion $r) => [
                'id' => $r->id,
                'created_at' => $r->created_at?->toIso8601String(),
                'tenant_name' => $r->tenant?->name,
                'question' => $r->question,
                'best_score' => $r->best_score,
                'matched_action_keys' => $r->matched_action_keys ?? [],
            ]);

        $failures = HelpAiQuestion::query()
            ->with(['tenant:id,name'])
            ->where('created_at', '>=', $since)
            ->where('response_kind', 'ai_failed')
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (HelpAiQuestion $r) => [
                'id' => $r->id,
                'created_at' => $r->created_at?->toIso8601String(),
                'tenant_name' => $r->tenant?->name,
                'question' => $r->question,
                'recommended_action_key' => $r->recommended_action_key,
            ]);

        $matchedRows = HelpAiQuestion::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('matched_action_keys')
            ->latest()
            ->limit(4000)
            ->get(['matched_action_keys']);

        $topMatched = $this->aggregateMatchedKeys($matchedRows);

        $patterns = HelpAiQuestion::query()
            ->selectRaw('question, COUNT(*) as c')
            ->where('created_at', '>=', $since)
            ->whereIn('response_kind', ['no_strong_match', 'ai_failed'])
            ->groupBy('question')
            ->orderByDesc('c')
            ->limit(20)
            ->get()
            ->map(fn ($row) => ['question' => $row->question, 'count' => (int) $row->c]);

        return Inertia::render('Admin/AI/HelpDiagnostics', [
            'days' => $days,
            'totals' => $totals,
            'total_cost_estimate' => round($totalCost, 6),
            'recent' => $recent,
            'no_match_sample' => $noMatchSample,
            'failures' => $failures,
            'top_matched_actions' => $topMatched,
            'unanswered_patterns' => $patterns,
        ]);
    }

    /**
     * @param  Collection<int, HelpAiQuestion>  $rows
     * @return list<array{key: string, count: int}>
     */
    private function aggregateMatchedKeys(Collection $rows): array
    {
        $freq = [];
        foreach ($rows as $r) {
            $keys = $r->matched_action_keys ?? [];
            if (! is_array($keys)) {
                continue;
            }
            foreach ($keys as $k) {
                if (! is_string($k) || $k === '') {
                    continue;
                }
                $freq[$k] = ($freq[$k] ?? 0) + 1;
            }
        }
        arsort($freq);
        $out = [];
        foreach (array_slice($freq, 0, 25, true) as $key => $count) {
            $out[] = ['key' => $key, 'count' => $count];
        }

        return $out;
    }
}
