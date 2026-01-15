<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AIAgentRun;
use App\Models\Ticket;
use App\Models\TicketLink;
use App\Models\AlertCandidate;
use App\Models\AlertSummary;
use App\Models\SupportTicket;
use App\Models\DetectionRule;
use App\Services\AIConfigService;
use App\Services\AICostReportingService;
use App\Services\AIBudgetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
    ) {
    }

    /**
     * Display the main AI Dashboard with tabs.
     */
    public function index(): Response
    {
        if (!Auth::user()->can('ai.dashboard.view')) {
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

        // Get tab parameter to determine which tab content to load
        $activeTab = request()->get('tab', 'activity');

        // Load tab-specific data
        $tabContent = [];
        
        if ($activeTab === 'activity') {
            // Load activity data (first page only for initial load)
            $query = AIAgentRun::with(['tenant', 'user'])
                ->orderBy('started_at', 'desc');

            // Apply filters from request
            $request = request();
            if ($request->filled('agent_id')) {
                $query->where('agent_id', $request->agent_id);
            }
            if ($request->filled('model_used')) {
                $query->where('model_used', 'like', '%' . $request->model_used . '%');
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
                $query->where('started_at', '<=', $request->date_to . ' 23:59:59');
            }
            
            $runs = $query->paginate(50)->through(function ($run) {
                $relatedTickets = TicketLink::where('linkable_type', AIAgentRun::class)
                    ->where('linkable_id', $run->id)
                    ->with('ticket:id,ticket_number,subject')
                    ->get()
                    ->pluck('ticket')
                    ->filter();

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
            });

            // Load failed automation jobs
            $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')
                ->where('queue', 'default')
                ->orderBy('failed_at', 'desc')
                ->limit(20)
                ->get()
                ->map(function ($job) {
                    $payload = json_decode($job->payload, true);
                    $displayName = $payload['displayName'] ?? 'Unknown Job';
                    
                    // Only show automation jobs
                    if (!str_contains($displayName, 'App\\Jobs\\Automation\\')) {
                        return null;
                    }
                    
                    // Extract job class name
                    $jobClass = str_replace('App\\Jobs\\Automation\\', '', $displayName);
                    
                    // Extract exception message (first line)
                    $exception = $job->exception;
                    $exceptionLines = explode("\n", $exception);
                    $errorMessage = $exceptionLines[0] ?? 'Unknown error';
                    
                    // Try to extract ticket ID or other context from payload
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
                        'full_exception' => $exception,
                        'ticket_id' => $ticketId,
                    ];
                })
                ->filter()
                ->values();

            $tabContent['activity'] = [
                'runs' => $runs,
                'failedJobs' => $failedJobs,
                'filterOptions' => [
                    'agents' => $this->getAgentOptions(),
                    'models' => $this->getModelOptions(),
                    'task_types' => $this->getTaskTypeOptions(),
                    'environments' => $this->getEnvironmentOptions(),
                ],
            ];
        } elseif ($activeTab === 'models') {
            $tabContent['models'] = [
                'models' => $this->configService->getAllModelsWithOverrides($environment),
            ];
        } elseif ($activeTab === 'agents') {
            $tabContent['agents'] = [
                'agents' => $this->configService->getAllAgentsWithOverrides($environment),
                'availableModels' => array_keys(config('ai.models', [])),
            ];
        } elseif ($activeTab === 'automations') {
            $automations = $this->configService->getAllAutomationsWithOverrides($environment);
            foreach ($automations as &$automation) {
                $agentId = $this->getAgentIdForTrigger($automation['key']);
                if ($agentId) {
                    $lastRun = AIAgentRun::where('agent_id', $agentId)
                        ->orderBy('started_at', 'desc')
                        ->first();
                    $automation['last_triggered_at'] = $lastRun?->started_at?->toISOString();
                } else {
                    $automation['last_triggered_at'] = null;
                }
            }
            $tabContent['automations'] = [
                'automations' => $automations,
            ];
        } elseif ($activeTab === 'reports') {
            $filters = request()->only([
                'start_date',
                'end_date',
                'agent_id',
                'model_used',
                'task_type',
                'triggering_context',
                'environment',
                'group_by',
            ]);
            
            // Remove null/empty values
            $filters = array_filter($filters, fn($value) => $value !== null && $value !== '');
            
            // Default to last 30 days if no date range specified
            if (!isset($filters['start_date'])) {
                $filters['start_date'] = now()->subDays(30)->format('Y-m-d');
            }
            if (!isset($filters['end_date'])) {
                $filters['end_date'] = now()->format('Y-m-d');
            }

            $report = $this->reportingService->generateReport($filters);
            
            $tabContent['reports'] = [
                'report' => $report,
                'filters' => $filters,
                'filterOptions' => [
                    'agents' => $this->getAgentOptions(),
                    'models' => $this->getModelOptions(),
                    'task_types' => $this->getTaskTypeOptions(),
                    'environments' => $this->getEnvironmentOptions(),
                    'contexts' => [
                        ['value' => 'system', 'label' => 'System'],
                        ['value' => 'tenant', 'label' => 'Tenant'],
                        ['value' => 'user', 'label' => 'User'],
                    ],
                ],
            ];
        } elseif ($activeTab === 'alerts') {
            // Phase 5B: Admin Observability UI
            // Load alert candidates with relationships
            $request = request();
            $query = AlertCandidate::with(['rule', 'summary', 'supportTicket', 'tenant']);

            // Default: open alerts only, sorted by severity + last_detected_at
            if (!$request->filled('status')) {
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
            $severityOrder = ['critical' => 3, 'warning' => 2, 'info' => 1];
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
                    'rules' => $detectionRules->map(fn($rule) => ['value' => $rule->id, 'label' => $rule->name])->toArray(),
                    'tenants' => $tenants->map(fn($tenant) => ['value' => $tenant->id, 'label' => $tenant->name])->toArray(),
                ],
            ];
        } elseif ($activeTab === 'budgets' && Auth::user()->can('ai.budgets.view')) {
            $budgets = $this->configService->getAllBudgetsWithOverrides($environment);
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
            $tabContent['budgets'] = [
                'budgets' => $budgets,
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
        if (!Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $query = AIAgentRun::with(['tenant', 'user'])
            ->orderBy('started_at', 'desc');

        // Apply filters
        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }

        if ($request->filled('model_used')) {
            $query->where('model_used', 'like', '%' . $request->model_used . '%');
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
            $query->where('started_at', '<=', $request->date_to . ' 23:59:59');
        }

        $runs = $query->paginate(50)->through(function ($run) {
            // Get related entities (tickets linked to this agent run)
            $relatedTickets = TicketLink::where('linkable_type', AIAgentRun::class)
                ->where('linkable_id', $run->id)
                ->with('ticket:id,ticket_number,subject')
                ->get()
                ->pluck('ticket')
                ->filter();

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
                'related_tickets' => $relatedTickets->map(function ($ticket) {
                    return [
                        'id' => $ticket->id,
                        'ticket_number' => $ticket->ticket_number,
                        'subject' => $ticket->subject,
                    ];
                })->values(),
            ];
        });

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
     * Display AI Models management view.
     */
    public function models(Request $request): Response
    {
        if (!Auth::user()->can('ai.dashboard.view')) {
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
        if (!Auth::user()->can('ai.dashboard.view')) {
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
        if (!Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $environment = $request->get('environment', app()->environment());
        $automations = $this->configService->getAllAutomationsWithOverrides($environment);

        // Get last triggered timestamps for each automation
        foreach ($automations as &$automation) {
            $agentId = $this->getAgentIdForTrigger($automation['key']);
            if ($agentId) {
                $lastRun = AIAgentRun::where('agent_id', $agentId)
                    ->orderBy('started_at', 'desc')
                    ->first();

                $automation['last_triggered_at'] = $lastRun?->started_at?->toISOString();
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
        if (!Auth::user()->can('ai.dashboard.manage')) {
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
        if (!Auth::user()->can('ai.dashboard.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'active' => 'nullable|boolean',
            'default_model' => 'nullable|string',
            'environment' => 'nullable|string',
        ]);

        $override = $this->configService->updateAgentOverride(
            $agentId,
            $validated,
            Auth::user()
        );

        return redirect()->back()->with('success', 'Agent override updated successfully.');
    }

    /**
     * Update or create an automation override.
     */
    public function updateAutomationOverride(Request $request, string $triggerKey)
    {
        if (!Auth::user()->can('ai.dashboard.manage')) {
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
        $models = config('ai.models', []);
        $uniqueModels = AIAgentRun::distinct('model_used')->pluck('model_used')->filter();
        
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
        $uniqueTaskTypes = AIAgentRun::distinct('task_type')->pluck('task_type')->filter();
        
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
        $uniqueEnvironments = AIAgentRun::distinct('environment')->pluck('environment')->filter();
        
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
        if (!Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $filters = $request->only([
            'start_date',
            'end_date',
            'agent_id',
            'model_used',
            'task_type',
            'triggering_context',
            'environment',
            'group_by',
        ]);

        // Default to last 30 days if no date range specified
        if (!isset($filters['start_date'])) {
            $filters['start_date'] = now()->subDays(30)->format('Y-m-d');
        }
        if (!isset($filters['end_date'])) {
            $filters['end_date'] = now()->format('Y-m-d');
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
     * Display AI Budgets management view.
     */
    public function budgets(Request $request): Response
    {
        if (!Auth::user()->can('ai.budgets.view')) {
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
    public function updateBudgetOverride(Request $request, int $budgetId)
    {
        if (!Auth::user()->can('ai.budgets.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'warning_threshold_percent' => 'nullable|integer|min:0|max:100',
            'hard_limit_enabled' => 'nullable|boolean',
            'environment' => 'nullable|string',
        ]);

        $override = $this->configService->updateBudgetOverride(
            $budgetId,
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
        if (!Auth::user()->can('ai.dashboard.manage')) {
            abort(403);
        }

        try {
            \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => $uuid]);
            
            return redirect()->back()->with('success', 'Failed job has been queued for retry.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to retry job: ' . $e->getMessage());
        }
    }

    /**
     * Get detailed information about a specific AI agent run.
     */
    public function showRun(int $id): \Illuminate\Http\JsonResponse
    {
        if (!Auth::user()->can('ai.dashboard.view')) {
            abort(403);
        }

        $run = AIAgentRun::with(['tenant', 'user'])
            ->findOrFail($id);

        // Get related tickets
        $relatedTickets = TicketLink::where('linkable_type', AIAgentRun::class)
            ->where('linkable_id', $run->id)
            ->with('ticket:id,ticket_number,subject')
            ->get()
            ->pluck('ticket')
            ->filter();

        return response()->json([
            'id' => $run->id,
            'timestamp' => $run->started_at->format('Y-m-d H:i:s'),
            'agent_id' => $run->agent_id,
            'agent_name' => $this->getAgentName($run->agent_id),
            'task_type' => $run->task_type,
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
        ]);
    }
}
