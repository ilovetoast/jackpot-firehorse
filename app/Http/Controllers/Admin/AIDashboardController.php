<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AITaskType;
use App\Http\Controllers\Controller;
use App\Models\AIAgentRun;
use App\Models\AlertCandidate;
use App\Models\Asset;
use App\Models\DetectionRule;
use App\Models\Ticket;
use App\Services\AIBudgetService;
use App\Services\AIConfigService;
use App\Services\AI\AIStudioPlatformFeatures;
use App\Services\AICostReportingService;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI Dashboard Controller
 *
 * Provides admin interface for observing and managing AI operations.
 * System-facing only - no tenant-facing features.
 *
 * Features:
 * - AI Activity observability (read-only)
 * - AI Models management (with DB overrides)
 * - AI Agents management (with DB overrides)
 * - AI Automations & Triggers management (with DB overrides)
 *
 * Authorization:
 * - All methods check AIDashboardPolicy
 * - ai.dashboard.view: Read-only access
 * - ai.dashboard.manage: Can edit overrides
 */
class AIDashboardController extends Controller
{
    public function __construct(
        protected AIConfigService $configService,
        protected AICostReportingService $reportingService,
        protected AIBudgetService $budgetService
    ) {}

    /**
     * Eager loads for paginated admin AI run lists (avoids N+1 on ticket_links per run).
     *
     * @return array<string, mixed>
     */
    protected function eagerLoadsForAdminAiRunList(): array
    {
        return [
            'tenant',
            'user',
            'tickets' => function ($q) {
                $q->with('ticket:id,ticket_number,subject');
            },
        ];
    }

