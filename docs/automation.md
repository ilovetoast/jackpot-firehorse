# Automation & AI Triggers Documentation

## Overview

Phase 7 implements controlled, system-level automation using AI agents to assist support ticket operations. All automation is auditable, attributable, and safe, with explicit human approval required for all actions that modify ticket data.

## Key Principles

### Automation Principles

1. **Deterministic**: All automation actions are predictable and traceable
2. **Auditable**: Every automation action is logged in `ai_agent_runs` table
3. **Attributable**: All actions are attributed to the system user
4. **Human Override**: Human approval is always required before applying suggestions
5. **Never Auto-Apply**: Suggestions are never automatically applied to tickets

### Safety Constraints

- **Never Auto-Apply**: All suggestions require explicit human approval before applying to tickets
- **Never Auto-Create**: Ticket creation suggestions must be confirmed by admin
- **Never Auto-Link**: Duplicate links require confirmation before creating TicketLink
- **System User Attribution**: All automation actions use system user for audit trail
- **Internal Only**: All AI-generated content (summaries, notes) marked as internal (never visible to tenants)

## Automation Triggers

### 1. Support Ticket Summarization

**Trigger:**
- When a tenant support ticket reaches a configurable message count (default: 5)
- OR when ticket status transitions to `waiting_on_support`

**Behavior:**
- Invokes `ticket_summarizer` AI agent
- Summarizes ticket conversation and extracts key facts
- Identifies potential category/severity signals

**Output:**
- Stores summary as internal-only note (is_internal=true)
- Links AI agent run to ticket via TicketLink
- Does NOT modify tenant-visible content

**Execution:** Asynchronous (queued)

**Configuration:** `config/automation.php` → `triggers.ticket_summarization`

### 2. Ticket Classification Assistance

**Trigger:**
- On ticket creation
- On ticket escalation to engineering

**Behavior:**
- Invokes `ticket_classifier` AI agent
- Suggests category, severity (for engineering tickets), component, environment

**Output:**
- Stores suggestions in `ai_ticket_suggestions` table AND ticket metadata
- Suggestions are visible to staff but never auto-applied
- Requires human approval before applying

**Execution:** Synchronous (inline, critical for immediate UX)

**Configuration:** `config/automation.php` → `triggers.ticket_classification`

### 3. SLA Risk Detection

**Trigger:**
- Periodic hourly scan of open tickets

**Behavior:**
- Invokes `sla_risk_analyzer` AI agent
- Analyzes message velocity, status churn, historical resolution patterns
- Flags tickets at risk of SLA breach

**Output:**
- Internal SLA risk flag stored in ticket metadata
- Internal note with reasoning if high risk detected
- No notifications yet (future phase)

**Execution:** Asynchronous (queued, hourly scheduled)

**Configuration:** `config/automation.php` → `triggers.sla_risk_detection`

**Scheduled Command:** `automation:scan-sla-risks` (runs hourly)

### 4. Error Pattern Detection → Internal Ticket Suggestion

**Trigger:**
- Scheduled hourly scan of error logs, frontend errors, job failures
- Detects repeated error fingerprints (configurable thresholds)

**Behavior:**
- Invokes `error_pattern_analyzer` AI agent
- Groups errors by fingerprint
- Detects spikes (default: 5 errors in 60 minutes)

**Output:**
- Creates ticket creation suggestion (pre-filled with severity, environment, component)
- Links diagnostic data via TicketLink
- Stores suggestion in `ai_ticket_suggestions` table
- Ticket is NOT auto-created - admin confirmation required

**Execution:** Asynchronous (queued, hourly scheduled)

**Configuration:** `config/automation.php` → `triggers.error_pattern_detection`

**Scheduled Command:** `automation:scan-error-patterns` (runs hourly)

### 5. Duplicate Ticket Detection

**Trigger:**
- New ticket creation
- Ticket escalation

**Behavior:**
- Invokes `duplicate_detector` AI agent
- Compares ticket context against recent tickets (last 30 days)
- Suggests potential duplicates

**Output:**
- Suggested ticket links with designation=duplicate (pending confirmation)
- Stores suggestion in `ai_ticket_suggestions` table
- Creates TicketLink records with pending_confirmation=true
- Never auto-links tickets - human confirmation required

**Execution:** Synchronous (inline, critical)

**Configuration:** `config/automation.php` → `triggers.duplicate_detection`

## Execution Model

### Synchronous (Inline)
- Critical triggers that affect immediate user experience
- Examples: Classification on ticket creation, duplicate detection

### Asynchronous (Queued)
- Non-critical triggers that can be delayed slightly
- Examples: Ticket summarization, SLA risk detection

## Cost Management

- All AI agent runs are tracked in `ai_agent_runs` table
- Costs attributed to system (no tenant/user attribution for automation)
- Cost calculation based on token usage and model pricing from `config/ai.php`
- Consider rate limiting if cost becomes a concern

## Error Handling

- All automation jobs handle failures gracefully
- Errors are logged but do NOT block ticket operations
- Retry logic: Max 3 retries for transient failures
- Failed jobs are logged with full error context

