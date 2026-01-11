<?php

/**
 * AI Configuration
 *
 * Centralized configuration for AI models, agents, and settings.
 * This file defines the AI infrastructure layer that enables provider-agnostic
 * AI operations with comprehensive tracking and cost attribution.
 *
 * Why centralized?
 * - Single source of truth for AI capabilities
 * - Provider abstraction (switch providers without code changes)
 * - Cost tracking and attribution
 * - Permission and safety enforcement
 * - Future extensibility (tenant-level AI, database overrides)
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The default AI provider to use when no provider is specified.
    | Must match a provider implementation in app/Services/AI/Providers/
    |
    */
    'default_provider' => env('AI_DEFAULT_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | AI Models Registry
    |--------------------------------------------------------------------------
    |
    | Registry of available AI models across all providers.
    | Models are defined here first, with optional database overrides later.
    |
    | Each model must include:
    | - provider: Provider identifier (openai, future providers)
    | - model_name: Actual model name used by provider API
    | - capabilities: Array of capabilities (text, reasoning, image, multimodal)
    | - recommended_use: Array of recommended use cases (tagging, audit, reporting, creative)
    | - default_cost_per_token: Array with 'input' and 'output' costs in USD per token
    | - active: Whether the model is currently active/available
    |
    | Cost attribution:
    | - Costs are calculated per agent run
    | - System context: No tenant attribution (system-level cost)
    | - Tenant context: Cost attributed to tenant_id
    | - User context: Cost attributed to both tenant_id and user_id
    |
    */
    'models' => [
        'gpt-4-turbo' => [
            'provider' => 'openai',
            'model_name' => 'gpt-4-turbo-preview',
            'capabilities' => ['text', 'reasoning'],
            'recommended_use' => ['tagging', 'audit', 'reporting', 'creative'],
            'default_cost_per_token' => [
                'input' => 0.00001,  // $0.01 per 1K tokens (gpt-4-turbo input)
                'output' => 0.00003, // $0.03 per 1K tokens (gpt-4-turbo output)
            ],
            'active' => true,
        ],
        'gpt-4' => [
            'provider' => 'openai',
            'model_name' => 'gpt-4',
            'capabilities' => ['text', 'reasoning'],
            'recommended_use' => ['tagging', 'audit', 'reporting', 'creative'],
            'default_cost_per_token' => [
                'input' => 0.00003,  // $0.03 per 1K tokens (gpt-4 input)
                'output' => 0.00006, // $0.06 per 1K tokens (gpt-4 output)
            ],
            'active' => true,
        ],
        'gpt-3.5-turbo' => [
            'provider' => 'openai',
            'model_name' => 'gpt-3.5-turbo',
            'capabilities' => ['text'],
            'recommended_use' => ['tagging', 'reporting'],
            'default_cost_per_token' => [
                'input' => 0.0000005,  // $0.0005 per 1K tokens (gpt-3.5-turbo input)
                'output' => 0.0000015, // $0.0015 per 1K tokens (gpt-3.5-turbo output)
            ],
            'active' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Agents Registry
    |--------------------------------------------------------------------------
    |
    | Definitions of AI agents as logical actors that perform tasks.
    | Agents are defined in config first, with optional database overrides later.
    |
    | Each agent must include:
    | - name: Human-readable name
    | - description: What the agent does
    | - scope: 'system' (system-wide) or 'tenant' (tenant-scoped, future)
    | - default_model: Key from models registry
    | - allowed_actions: Array of actions agent can perform
    |   Options: read, write, create_ticket, upload_asset, generate_image
    | - permissions: Array of Spatie permission names checked at runtime
    |
    | Permissions enforcement:
    | - Permissions are checked by AIService before execution
    | - Uses existing Spatie permission system
    | - System-scoped agents require system permissions
    | - Tenant-scoped agents require tenant-specific permissions (future)
    |
    | All actions are auditable via agent runs stored in ai_agent_runs table.
    |
    */
    'agents' => [
        'ticket_analyzer' => [
            'name' => 'Ticket Analyzer',
            'description' => 'Analyzes support tickets and generates summaries',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read', 'create_ticket'],
            'permissions' => [
                'tickets.view_staff',
                'tickets.create_engineering',
            ],
        ],
        'audit_reporter' => [
            'name' => 'Audit Reporter',
            'description' => 'Generates audit reports from activity logs',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_audit_log',
            ],
        ],
        'performance_analyst' => [
            'name' => 'Performance Analyst',
            'description' => 'Analyzes system performance and generates insights',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                // System-level permissions (may need new permission)
            ],
        ],
        'ticket_summarizer' => [
            'name' => 'Ticket Summarizer',
            'description' => 'Summarizes ticket conversations and extracts key facts',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_staff',
            ],
        ],
        'ticket_classifier' => [
            'name' => 'Ticket Classifier',
            'description' => 'Suggests category, severity, and component for tickets',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_staff',
            ],
        ],
        'sla_risk_analyzer' => [
            'name' => 'SLA Risk Analyzer',
            'description' => 'Analyzes tickets for SLA breach risk',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_sla',
            ],
        ],
        'error_pattern_analyzer' => [
            'name' => 'Error Pattern Analyzer',
            'description' => 'Detects error patterns and suggests internal tickets',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_engineering',
            ],
        ],
        'duplicate_detector' => [
            'name' => 'Duplicate Ticket Detector',
            'description' => 'Detects potential duplicate tickets',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_staff',
            ],
        ],
        'system_reliability_agent' => [
            'name' => 'System Reliability Agent',
            'description' => 'Analyzes system health and generates reliability insights',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                // System-level agent - no specific permissions required
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Controls optional logging of prompts and responses, plus retention policy.
    |
    | store_prompts:
    | - If true, stores prompt and response in agent run metadata JSON field
    | - Can be disabled for cost/privacy reasons
    | - Structured format for easy querying
    |
    | retention_days:
    | - Configurable retention window for agent run logs
    | - Default: 30 days
    | - PruneAILogs command handles deletion (outlined but not implemented yet)
    | - Do NOT store logs indefinitely
    |
    */
    'logging' => [
        'store_prompts' => env('AI_STORE_PROMPTS', false),
        'retention_days' => env('AI_LOG_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Budgets Configuration
    |--------------------------------------------------------------------------
    |
    | Defines default budgets for AI operations.
    | Budgets can be system-wide, per-agent, or per-task type.
    | Database overrides can modify these values without code changes.
    |
    | Budget types:
    | - system: System-wide monthly budget
    | - agents: Per-agent budgets (optional)
    | - tasks: Per-task type budgets (optional)
    |
    | Budget characteristics:
    | - Monthly period (resets on 1st of each month)
    | - Soft limits by default (warn, don't block)
    | - Hard limits optional (must be explicitly enabled)
    | - Warning thresholds (default 80%)
    |
    */
    'budgets' => [
        'system' => [
            'monthly' => [
                'amount' => env('AI_BUDGET_SYSTEM_MONTHLY', 1000.00), // $1000/month default
                'warning_threshold_percent' => 80,
                'hard_limit_enabled' => false,
            ],
        ],
        'agents' => [
            // Per-agent budgets (optional)
            // Example:
            // 'ticket_analyzer' => [
            //     'monthly' => [
            //         'amount' => 500.00,
            //         'warning_threshold_percent' => 80,
            //         'hard_limit_enabled' => false,
            //     ],
            // ],
        ],
        'tasks' => [
            // Per-task type budgets (optional)
            // Example:
            // 'support_ticket_summary' => [
            //     'monthly' => [
            //         'amount' => 200.00,
            //         'warning_threshold_percent' => 80,
            //         'hard_limit_enabled' => false,
            //     ],
            // ],
        ],
    ],
];