    /**
     * Tickets linked to a run (Ticket models), using preloaded {@see AIAgentRun::tickets} when present.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Ticket>
     */
    protected function relatedTicketsForAiAgentRun(AIAgentRun $run): \Illuminate\Support\Collection
    {
        return $run->tickets->pluck('ticket')->filter();
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatAdminAiActivityRun(AIAgentRun $run): array
    {
        $relatedTickets = $this->relatedTicketsForAiAgentRun($run);

        return [
            'id' => $run->id,
            'timestamp' => $run->started_at->format('Y-m-d H:i:s'),
            'agent_id' => $run->agent_id,
            'agent_name' => $this->getAgentName($run->agent_id),
            'task_type' => $run->task_type,
            'triggering_context' => $run->triggering_context,
            'model_used' => $run->model_used,
            'tokens_in' => $run->tokens_in,
            'tokens_out' => $run->tokens_out,
            'estimated_cost' => $run->estimated_cost,
            'status' => $run->status,
            'error_message' => $run->error_message,
            'blocked_reason' => $run->blocked_reason,
            'duration' => $run->formatted_duration,
            'tenant' => $run->tenant ? [
                'id' => $run->tenant->id,
                'name' => $run->tenant->name,
            ] : null,
            'user' => $run->user ? [
                'id' => $run->user->id,
                'name' => $run->user->name,
                'email' => $run->user->email,
            ] : null,
            'environment' => $run->environment,
            'related_tickets' => $relatedTickets->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'subject' => $ticket->subject,
                ];
            })->values(),
        ];
    }

    /**
     * Display the main AI Dashboard with tabs.
     */
    public function index(): Response|RedirectResponse
    {
        if (! Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $environment = app()->environment();

        // Get summary stats
        $stats = [
            'total_runs' => AIAgentRun::count(),
            'successful_runs' => AIAgentRun::where('status', 'success')->count(),
            'failed_runs' => AIAgentRun::where('status', 'failed')->count(),
            'total_cost' => (float) (AIAgentRun::sum('estimated_cost') ?? 0),
            'total_tokens_in' => (int) (AIAgentRun::sum('tokens_in') ?? 0),
            'total_tokens_out' => (int) (AIAgentRun::sum('tokens_out') ?? 0),
        ];

        // Get budget status
        $systemBudget = $this->budgetService->getSystemBudget($environment);
        $budgetStatus = null;
        $budgetRemaining = null;
        $currentMonthCost = null;
        $costTrends = null;
        $costSpikes = null;

        if ($systemBudget) {
            $budgetStatus = $this->budgetService->getBudgetStatus($systemBudget, $environment);
            $budgetRemaining = $systemBudget->getRemaining($environment);

            // Get current month cost
            $currentMonthStart = now()->startOfMonth();
            $currentMonthEnd = now()->endOfMonth();
            $currentMonthCost = AIAgentRun::whereBetween('started_at', [$currentMonthStart, $currentMonthEnd])
                ->sum('estimated_cost');

            // Get cost trends (last 7 days)
            $costTrends = $this->reportingService->getCostTrends('day', 7);

            // Detect cost spikes
            $costSpikes = $this->reportingService->detectCostSpikes(50);
        }

        // Legacy ?tab= URLs -> canonical routes (preserve other query params).
        $tabQuery = request()->query('tab');
        $legacyTabRedirects = [
            'activity' => 'admin.ai.activity',
            'models' => 'admin.ai.models',
            'agents' => 'admin.ai.agents',
            'automations' => 'admin.ai.automations',
            'reports' => 'admin.ai.reports',
        ];
        if (is_string($tabQuery) && isset($legacyTabRedirects[$tabQuery])) {
            return redirect()->route($legacyTabRedirects[$tabQuery], request()->except('tab'));
        }
        if ($tabQuery === 'budgets') {
            if (! Auth::user()->can('ai.budgets.view')) {
                return redirect()->route('admin.ai.index', request()->except('tab'));
            }

            return redirect()->route('admin.ai.budgets', request()->except('tab'));
        }

        $activeTab = request()->get('tab', 'overview');
        if (! is_string($activeTab)) {
            $activeTab = 'overview';
        }
        if (! in_array($activeTab, ['overview', 'alerts'], true)) {
            return redirect()->route('admin.ai.index', request()->except('tab'));
        }

        $tabContent = [];

        if ($activeTab === 'overview') {
            $overviewRuns = AIAgentRun::query()
                ->forAdminActivityList()
                ->with($this->eagerLoadsForAdminAiRunList())
                ->orderByDesc('id')
                ->paginate(12)
                ->through(fn (AIAgentRun $run) => $this->formatAdminAiActivityRun($run));

            $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')
                ->where('queue', 'default')
                ->orderBy('failed_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($job) {
                    $payload = json_decode($job->payload, true);
                    $displayName = $payload['displayName'] ?? 'Unknown Job';

                    if (! str_contains($displayName, 'App\\Jobs\\Automation\\')) {
                        return null;
                    }

                    $jobClass = str_replace('App\\Jobs\\Automation\\', '', $displayName);

                    $exception = $job->exception;
                    $exceptionLines = explode("\n", $exception);
                    $errorMessage = $exceptionLines[0] ?? 'Unknown error';

                    $command = $payload['data']['command'] ?? null;
                    $ticketId = null;
                    if ($command) {
                        $unserialized = unserialize($command);
                        if (is_object($unserialized) && property_exists($unserialized, 'ticketId')) {
                            $ticketId = $unserialized->ticketId;
                        }
                    }

                    return [
                        'id' => $job->id,
                        'uuid' => $job->uuid,
                        'job_class' => $jobClass,
                        'job_name' => $displayName,
                        'failed_at' => \Carbon\Carbon::parse($job->failed_at)->format('Y-m-d H:i:s'),
                        'error_message' => $errorMessage,
                        'full_exception' => mb_substr($exception, 0, 12000),
                        'ticket_id' => $ticketId,
                    ];
                })
                ->filter()
                ->values();

            $tabContent['activity'] = [
                'runs' => $overviewRuns,
                'failedJobs' => $failedJobs,
                'filterOptions' => [
                    'agents' => $this->getAgentOptions(),
                    'models' => $this->getModelOptions(),
                    'task_types' => $this->getTaskTypeOptions(),
                    'environments' => $this->getEnvironmentOptions(),
                ],
            ];
        } elseif ($activeTab === 'alerts') {
            // Phase 5B: Admin Observability UI
            // Load alert candidates with relationships
            $request = request();
            $query = AlertCandidate::with(['rule', 'summary', 'supportTicket', 'tenant']);

            // Default: open alerts only, sorted by severity + last_detected_at
            if (! $request->filled('status')) {
                $query->where('status', 'open');
            }

            // Apply filters
            if ($request->filled('severity')) {
                $query->where('severity', $request->severity);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('scope')) {
                $query->where('scope', $request->scope);
            }
            if ($request->filled('rule_id')) {
                $query->where('rule_id', $request->rule_id);
            }
            if ($request->filled('has_ticket')) {
                if ($request->has_ticket === 'yes') {
                    $query->has('supportTicket');
                } else {
                    $query->doesntHave('supportTicket');
                }
            }
            if ($request->filled('has_summary')) {
                if ($request->has_summary === 'yes') {
                    $query->has('summary');
                } else {
                    $query->doesntHave('summary');
                }
            }
            if ($request->filled('ticket_status')) {
                if ($request->ticket_status === 'none') {
                    $query->doesntHave('supportTicket');
                } else {
                    $query->whereHas('supportTicket', function ($q) use ($request) {
                        $q->where('status', $request->ticket_status);
                    });
                }
            }
            if ($request->filled('tenant_id')) {
                $query->where('tenant_id', $request->tenant_id);
            }

            // Sort by severity (critical > warning > info) then last_detected_at (desc)
            $query->orderByRaw("FIELD(severity, 'critical', 'warning', 'info') DESC")
                ->orderBy('last_detected_at', 'desc');

            $alerts = $query->paginate(50)->through(function ($alert) {
                return [
                    'id' => $alert->id,
                    'rule_id' => $alert->rule_id,
                    'rule_name' => $alert->rule->name ?? 'Unknown Rule',
                    'scope' => $alert->scope,
                    'subject_id' => $alert->subject_id,
                    'tenant_id' => $alert->tenant_id,
                    'tenant_name' => $alert->tenant->name ?? null,
                    'severity' => $alert->severity,
                    'observed_count' => $alert->observed_count,
                    'threshold_count' => $alert->threshold_count,
                    'window_minutes' => $alert->window_minutes,
                    'status' => $alert->status,
                    'first_detected_at' => $alert->first_detected_at->toIso8601String(),
                    'last_detected_at' => $alert->last_detected_at->toIso8601String(),
                    'detection_count' => $alert->detection_count,
                    'context' => $alert->context,
                    'has_summary' => $alert->summary !== null,
                    'summary' => $alert->summary ? [
                        'summary_text' => $alert->summary->summary_text,
                        'impact_summary' => $alert->summary->impact_summary,
                        'affected_scope' => $alert->summary->affected_scope,
                        'suggested_actions' => $alert->summary->suggested_actions ?? [],
                        'confidence_score' => $alert->summary->confidence_score,
                    ] : null,
                    'has_ticket' => $alert->supportTicket !== null,
                    'ticket' => $alert->supportTicket ? [
                        'id' => $alert->supportTicket->id,
                        'summary' => $alert->supportTicket->summary,
                        'status' => $alert->supportTicket->status,
                        'severity' => $alert->supportTicket->severity,
                        'source' => $alert->supportTicket->source,
                    ] : null,
                ];
            });

            // Get filter options
            $detectionRules = DetectionRule::orderBy('name')->get(['id', 'name']);
            $tenants = \App\Models\Tenant::whereIn('id', AlertCandidate::distinct()->pluck('tenant_id')->filter())
                ->orderBy('name')
                ->get(['id', 'name']);

            $tabContent['alerts'] = [
                'alerts' => $alerts,
                'filterOptions' => [
                    'severities' => [
                        ['value' => 'info', 'label' => 'Info'],
                        ['value' => 'warning', 'label' => 'Warning'],
                        ['value' => 'critical', 'label' => 'Critical'],
                    ],
                    'statuses' => [
                        ['value' => 'open', 'label' => 'Open'],
                        ['value' => 'acknowledged', 'label' => 'Acknowledged'],
                        ['value' => 'resolved', 'label' => 'Resolved'],
                    ],
                    'scopes' => [
                        ['value' => 'global', 'label' => 'Global'],
                        ['value' => 'tenant', 'label' => 'Tenant'],
                        ['value' => 'asset', 'label' => 'Asset'],
                        ['value' => 'download', 'label' => 'Download'],
                    ],
                    'rules' => $detectionRules->map(fn ($rule) => ['value' => $rule->id, 'label' => $rule->name])->toArray(),
                    'tenants' => $tenants->map(fn ($tenant) => ['value' => $tenant->id, 'label' => $tenant->name])->toArray(),
                ],
            ];
        }

        return Inertia::render('Admin/AI/Index', [
            'stats' => $stats,
            'environment' => $environment,
            'canManage' => Auth::user()->can('ai.dashboard.manage'),
            'budgetStatus' => $budgetStatus,
            'budgetRemaining' => $budgetRemaining,
            'currentMonthCost' => $currentMonthCost,
            'costTrends' => $costTrends,
            'costSpikes' => $costSpikes,
            'canViewBudgets' => Auth::user()->can('ai.budgets.view'),
            'activeTab' => $activeTab,
            'tabContent' => $tabContent,
        ]);
    }

    /**
     * Display AI Activity timeline/table.
     */
    public function activity(Request $request): Response
    {
        if (! Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $query = AIAgentRun::query()
            ->forAdminActivityList()
            ->with($this->eagerLoadsForAdminAiRunList())
            ->orderByDesc('id');

        // Apply filters
        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }

        if ($request->filled('model_used')) {
            $query->where('model_used', 'like', '%'.$request->model_used.'%');
        }

        if ($request->filled('task_type')) {
            $query->where('task_type', $request->task_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('environment')) {
            $query->where('environment', $request->environment);
        }

        if ($request->filled('date_from')) {
            $query->where('started_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('started_at', '<=', $request->date_to.' 23:59:59');
        }

        $runs = $query->paginate(50)->through(fn (AIAgentRun $run) => $this->formatAdminAiActivityRun($run));

        // Get filter options
        $filterOptions = [
            'agents' => $this->getAgentOptions(),
            'models' => $this->getModelOptions(),
            'task_types' => $this->getTaskTypeOptions(),
            'environments' => $this->getEnvironmentOptions(),
        ];

        return Inertia::render('Admin/AI/Activity', [
            'runs' => $runs,
            'filters' => $request->only(['agent_id', 'model_used', 'task_type', 'status', 'environment', 'date_from', 'date_to']),
            'filterOptions' => $filterOptions,
        ]);
    }

    /**
     * Image / Studio media AI audit: canvas generative, editor edit, presentation preview, and
     * Studio composition video (Kling, etc.) — structured `generative_audit` on each run where recorded.
     */
    public function editorImageAudit(Request $request): Response
    {
        if (! Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $imageAuditTaskTypes = [
            'editor_generative_image',
            'editor_edit_image',
            AITaskType::THUMBNAIL_PRESENTATION_PREVIEW,
            AITaskType::STUDIO_COMPOSITION_ANIMATION,
        ];

        $allowedAgentIds = ['editor_generative_image', 'editor_edit_image', 'presentation_preview', 'studio_animate_composition'];

        $query = AIAgentRun::query()
            ->forAdminActivityList()
            ->whereIn('task_type', $imageAuditTaskTypes)
            ->with($this->eagerLoadsForAdminAiRunList())
            ->orderByDesc('id');

        if ($request->filled('agent_id')) {
            $aid = (string) $request->agent_id;
            if (in_array($aid, $allowedAgentIds, true)) {
                $query->where('agent_id', $aid);
            }
        }

        if ($request->filled('model_used')) {
            $query->where('model_used', 'like', '%'.$request->model_used.'%');
        }

        if ($request->filled('task_type')) {
            $tt = (string) $request->task_type;
            if (in_array($tt, $imageAuditTaskTypes, true)) {
                $query->where('task_type', $tt);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('environment')) {
            $query->where('environment', $request->environment);
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', (int) $request->tenant_id);
        }

        if ($request->filled('date_from')) {
            $query->where('started_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('started_at', '<=', $request->date_to.' 23:59:59');
        }

        $runs = $query->paginate(50)->through(function ($run) {
            $relatedTickets = $this->relatedTicketsForAiAgentRun($run);

            $ga = is_array($run->metadata) ? ($run->metadata['generative_audit'] ?? null) : null;
            $auditSummary = null;
            if (is_array($ga)) {
                $auditSummary = [
                    'audit_kind' => $ga['audit_kind'] ?? null,
                    'registry_model_key' => $ga['registry_model_key'] ?? null,
                    'provider' => $ga['provider'] ?? null,
                    'prompt_preview' => isset($ga['prompt_preview']) ? mb_substr((string) $ga['prompt_preview'], 0, 160) : null,
                    'generative_layer_uuid' => $ga['generative_layer_uuid'] ?? null,
                    'composition_id' => $ga['composition_id'] ?? null,
                    'source_asset_id' => $ga['source_asset_id'] ?? null,
                    'studio_animation_job_id' => $ga['studio_animation_job_id'] ?? null,
                    'output_asset_id' => $ga['output_asset_id'] ?? null,
                    'credits_charged' => $ga['credits_charged'] ?? null,
                    'output_duration_seconds' => $ga['output_duration_seconds'] ?? null,
                    'has_audit' => true,
                ];
            } else {
                $auditSummary = ['has_audit' => false];
            }

            return [
                'id' => $run->id,
                'timestamp' => $run->started_at->format('Y-m-d H:i:s'),
                'agent_id' => $run->agent_id,
                'agent_name' => $this->getAgentName($run->agent_id),
                'task_type' => $run->task_type,
                'triggering_context' => $run->triggering_context,
                'model_used' => $run->model_used,
                'tokens_in' => $run->tokens_in,
                'tokens_out' => $run->tokens_out,
                'estimated_cost' => $run->estimated_cost,
                'status' => $run->status,
                'error_message' => $run->error_message,
                'duration' => $run->formatted_duration,
                'tenant' => $run->tenant ? [
                    'id' => $run->tenant->id,
                    'name' => $run->tenant->name,
                ] : null,
                'user' => $run->user ? [
                    'id' => $run->user->id,
                    'name' => $run->user->name,
                    'email' => $run->user->email,
                ] : null,
                'environment' => $run->environment,
                'audit_summary' => $auditSummary,
                'related_tickets' => $relatedTickets->map(function ($ticket) {
                    return [
                        'id' => $ticket->id,
                        'ticket_number' => $ticket->ticket_number,
                        'subject' => $ticket->subject,
                    ];
                })->values(),
            ];
        });

        $filterOptions = [
            'agents' => [
                ['value' => 'editor_generative_image', 'label' => $this->getAgentName('editor_generative_image')],
                ['value' => 'editor_edit_image', 'label' => $this->getAgentName('editor_edit_image')],
                ['value' => 'presentation_preview', 'label' => $this->getAgentName('presentation_preview')],
                ['value' => 'studio_animate_composition', 'label' => $this->getAgentName('studio_animate_composition')],
            ],
            'models' => $this->getModelOptions(),
            'task_types' => [
                ['value' => 'editor_generative_image', 'label' => 'editor_generative_image'],
                ['value' => 'editor_edit_image', 'label' => 'editor_edit_image'],
                ['value' => AITaskType::THUMBNAIL_PRESENTATION_PREVIEW, 'label' => AITaskType::THUMBNAIL_PRESENTATION_PREVIEW],
                ['value' => AITaskType::STUDIO_COMPOSITION_ANIMATION, 'label' => AITaskType::STUDIO_COMPOSITION_ANIMATION],
            ],
            'environments' => $this->getEnvironmentOptions(),
        ];

        return Inertia::render('Admin/AI/EditorImageAudit', [
            'runs' => $runs,
            'filters' => $request->only([
                'agent_id', 'model_used', 'task_type', 'status', 'environment',
                'date_from', 'date_to', 'tenant_id',
            ]),
            'filterOptions' => $filterOptions,
        ]);
    }

    /**
     * Display AI Models management view.
     */
    public function models(Request $request): Response
    {
        if (! Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $environment = $request->get('environment', app()->environment());
        $models = $this->configService->getAllModelsWithOverrides($environment);

        return Inertia::render('Admin/AI/Models', [
            'models' => $models,
            'environment' => $environment,
            'canManage' => Auth::user()->can('ai.dashboard.manage'),
        ]);
    }

    /**
     * Display AI Agents management view.
     */
    public function agents(Request $request): Response
    {
        if (! Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $environment = $request->get('environment', app()->environment());
        $agents = $this->configService->getAllAgentsWithOverrides($environment);

        $availableModels = array_keys(config('ai.models', []));

        return Inertia::render('Admin/AI/Agents', [
            'agents' => $agents,
            'environment' => $environment,
            'availableModels' => $availableModels,
            'canManage' => Auth::user()->can('ai.dashboard.manage'),
        ]);
    }

    /**
     * Display AI Automations & Triggers management view.
     */
    public function automations(Request $request): Response
    {
        if (! Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $environment = $request->get('environment', app()->environment());
        $automations = $this->configService->getAllAutomationsWithOverrides($environment);

        // Get last triggered timestamps for each automation
        foreach ($automations as &$automation) {
            $agentId = $this->getAgentIdForTrigger($automation['key']);
            if ($agentId) {
                $startedAt = AIAgentRun::query()
                    ->where('agent_id', $agentId)
                    ->orderByDesc('started_at')
                    ->value('started_at');
                $automation['last_triggered_at'] = $startedAt
                    ? \Carbon\Carbon::parse($startedAt)->toISOString()
                    : null;
            } else {
                $automation['last_triggered_at'] = null;
            }
        }

        return Inertia::render('Admin/AI/Automations', [
            'automations' => $automations,
            'environment' => $environment,
            'canManage' => Auth::user()->can('ai.dashboard.manage'),
        ]);
    }

    /**
     * Update or create a model override.
     */
    public function updateModelOverride(Request $request, string $modelKey)
    {
        if (! Auth::user()->can('ai.dashboard.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'active' => 'nullable|boolean',
            'default_for_tasks' => 'nullable|array',
            'default_for_tasks.*' => 'string',
            'environment' => 'nullable|string',
        ]);

        $override = $this->configService->updateModelOverride(
            $modelKey,
            $validated,
            Auth::user()
        );

        return redirect()->back()->with('success', 'Model override updated successfully.');
    }

    /**
     * Update or create an agent override.
     */
    public function updateAgentOverride(Request $request, string $agentId)
    {
        if (! Auth::user()->can('ai.dashboard.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'active' => 'nullable|boolean',
            'default_model' => 'nullable|string',
            'environment' => 'nullable|string',
        ]);

        try {
            $this->configService->updateAgentOverride(
                $agentId,
                $validated,
                Auth::user()
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['default_model' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Agent override updated successfully.');
    }

    /**
     * Update or create an automation override.
     */
    public function updateAutomationOverride(Request $request, string $triggerKey)
    {
        if (! Auth::user()->can('ai.dashboard.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'enabled' => 'nullable|boolean',
            'thresholds' => 'nullable|array',
            'environment' => 'nullable|string',
        ]);

        $override = $this->configService->updateAutomationOverride(
            $triggerKey,
            $validated,
            Auth::user()
        );

        return redirect()->back()->with('success', 'Automation override updated successfully.');
    }

    /**
     * Get agent name from config.
     */
    protected function getAgentName(string $agentId): string
    {
        $config = config("ai.agents.{$agentId}");

        return $config['name'] ?? $agentId;
    }

    /**
     * Get agent options for filter dropdown.
     */
    protected function getAgentOptions(): array
    {
        $agents = config('ai.agents', []);

        return collect($agents)->map(function ($config, $id) {
            return [
                'value' => $id,
                'label' => $config['name'] ?? $id,
            ];
        })->values()->toArray();
    }

    /**
     * Get model options for filter dropdown.
     */
    protected function getModelOptions(): array
    {
        // Distinct values only — avoid pluck without tight query on multi-million-row tables (memory + sort buffer).
        $uniqueModels = AIAgentRun::query()
            ->select('model_used')
            ->whereNotNull('model_used')
            ->where('model_used', '!=', '')
            ->groupBy('model_used')
            ->orderBy('model_used')
            ->pluck('model_used');

        return $uniqueModels->map(function ($model) {
            return [
                'value' => $model,
                'label' => $model,
            ];
        })->values()->toArray();
    }

    /**
     * Get task type options for filter dropdown.
     */
    protected function getTaskTypeOptions(): array
    {
        $uniqueTaskTypes = AIAgentRun::query()
            ->select('task_type')
            ->whereNotNull('task_type')
            ->where('task_type', '!=', '')
            ->groupBy('task_type')
            ->orderBy('task_type')
            ->pluck('task_type');

        return $uniqueTaskTypes->map(function ($taskType) {
            return [
                'value' => $taskType,
                'label' => $taskType,
            ];
        })->values()->toArray();
    }

    /**
     * Get environment options for filter dropdown.
     */
    protected function getEnvironmentOptions(): array
    {
        $uniqueEnvironments = AIAgentRun::query()
            ->select('environment')
            ->whereNotNull('environment')
            ->where('environment', '!=', '')
            ->groupBy('environment')
            ->orderBy('environment')
            ->pluck('environment');

        return $uniqueEnvironments->map(function ($env) {
            return [
                'value' => $env,
                'label' => ucfirst($env),
            ];
        })->values()->toArray();
    }

    /**
     * Get agent ID for a given trigger key.
     * Maps automation triggers to their associated agents.
     */
    protected function getAgentIdForTrigger(string $triggerKey): ?string
    {
        $mapping = [
            'ticket_summarization' => 'ticket_summarizer',
            'ticket_classification' => 'ticket_classifier',
            'sla_risk_detection' => 'sla_risk_analyzer',
            'error_pattern_detection' => 'error_pattern_analyzer',
            'duplicate_detection' => 'duplicate_detector',
        ];

        return $mapping[$triggerKey] ?? null;
    }

    /**
     * Display AI Cost Reports.
     */
    public function reports(Request $request): Response
    {
        if (! Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $filters = $request->only([
            'start_date',
            'end_date',
            'range_preset',
            'agent_id',
            'model_used',
            'task_type',
            'triggering_context',
            'environment',
            'group_by',
        ]);

        $allowedRangePresets = \App\Services\AICostReportingService::RANGE_PRESETS;
        $rangePreset = $filters['range_preset'] ?? null;
        if (is_string($rangePreset) && in_array($rangePreset, $allowedRangePresets, true)) {
            unset($filters['start_date'], $filters['end_date']);
        } else {
            unset($filters['range_preset']);
            // Default to last 30 days if no calendar range specified
            if (! isset($filters['start_date'])) {
                $filters['start_date'] = now()->subDays(30)->format('Y-m-d');
            }
            if (! isset($filters['end_date'])) {
                $filters['end_date'] = now()->format('Y-m-d');
            }
        }

        $report = $this->reportingService->generateReport($filters);

        // Get filter options
        $filterOptions = [
            'agents' => $this->getAgentOptions(),
            'models' => $this->getModelOptions(),
            'task_types' => $this->getTaskTypeOptions(),
            'environments' => $this->getEnvironmentOptions(),
            'contexts' => [
                ['value' => 'system', 'label' => 'System'],
                ['value' => 'tenant', 'label' => 'Tenant'],
                ['value' => 'user', 'label' => 'User'],
            ],
        ];

        return Inertia::render('Admin/AI/Reports', [
            'report' => $report,
            'filters' => $filters,
            'filterOptions' => $filterOptions,
            'environment' => app()->environment(),
        ]);
    }

    /**
     * Studio platform feature toggles (segmentation, background fill, still → video).
     */
    public function studioPlatformFeatures(Request $request): Response
    {
        if (! Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $environment = $request->get('environment', app()->environment());
        $features = app(AIStudioPlatformFeatures::class)->adminPayload($environment);

        return Inertia::render('Admin/AI/StudioPlatformFeatures', [
            'features' => $features,
            'environment' => $environment,
            'canManage' => Auth::user()->can('ai.dashboard.manage'),
        ]);
    }

    /**
     * Persist a Studio platform feature toggle for the given environment.
     */
    public function updateStudioPlatformFeature(Request $request): RedirectResponse
    {
        if (! Auth::user()->can('ai.dashboard.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'feature_key' => 'required|string|max:96',
            'enabled' => 'required|boolean',
            'environment' => 'nullable|string|max:64',
        ]);

        $allowed = array_keys(config('ai_studio_platform_features.features', []));
        if (! in_array($validated['feature_key'], $allowed, true)) {
            abort(422, 'Unknown feature key.');
        }

        $environment = $validated['environment'] ?? app()->environment();
        app(AIStudioPlatformFeatures::class)->setEnabled(
            $validated['feature_key'],
            (bool) $validated['enabled'],
            Auth::user(),
            $environment
        );

        return redirect()->back()->with('success', 'Studio feature setting saved.');
    }

    /**
     * Display AI Budgets management view.
     */
    public function budgets(Request $request): Response
    {
        if (! Auth::user()->can('ai.budgets.view')) {
            abort(403);
        }

        $environment = $request->get('environment', app()->environment());
        $budgets = $this->configService->getAllBudgetsWithOverrides($environment);

        // Get budget status and usage for each budget
        foreach ($budgets as &$budget) {
            if ($budget['id']) {
                $budgetModel = \App\Models\AIBudget::find($budget['id']);
                if ($budgetModel) {
                    $budget['status'] = $this->budgetService->getBudgetStatus($budgetModel, $environment);
                    $budget['current_usage'] = $budgetModel->getCurrentUsage($environment);
                    $budget['effective_amount'] = $budgetModel->getEffectiveAmount($environment);
                    $budget['remaining'] = $budgetModel->getRemaining($environment);
                    $budget['warning_threshold'] = $budgetModel->getEffectiveWarningThreshold($environment);
                    $budget['hard_limit_enabled'] = $budgetModel->isHardLimitEnabled($environment);
                }
            }
        }

        return Inertia::render('Admin/AI/Budgets', [
            'budgets' => $budgets,
            'environment' => $environment,
            'canManage' => Auth::user()->can('ai.budgets.manage'),
        ]);
    }

    /**
     * Update or create a budget override.
     */
    public function updateBudgetOverride(Request $request, string $budgetId): \Illuminate\Http\RedirectResponse
    {
        if (! Auth::user()->can('ai.budgets.manage')) {
            abort(403);
        }

        $budgetIdInt = filter_var($budgetId, FILTER_VALIDATE_INT);
        if ($budgetIdInt === false || $budgetIdInt < 1) {
            abort(404);
        }

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'warning_threshold_percent' => 'nullable|integer|min:0|max:100',
            'hard_limit_enabled' => 'nullable|boolean',
            'environment' => 'nullable|string',
        ]);

        $override = $this->configService->updateBudgetOverride(
            $budgetIdInt,
            $validated,
            Auth::user()
        );

        return redirect()->back()->with('success', 'Budget override updated successfully.');
    }

    /**
     * Retry a failed queue job.
     */
    public function retryFailedJob(string $uuid): \Illuminate\Http\RedirectResponse
    {
        if (! Auth::user()->can('ai.dashboard.manage')) {
            abort(403);
        }

        try {
            \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => $uuid]);

            return redirect()->back()->with('success', 'Failed job has been queued for retry.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to retry job: '.$e->getMessage());
        }
    }

    /**
     * Get detailed information about a specific AI agent run.
     */
    public function showRun(string $id): \Illuminate\Http\JsonResponse
    {
        if (! Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $idInt = filter_var($id, FILTER_VALIDATE_INT);
        if ($idInt === false || $idInt < 1) {
            abort(404);
        }

        $run = AIAgentRun::with($this->eagerLoadsForAdminAiRunList())
            ->findOrFail($idInt);

        $relatedTickets = $this->relatedTicketsForAiAgentRun($run);

        $sourceAssetSummary = $this->sourceAssetSummaryForAiRun($run);
        $outputVideoAsset = $this->outputVideoAssetSummaryForAiRun($run);

        return response()->json([
            'id' => $run->id,
            'timestamp' => $run->started_at->format('Y-m-d H:i:s'),
            'agent_id' => $run->agent_id,
            'agent_name' => $this->getAgentName($run->agent_id),
            'task_type' => $run->task_type,
            'entity_type' => $run->entity_type,
            'entity_id' => $run->entity_id,
            'triggering_context' => $run->triggering_context,
            'model_used' => $run->model_used,
            'tokens_in' => $run->tokens_in,
            'tokens_out' => $run->tokens_out,
            'total_tokens' => $run->total_tokens,
            'estimated_cost' => $run->estimated_cost,
            'status' => $run->status,
            'error_message' => $run->error_message,
            'blocked_reason' => $run->blocked_reason,
            'duration' => $run->formatted_duration,
            'started_at' => $run->started_at->toISOString(),
            'completed_at' => $run->completed_at?->toISOString(),
            'environment' => $run->environment,
            'tenant' => $run->tenant ? [
                'id' => $run->tenant->id,
                'name' => $run->tenant->name,
            ] : null,
            'user' => $run->user ? [
                'id' => $run->user->id,
                'name' => $run->user->name,
                'email' => $run->user->email,
            ] : null,
            'related_tickets' => $relatedTickets->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'subject' => $ticket->subject,
                ];
            })->values(),
            'metadata' => $run->metadata, // Includes prompts and responses if store_prompts is enabled
            'source_asset' => $sourceAssetSummary,
            'output_asset' => $outputVideoAsset,
        ]);
    }

    /**
     * For Studio composition video completions: link the output MP4 in DAM and optional signed playback URL.
     *
     * @return array<string, mixed>|null
     */
    protected function outputVideoAssetSummaryForAiRun(AIAgentRun $run): ?array
    {
        $meta = is_array($run->metadata) ? $run->metadata : [];
        $ga = is_array($meta['generative_audit'] ?? null) ? $meta['generative_audit'] : [];
        if (($ga['audit_kind'] ?? null) !== 'studio_composition_animation') {
            return null;
        }

        $rawId = $ga['output_asset_id'] ?? $meta['asset_id'] ?? null;
        if ($rawId === null || $rawId === '') {
            return null;
        }

        $assetId = is_string($rawId) ? trim($rawId) : (string) $rawId;
        $asset = Asset::query()->find($assetId);
        if (! $asset) {
            return [
                'id' => $assetId,
                'missing' => true,
                'admin_url' => url('/app/admin/assets/'.$assetId),
            ];
        }

        $playbackUrl = null;
        $posterUrl = null;
        if (is_string($asset->mime_type) && str_starts_with($asset->mime_type, 'video/')) {
            try {
                $playbackUrl = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED);
            } catch (\Throwable $e) {
                Log::debug('[AIDashboardController] output video playback URL failed', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        try {
            $posterUrl = $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED);
        } catch (\Throwable $e) {
            Log::debug('[AIDashboardController] output video poster URL failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'id' => $asset->id,
            'title' => $asset->title ?: $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'admin_url' => url('/app/admin/assets/'.$asset->id),
            'playback_url' => $playbackUrl !== '' ? $playbackUrl : null,
            'poster_url' => $posterUrl !== '' ? $posterUrl : null,
        ];
    }

    /**
     * Best-effort link + thumbnail for the raster that was sent into image-edit flows.
     * Raw bytes are not stored on the run ({@see AIService::buildMetadata}); use asset when known.
     *
     * @return array<string, mixed>|null
     */
    protected function sourceAssetSummaryForAiRun(AIAgentRun $run): ?array
    {
        $meta = is_array($run->metadata) ? $run->metadata : [];
        $ga = is_array($meta['generative_audit'] ?? null) ? $meta['generative_audit'] : [];
        $req = is_array($meta['editor_admin_request'] ?? null) ? $meta['editor_admin_request'] : [];

        $rawId = $ga['source_asset_id'] ?? $req['asset_id'] ?? null;
        if (($rawId === null || $rawId === '') && $run->entity_type === 'asset' && $run->entity_id !== null && $run->entity_id !== '') {
            $rawId = $run->entity_id;
        }
        if ($rawId === null || $rawId === '') {
            return null;
        }

        $assetId = is_string($rawId) ? trim($rawId) : (string) $rawId;

        $asset = Asset::query()->find($assetId);
        if (! $asset) {
            return [
                'id' => $assetId,
                'missing' => true,
                'admin_url' => url('/app/admin/assets/'.$assetId),
            ];
        }

        $thumbnailUrl = null;
        try {
            $thumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED);
        } catch (\Throwable $e) {
            Log::debug('[AIDashboardController] source asset thumbnail URL failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'id' => $asset->id,
            'title' => $asset->title ?: $asset->original_filename,
            'thumbnail_url' => $thumbnailUrl,
            'admin_url' => url('/app/admin/assets/'.$asset->id),
        ];
    }

    /**
     * GET /admin/ai/studio-usage
     *
     * Read-only daily rollups for Studio (composition) activity — same pattern as
     * ai_usage: bounded rows (days × metrics × tenants), not per-save events.
     * Query: ?days=30 (1–365). Optional ?tenant_id= for one workspace.
     */
    public function studioUsage(Request $request): JsonResponse
    {
        if (! Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }
        if (! Schema::hasTable('studio_usage_daily')) {
            return response()->json([
                'days' => 0,
                'rows' => [],
                'note' => 'Table studio_usage_daily not found — run migrations.',
            ]);
        }

        $days = min(365, max(1, (int) $request->query('days', 30)));
        $since = now()->subDays($days - 1)->startOfDay()->toDateString();
        $tenantId = $request->query('tenant_id');

        $q = DB::table('studio_usage_daily')
            ->where('usage_date', '>=', $since);
        if ($tenantId !== null && $tenantId !== '' && ctype_digit((string) $tenantId)) {
            $q->where('tenant_id', (int) $tenantId);
        }

        $rows = $q
            ->select([
                'usage_date',
                'metric',
                DB::raw('SUM(event_count) as events'),
                DB::raw('SUM(sum_duration_ms) as sum_duration_ms'),
                DB::raw('SUM(sum_cost_usd) as sum_cost_usd'),
            ])
            ->groupBy('usage_date', 'metric')
            ->orderByDesc('usage_date')
            ->orderBy('metric')
            ->get()
            ->map(function ($r) {
                $events = (int) $r->events;
                $sumMs = (int) $r->sum_duration_ms;

                return [
                    'usage_date' => $r->usage_date,
                    'metric' => $r->metric,
                    'events' => $events,
                    'sum_duration_ms' => $sumMs,
                    'avg_duration_ms' => $events > 0 ? (int) round($sumMs / $events) : null,
                    'sum_cost_usd' => round((float) $r->sum_cost_usd, 6),
                ];
            });

        return response()->json([
            'days' => $days,
            'since' => $since,
            'rows' => $rows,
        ]);
    }
}