## Configuration

### Master Switch

```php
// config/automation.php
'enabled' => env('AUTOMATION_ENABLED', true),
```

### Per-Trigger Configuration

Each trigger can be individually enabled/disabled:

```php
'triggers' => [
    'ticket_summarization' => [
        'enabled' => env('AUTOMATION_TICKET_SUMMARY_ENABLED', true),
        'message_threshold' => env('AUTOMATION_MESSAGE_THRESHOLD', 5),
        'async' => true,
    ],
    // ... other triggers
],
```

### Environment Variables

- `AUTOMATION_ENABLED`: Master switch (default: true)
- `AUTOMATION_TICKET_SUMMARY_ENABLED`: Enable summarization (default: true)
- `AUTOMATION_MESSAGE_THRESHOLD`: Message count threshold (default: 5)
- `AUTOMATION_CLASSIFICATION_ENABLED`: Enable classification (default: true)
- `AUTOMATION_SLA_RISK_ENABLED`: Enable SLA risk detection (default: true)
- `AUTOMATION_ERROR_PATTERNS_ENABLED`: Enable error pattern detection (default: true)
- `AUTOMATION_ERROR_WINDOW_MINUTES`: Time window for error patterns (default: 60)
- `AUTOMATION_ERROR_THRESHOLD`: Error count threshold (default: 5)
- `AUTOMATION_DUPLICATE_ENABLED`: Enable duplicate detection (default: true)

## Viewing AI Suggestions

### In Ticket Detail View

1. Navigate to `/app/admin/support/tickets/{ticket_id}`
2. Scroll to "AI Suggestions" panel (appears if suggestions exist)
3. Review suggestions grouped by type
4. Accept or reject each suggestion
5. For ticket creation suggestions, click "Accept" to create the internal ticket

### In Ticket List View

1. Navigate to `/app/admin/support/tickets`
2. Look for sparkle icon (✨) next to tickets with pending suggestions
3. Click ticket to view suggestions in detail view

### Suggestion Types

- **Classification**: Suggested category, severity, component, environment
- **Duplicate**: Suggested duplicate ticket links
- **Ticket Creation**: Suggested internal ticket from error patterns
- **Severity**: Suggested severity adjustment

## Auditing AI Agent Runs

### View Agent Run History

1. Navigate to ticket detail view
2. Click on "Linked Items" tab
3. Find AI agent run links (link_type = 'ai_agent_run')
4. View cost, tokens, and execution details

### Cost Attribution

- System-level automations: No tenant/user attribution
- All costs tracked in `ai_agent_runs` table
- Cost calculated based on model pricing and token usage
- View costs in suggestion panel (each suggestion shows estimated cost)

## What AI Does Automatically

The following actions happen automatically without human approval:

1. **Summarization**: Creates internal notes with ticket summaries
2. **Classification Suggestions**: Generates suggestions (not applied)
3. **SLA Risk Flags**: Stores risk flags in ticket metadata
4. **Error Pattern Suggestions**: Creates ticket creation suggestions (not created)

## What Always Requires Human Approval

The following actions ALWAYS require explicit human approval:

1. **Applying Classification**: Accepting category/severity/component suggestions
2. **Creating Tickets**: Confirming ticket creation from error patterns
3. **Linking Duplicates**: Confirming duplicate ticket links
4. **Applying Severity**: Accepting severity adjustment suggestions

## Permissions

### View Suggestions
- Site Support, Site Admin, Site Owner, Site Engineering: Can view all suggestions
- Compliance: Read-only access (cannot accept/reject)

### Accept/Reject Suggestions
- Site Support, Site Admin, Site Owner, Site Engineering: Can accept/reject suggestions
- Compliance: Cannot accept/reject (read-only)

### Create Tickets from Suggestions
- Site Admin, Site Owner: Can create tickets from error pattern suggestions
- Site Engineering: Can create tickets from error pattern suggestions
- Site Support: Cannot create tickets (can only manage tenant tickets)

## Troubleshooting

### Suggestions Not Appearing

1. Check if automation is enabled: `AUTOMATION_ENABLED=true`
2. Check if specific trigger is enabled in `config/automation.php`
3. Check AI agent run logs for errors
4. Verify AI provider API key is configured

### Suggestions Not Being Generated

1. Check queue is running: `php artisan queue:listen`
2. Check scheduled commands are running: `php artisan schedule:run`
3. Review logs: `storage/logs/laravel.log`
4. Check AI provider connectivity and API limits

### High Costs

1. Review `ai_agent_runs` table for cost breakdown
2. Adjust automation thresholds in `config/automation.php`
3. Disable specific triggers if not needed
4. Consider using lower-cost models in `config/ai.php`

## Future Enhancements

The following are explicitly NOT in scope for Phase 7:

- Tenant-facing AI features
- Real-time notifications
- Background learning loops
- Autonomous decision-making
- SLA configuration UI
- Tenant AI billing

These may be implemented in future phases based on requirements and feedback.
