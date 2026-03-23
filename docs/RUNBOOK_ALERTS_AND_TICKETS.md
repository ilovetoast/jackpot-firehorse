# Operational Runbook: Alerts and Tickets System

**Last Updated:** 2024  
**Status:** Active  
**Phases Covered:** Phase 4, Phase 5A, Phase 5B, Stabilization A2

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Normal Pipeline Flow](#normal-pipeline-flow)
3. [Known Failure Modes & Outcomes](#known-failure-modes--outcomes)
4. [Safe Recovery Steps](#safe-recovery-steps)
5. [Dev Commands for Validation](#dev-commands-for-validation)
6. [What NOT to Do](#what-not-to-do)
7. [Monitoring & Health Checks](#monitoring--health-checks)
8. [Rate Caps & Safety Limits](#rate-caps--safety-limits)

---

## System Overview

The Alerts and Tickets system provides:

- **Event Aggregation:** Raw activity events → time-bucketed aggregates
- **Pattern Detection:** Declarative rules evaluate aggregates → detection results
- **Alert Candidates:** Detected patterns → persistent alert records
- **AI Summaries:** Human-readable explanations of alerts
- **Support Tickets:** Automatic or manual ticket creation from alerts
- **Admin Visibility:** Read-only UI for observing alerts, summaries, and tickets

**Key Principle:** The system is **read-only** for event consumption. Events flow forward through the pipeline; detection rules and alert management are separate concerns.

---

## Normal Pipeline Flow

### End-to-End Flow

```
1. Activity Events (activity_events table)
   ↓
2. Event Aggregation (AggregateEventsJob)
   → event_aggregates
   → asset_event_aggregates
   → download_event_aggregates
   ↓
3. Pattern Detection (PatternDetectionService)
   → Evaluates DetectionRules against aggregates
   → Returns matched results
   ↓
4. Alert Candidate Creation (AlertCandidateService)
   → Creates/updates AlertCandidate records
   → Rate cap check (STABILIZATION A2)
   ↓
5. Auto Ticket Creation (AutoTicketCreationService)
   → Evaluates TicketCreationRules
   → Creates SupportTicket if rules match
   → Rate cap check (STABILIZATION A2)
   ↓
6. AI Summary Generation (AlertSummaryService)
   → Generates human-readable summaries
   → Falls back to stub if AI unavailable
   ↓
7. Admin Visibility (Admin UI - Phase 5B)
   → View alerts, summaries, tickets
   → Acknowledge/resolve alerts
```

### Scheduled Jobs

**AggregateEventsJob:**
- Runs periodically (via scheduler or manual dispatch)
- Processes events in time windows (default: 15 minutes)
- Idempotent (safe to re-run on same window)
- Updates `event_aggregation:last_processed_at` cache key

**Recommended Schedule:**
```php
// In app/Console/Kernel.php or schedule definition
$schedule->job(new AggregateEventsJob())
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

### Manual Triggering

**Run aggregation manually:**
```bash
php artisan queue:work --queue=default --tries=3
# Or dispatch job:
php artisan tinker
>>> \App\Jobs\AggregateEventsJob::dispatch();
```

**Run pattern detection:**
```bash
php artisan tinker
>>> $service = app(\App\Services\PatternDetectionService::class);
>>> $results = $service->evaluateAllRules();
>>> $results->count(); // Number of matches
```

---

## Known Failure Modes & Outcomes

### 1. Event Aggregation Failures

**Symptom:** Aggregates not created or incomplete

**Possible Causes:**
- Job queue not processing
- Database connectivity issues
- Large event volume causing timeouts
- Cache issues with `last_processed_at` tracking

**Outcome:**
- No aggregates → No pattern matches → No alerts
- Partial aggregates → Some rules may match, others won't
- System continues operating (events are not lost)

**Detection:**
```sql
-- Check recent aggregates
SELECT COUNT(*) FROM event_aggregates 
WHERE bucket_start_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- Check if aggregation is processing
SELECT * FROM jobs WHERE queue = 'default' AND failed_at IS NULL;

-- Check cache for last processed timestamp
SELECT * FROM cache WHERE `key` LIKE 'event_aggregation:%';
```

### 2. Pattern Detection Not Matching

**Symptom:** Rules enabled but no alerts created

**Possible Causes:**
- Thresholds not met (aggregate counts below threshold)
- Time window misalignment (events outside detection window)
- Metadata filters excluding all aggregates
- Rule disabled

**Outcome:**
- No AlertCandidates created
- System continues operating normally

**Detection:**
```sql
-- Check enabled rules
SELECT * FROM detection_rules WHERE enabled = 1;

-- Check recent aggregates vs thresholds
SELECT dr.name, dr.threshold_count, COUNT(ea.id) as aggregate_count
FROM detection_rules dr
LEFT JOIN event_aggregates ea ON ea.event_type = dr.event_type
WHERE dr.enabled = 1
AND ea.bucket_start_at >= DATE_SUB(NOW(), INTERVAL dr.threshold_window_minutes MINUTE)
GROUP BY dr.id;
```

### 3. Alert Rate Cap Exceeded

**Symptom:** Warning logs about alert suppression

**Cause:** Tenant has exceeded `alerts.max_per_tenant_per_hour` (default: 100)

**Outcome:**
- New AlertCandidates for that tenant are suppressed
- Warning logged (not an error)
- Existing alerts remain
- System continues operating

**Detection:**
```bash
# Check logs for suppression warnings
tail -f storage/logs/laravel.log | grep "Alert creation suppressed"

# Count alerts created in current hour per tenant
SELECT tenant_id, COUNT(*) as alert_count
FROM alert_candidates
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY tenant_id;
```

### 4. Ticket Rate Cap Exceeded

**Symptom:** Warning logs, alerts have `_suppression` in context

**Cause:** System-wide ticket creation exceeded `tickets.max_auto_create_per_hour` (default: 50)

**Outcome:**
- Ticket creation suppressed (skipped)
- AlertCandidate remains open
- Suppression metadata stored in `alert_candidates.context._suppression`
- System continues operating

**Detection:**
```bash
# Check logs
tail -f storage/logs/laravel.log | grep "Ticket creation suppressed"

# Check suppression metadata
SELECT id, context->>'$._suppression.reason' as suppression_reason
FROM alert_candidates
WHERE JSON_EXTRACT(context, '$._suppression') IS NOT NULL;

# Count tickets created in current hour
SELECT COUNT(*) FROM support_tickets
WHERE source = 'system'
AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

### 5. AI Summary Generation Failures

**Symptom:** Alerts without summaries, or stub summaries only

**Possible Causes:**
- AI service unavailable or misconfigured
- Budget exceeded (if configured)
- Agent not configured (`alert_summarizer`)

**Outcome:**
- Stub summary generated (non-blocking)
- Alert remains functional
- Can be regenerated later

**Detection:**
```sql
-- Check alerts without summaries
SELECT ac.id, ac.severity, ac.created_at
FROM alert_candidates ac
LEFT JOIN alert_summaries asum ON asum.alert_candidate_id = ac.id
WHERE asum.id IS NULL;

-- Check stub summaries (confidence_score = null or 0)
SELECT COUNT(*) FROM alert_summaries WHERE confidence_score IS NULL;
```

### 6. Database Performance Issues

**Symptom:** Slow queries, timeouts

**Possible Causes:**
- Large event_events table (not partitioned)
- Missing indexes
- Long-running aggregation jobs

**Outcome:**
- Delayed aggregation → Delayed alerts
- System continues but may lag

**Detection:**
```sql
-- Check table sizes
SELECT 
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
AND table_name IN ('activity_events', 'event_aggregates', 'alert_candidates')
ORDER BY size_mb DESC;

-- Check slow queries
SHOW PROCESSLIST;
```

---

## Safe Recovery Steps

### Recovery: Aggregation Lag

**If aggregates are behind:**

1. **Check job queue status:**
   ```bash
   php artisan queue:work --queue=default --tries=3
   ```

2. **Manually trigger aggregation for a time window:**
   ```bash
   php artisan tinker
   >>> $start = \Carbon\Carbon::now()->subHours(2);
   >>> $end = \Carbon\Carbon::now();
   >>> \App\Jobs\AggregateEventsJob::dispatch($start, $end);
   ```

3. **Verify aggregates created:**
   ```sql
   SELECT COUNT(*) FROM event_aggregates 
   WHERE bucket_start_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR);
   ```

4. **Run pattern detection:**
   ```bash
   php artisan tinker
   >>> $service = app(\App\Services\PatternDetectionService::class);
   >>> $results = $service->evaluateAllRules();
   ```

### Recovery: Missing Alerts

**If alerts should exist but don't:**

1. **Verify rules are enabled:**
   ```sql
   SELECT * FROM detection_rules WHERE enabled = 1;
   ```

2. **Check if aggregates exist for rule's event_type:**
   ```sql
   SELECT dr.name, COUNT(ea.id) as aggregate_count
   FROM detection_rules dr
   LEFT JOIN event_aggregates ea ON ea.event_type = dr.event_type
   WHERE dr.enabled = 1
   AND ea.bucket_start_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
   GROUP BY dr.id;
   ```

3. **Manually run pattern detection:**
   ```bash
   php artisan tinker
   >>> $service = app(\App\Services\PatternDetectionService::class);
   >>> $results = $service->evaluateAllRules();
   >>> // Process results via AlertCandidateService
   >>> $alertService = app(\App\Services\AlertCandidateService::class);
   >>> $alertService->processDetectionResults($results);
   ```

### Recovery: Rate Cap Suppression

**If alerts/tickets are being suppressed:**

1. **Check current rates:**
   ```sql
   -- Alert rate per tenant
   SELECT tenant_id, COUNT(*) as count
   FROM alert_candidates
   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
   GROUP BY tenant_id;
   
   -- Ticket rate (system-wide)
   SELECT COUNT(*) as count
   FROM support_tickets
   WHERE source = 'system'
   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);
   ```

2. **Temporary adjustment (if needed):**
   ```bash
   # In .env or config
   ALERTS_MAX_PER_TENANT_PER_HOUR=200
   TICKETS_MAX_AUTO_CREATE_PER_HOUR=100
   
   # Reload config
   php artisan config:clear
   ```

3. **Monitor logs:**
   ```bash
   tail -f storage/logs/laravel.log | grep -E "(suppressed|rate cap)"
   ```

**Note:** Rate caps are safety features. Increasing them should be done cautiously and monitored.

### Recovery: Missing AI Summaries

**If summaries are missing:**

1. **Check AI service configuration:**
   ```bash
   php artisan tinker
   >>> config('ai.agents.alert_summarizer'); // Should exist
   ```

2. **Manually regenerate summaries:**
   ```bash
   php artisan tinker
   >>> $alert = \App\Models\AlertCandidate::find(123);
   >>> $service = app(\App\Services\AlertSummaryService::class);
   >>> $summary = $service->generateSummary($alert, true); // Force regenerate
   ```

3. **Check AI budget/service availability:**
   - Verify AI provider credentials
   - Check budget limits
   - Review AI service logs

### Recovery: Stuck or Failed Jobs

**If jobs are failing:**

1. **Check failed jobs:**
   ```bash
   php artisan queue:failed
   ```

2. **Retry failed jobs:**
   ```bash
   php artisan queue:retry all
   # Or specific job:
   php artisan queue:retry {job-id}
   ```

3. **Clear failed jobs (if safe):**
   ```bash
   php artisan queue:flush
   ```

4. **Restart queue workers:**
   ```bash
   php artisan queue:restart
   ```

---

## Dev Commands for Validation

### Generate Test Alert Data

**Command:** `php artisan dev:generate-alert`

**Purpose:** Generate end-to-end alert pipeline data for testing

**Usage:**
```bash
# Basic usage
php artisan dev:generate-alert --tenant=1

# Specific rule
php artisan dev:generate-alert --tenant=1 --rule=5

# Custom parameters
php artisan dev:generate-alert --tenant=1 --count=10 --window=30 --severity=critical
```

**What it does:**
1. Generates fake `activity_events` matching a DetectionRule
2. Runs aggregation for the time window
3. Runs pattern detection
4. Creates AlertCandidate
5. Creates SupportTicket (if rules allow)
6. Generates AlertSummary

**Output:**
- Created events count
- AlertCandidate ID
- SupportTicket ID (if created)
- Summary status (AI or stub)

**Use Cases:**
- Testing alert pipeline end-to-end
- Validating detection rules
- Testing ticket creation rules
- UI testing with realistic data

**Limitations:**
- Dev-only (aborts in production)
- Events are tagged with `_dev_generated: true`
- May need to adjust event count to meet thresholds

### Verify Pipeline Health

**Check aggregation status:**
```bash
php artisan tinker
>>> $service = app(\App\Services\EventAggregationService::class);
>>> $start = \Carbon\Carbon::now()->subHours(1);
>>> $end = \Carbon\Carbon::now();
>>> $stats = $service->aggregateTimeWindow($start, $end);
>>> print_r($stats);
```

**Check detection rules:**
```bash
php artisan tinker
>>> $rules = \App\Models\DetectionRule::enabled()->get();
>>> $rules->each(fn($r) => echo "{$r->name}: {$r->event_type}\n");
```

**Check recent alerts:**
```bash
php artisan tinker
>>> $alerts = \App\Models\AlertCandidate::orderBy('created_at', 'desc')->limit(10)->get();
>>> $alerts->each(fn($a) => echo "Alert #{$a->id}: {$a->rule->name} ({$a->status})\n");
```

---

## What NOT to Do

### Locked Phases - DO NOT MODIFY

**Phase 4 is LOCKED:**
- ❌ DO NOT modify event emission (Phase 2.5, 3.1)
- ❌ DO NOT modify aggregation schema or logic
- ❌ DO NOT change detection rule evaluation logic
- ❌ DO NOT modify AlertCandidate creation logic (except safety caps)
- ❌ DO NOT modify AlertSummary generation logic

**Phase 5A is LOCKED:**
- ❌ DO NOT modify SupportTicket schema
- ❌ DO NOT change ticket creation rules logic
- ❌ DO NOT modify external adapter interface (unless implementing new adapter)

**Phase 5B is LOCKED:**
- ❌ DO NOT modify admin UI alert/ticket display logic
- ❌ DO NOT change alert lifecycle actions (acknowledge/resolve)

### Operational Restrictions

**DO NOT:**
- ❌ Manually delete `activity_events` (append-only audit trail)
- ❌ Modify `event_aggregates` directly (use aggregation jobs)
- ❌ Delete AlertCandidates to "fix" issues (use acknowledge/resolve)
- ❌ Disable rate caps without monitoring impact
- ❌ Change detection thresholds without understanding impact
- ❌ Modify alert context structure (breaks UI expectations)

**DO:**
- ✅ Use acknowledge/resolve actions for alert lifecycle
- ✅ Adjust rate caps via config if needed (monitor impact)
- ✅ Enable/disable rules as needed
- ✅ Review logs for suppression warnings
- ✅ Use dev commands for testing

---

## Monitoring & Health Checks

### Key Metrics to Monitor

**Event Aggregation:**
- Events processed per hour
- Aggregates created per hour
- Job queue length
- Aggregation lag (time since last processed event)

**Pattern Detection:**
- Rules evaluated per run
- Matches found per run
- Average evaluation time

**Alert Creation:**
- Alerts created per hour (per tenant and total)
- Rate cap suppressions per hour
- Alert status distribution (open/acknowledged/resolved)

**Ticket Creation:**
- Tickets auto-created per hour
- Rate cap suppressions per hour
- Ticket status distribution

**AI Summaries:**
- Summaries generated per hour
- AI vs stub summary ratio
- Summary generation failures

### Health Check Queries

**Check if aggregation is running:**
```sql
SELECT 
    COUNT(*) as events_pending,
    MAX(created_at) as latest_event
FROM activity_events
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

**Check alert pipeline health:**
```sql
SELECT 
    COUNT(*) as open_alerts,
    COUNT(DISTINCT tenant_id) as affected_tenants,
    SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_count
FROM alert_candidates
WHERE status = 'open';
```

**Check rate cap status:**
```sql
-- Per-tenant alert rate
SELECT 
    tenant_id,
    COUNT(*) as alerts_this_hour,
    (SELECT value FROM cache WHERE `key` = 'alerts.max_per_tenant_per_hour') as cap
FROM alert_candidates
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY tenant_id;
```

### Log Monitoring

**Key log patterns to watch:**
```bash
# Aggregation issues
tail -f storage/logs/laravel.log | grep -E "\[EventAggregationService\]|\[AggregateEventsJob\]"

# Rate cap suppressions
tail -f storage/logs/laravel.log | grep -E "suppressed|rate cap"

# Pattern detection
tail -f storage/logs/laravel.log | grep -E "\[PatternDetectionService\]"

# Alert/ticket creation
tail -f storage/logs/laravel.log | grep -E "\[AlertCandidateService\]|\[AutoTicketCreationService\]"
```

---

## Rate Caps & Safety Limits

### Alert Rate Cap

**Config:** `alerts.max_per_tenant_per_hour` (default: 100)

**Purpose:** Prevent alert storms from a single tenant

**Behavior:**
- Counts new AlertCandidates created in current hour per tenant
- When exceeded: Suppresses new alert creation, logs warning
- Existing alerts remain
- Processing continues normally

**Adjustment:**
```env
# .env
ALERTS_MAX_PER_TENANT_PER_HOUR=200
```

**Disable:**
```env
ALERTS_MAX_PER_TENANT_PER_HOUR=0
```

### Ticket Rate Cap

**Config:** `alerts.tickets.max_auto_create_per_hour` (default: 50)

**Purpose:** Prevent ticket storms system-wide

**Behavior:**
- Counts system-source tickets created in current hour (all tenants)
- When exceeded: Suppresses ticket creation, logs warning, updates alert context
- Alerts remain open (tickets can be created manually later)
- Processing continues normally

**Adjustment:**
```env
# .env
TICKETS_MAX_AUTO_CREATE_PER_HOUR=100
```

**Disable:**
```env
TICKETS_MAX_AUTO_CREATE_PER_HOUR=0
```

### Best Practices

1. **Monitor Before Adjusting:** Review actual rates before increasing caps
2. **Gradual Increases:** Increase caps incrementally, monitor impact
3. **Document Changes:** Record why caps were adjusted and expected impact
4. **Review Suppressions:** Regular review of suppression logs to identify patterns
5. **Consider Root Cause:** High suppression rates may indicate underlying issues

---

## Troubleshooting Quick Reference

| Symptom | Check | Action |
|---------|-------|--------|
| No alerts created | Rules enabled? Aggregates exist? | Enable rules, verify aggregation running |
| Alerts suppressed | Rate cap exceeded? | Check logs, adjust cap if appropriate |
| Tickets not created | Ticket rules enabled? Alert meets requirements? | Verify rules, check detection_count |
| Aggregates missing | Queue processing? Jobs failed? | Check queue, retry failed jobs |
| AI summaries missing | AI service available? Budget exceeded? | Check AI config, regenerate summaries |
| Slow performance | Table sizes? Indexes? | Check query performance, review indexes |

---

## Emergency Contacts

**For Critical Issues:**
- Review logs: `storage/logs/laravel.log`
- Check queue: `php artisan queue:failed`
- Database status: Check table sizes and query performance
- AI service: Verify credentials and budget

**System Recovery:**
- Aggregation can be re-run safely (idempotent)
- Pattern detection can be re-run safely (read-only)
- Alerts can be acknowledged/resolved via admin UI
- Tickets can be created manually if auto-creation suppressed

---

**Last Updated:** 2024  
**Maintainer:** Engineering Team  
**Related Docs:** 
- This file (merged Phase 4, 5A, 5B below)
- `docs/DEV_TOOLING.md`

---

# Merged phase reference: analytics, support tickets, admin UI

The following sections preserve the former `PHASE_4`, `PHASE_5A`, and `PHASE_5B` documents in full.

## Source: PHASE_4_ANALYTICS_FOUNDATIONS.md


**Status:** 🔨 IN PROGRESS (Step 1)  
**Date Started:** 2024

---

## Overview

Phase 4 transforms raw event streams from locked phases into aggregated signals for pattern detection, AI analysis, and support escalation. This phase is about **interpreting events**, not displaying them.

**This phase does NOT implement:**
- Reporting UI or dashboards
- Alert delivery (email, Slack, etc.)
- Notification systems
- AI model calls
- Billing metrics

---

## Step 1: Event Aggregation Foundations

### Purpose

Create data structures that aggregate events over time so later steps can:
- Detect abnormal patterns
- Generate alerts
- Feed AI agents
- Enable efficient querying without scanning raw event tables

### Aggregation Tables

#### `event_aggregates` (Time-Bucketed, Tenant-Level)

**Purpose:** Aggregate all activity events by tenant and event type over time buckets.

**Fields:**
- `id` - Primary key
- `tenant_id` - Foreign key to tenants
- `brand_id` - Optional brand scope (nullable)
- `event_type` - Event type from EventType enum (e.g., `'download.zip.requested'`)
- `bucket_start_at` - Start of time bucket (inclusive)
- `bucket_end_at` - End of time bucket (inclusive)
- `count` - Total event count in bucket
- `success_count` - Count of successful events (if applicable)
- `failure_count` - Count of failed events (if applicable)
- `metadata` - JSON field storing:
  - Error code counts: `{ "UPLOAD_AUTH_EXPIRED": 5, ... }`
  - File type counts: `{ "pdf": 10, "jpg": 5, ... }`
  - Context counts: `{ "zip": 3, "single": 7, ... }`
  - Download type counts: `{ "snapshot": 2, "living": 1, ... }`
  - Source counts: `{ "grid": 5, "drawer": 8, ... }`
- `created_at`, `updated_at` - Timestamps

**Unique Constraint:** `['tenant_id', 'event_type', 'bucket_start_at']`

**Use Cases:**
- "How many download ZIP requests per tenant in the last hour?"
- "What error codes appear most frequently for uploads?"
- "Which file types are downloaded most?"

#### `asset_event_aggregates` (Per-Asset Aggregation)

**Purpose:** Aggregate activity events per asset over time buckets.

**Fields:**
- `id` - Primary key
- `tenant_id` - Foreign key to tenants
- `asset_id` - UUID foreign key to assets
- `event_type` - Event type from EventType enum
- `bucket_start_at` - Start of time bucket
- `bucket_end_at` - End of time bucket (nullable, can be calculated)
- `count` - Event count in bucket
- `metadata` - JSON field storing:
  - File type (from asset or event metadata)
  - Error codes: `{ "UPLOAD_AUTH_EXPIRED": 2, ... }`
  - Contexts: `{ "zip": 1, "single": 3, ... }`
- `created_at`, `updated_at` - Timestamps

**Unique Constraint:** `['asset_id', 'event_type', 'bucket_start_at']`

**Use Cases:**
- "How many downloads did asset X have today?"
- "Which assets are experiencing the most failures?"
- "Per-asset download trends over time"

#### `download_event_aggregates` (Per-Download Aggregation)

**Purpose:** Aggregate download-related events per download group over time buckets.

**Fields:**
- `id` - Primary key
- `tenant_id` - Foreign key to tenants
- `download_id` - UUID foreign key to downloads
- `event_type` - Event type from EventType enum
- `bucket_start_at` - Start of time bucket
- `bucket_end_at` - End of time bucket (nullable, can be calculated)
- `count` - Event count in bucket
- `metadata` - JSON field storing:
  - Download type: `{ "snapshot": 1, "living": 0 }`
  - Source: `{ "grid": 2, "drawer": 1, ... }`
  - Access mode: `{ "public": 1, "team": 2, ... }`
  - Error codes: `{ "DOWNLOAD_ZIP_FAILED": 1, ... }`
  - ZIP size bytes (for ZIP-related events): sum or average
- `created_at`, `updated_at` - Timestamps

**Unique Constraint:** `['download_id', 'event_type', 'bucket_start_at']`

**Use Cases:**
- "How many times was download X accessed?"
- "Which download types are most popular?"
- "Download failure rates by source"

---

## Aggregation Strategy (Design Only)

### Time Bucket Configuration

**Bucket Sizes:**
- Default: 5 minutes (configurable)
- Alternative: 15 minutes (for lower-frequency events)
- Stored as constant or config value (e.g., `EVENT_AGGREGATION_BUCKET_MINUTES = 5`)

**Bucket Boundaries:**
- Aligned to clock time (e.g., 5-minute buckets: 00:00, 00:05, 00:10, ...)
- `bucket_start_at` is inclusive
- `bucket_end_at` is inclusive (calculated as `bucket_start_at + bucket_size - 1 second`)

### Event Roll-Up Logic (Future Implementation)

**How raw events roll up into buckets:**

1. **Group Events:**
   - By `tenant_id`
   - By `event_type`
   - By time bucket (rounded down to bucket boundary)

2. **Calculate Aggregates:**
   - `count` = total events in bucket
   - `success_count` = events with no error in metadata
   - `failure_count` = events with error codes in metadata
   - `metadata` = aggregated counts from event metadata:
     - Error codes: Count occurrences of each error_code
     - File types: Count occurrences of each file_type
     - Contexts: Count occurrences of each context
     - Download types: Count occurrences of each download_type
     - Sources: Count occurrences of each source

3. **Upsert Logic:**
   - Use unique constraint to prevent duplicates
   - If aggregate exists: increment counts and merge metadata
   - If aggregate doesn't exist: create new aggregate record

### Idempotency Strategy

**Requirements:**
- Aggregation jobs must be idempotent (safe to re-run)
- Same events must not be counted multiple times

**Design Approach:**
- Track last processed event ID or timestamp
- Only process events after last processed point
- Use database transactions for atomic upserts
- Unique constraints prevent duplicate aggregates

### Late-Arriving Events Handling

**Problem:** Events may arrive after their time bucket has been processed.

**Design Approach:**
- Process events in time order (by `created_at`)
- Allow backfilling of past buckets
- Maintain "last processed timestamp" per aggregation run
- Late events update existing aggregates (upsert)

**Future Enhancement:**
- Configurable lookback window (e.g., process events up to 1 hour late)
- Separate job for backfilling missed events

### Backfill Strategy (Future)

**Design Only — Not Implemented:**

1. **Manual Backfill:**
   - Command: `php artisan analytics:backfill --from=YYYY-MM-DD --to=YYYY-MM-DD`
   - Processes all events in date range
   - Creates aggregates for all buckets in range

2. **Automatic Backfill:**
   - Scheduled job runs periodically
   - Checks for gaps in aggregation
   - Fills missing buckets

3. **Validation:**
   - Verify aggregate counts match raw event counts
   - Detect and report discrepancies

---

## Event Compatibility

### Event Type References

- All `event_type` fields reference existing `EventType` enum
- No new event types added in Phase 4
- Aggregation layer is **READ-ONLY** with respect to events
- Events are consumed from `activity_events` table only

### Supported Event Types (Examples)

**Upload Events (Phase 2.5):**
- `asset.upload.finalized`
- `asset.thumbnail.failed`
- (Error codes from upload errors)

**Download Events (Phase 3.1):**
- `download_group.created`
- `download_group.ready`
- `download.zip.requested`
- `download.zip.completed`
- `download.zip.failed`
- `asset.download.created`

**System Events:**
- `system.error`
- `system.warning`

---

## AI-Ready Metadata Design

### Metadata Storage Strategy

**Principle:** Store raw counts and context, do NOT normalize or interpret yet.

**Metadata Structure (JSON):**

```json
{
  "error_codes": {
    "UPLOAD_AUTH_EXPIRED": 5,
    "UPLOAD_VALIDATION_FAILED": 2
  },
  "file_types": {
    "pdf": 10,
    "jpg": 5,
    "png": 3
  },
  "contexts": {
    "zip": 3,
    "single": 7
  },
  "download_types": {
    "snapshot": 2,
    "living": 1
  },
  "sources": {
    "grid": 5,
    "drawer": 8,
    "collection": 1
  },
  "access_modes": {
    "public": 1,
    "team": 2
  }
}
```

### Metadata Extraction (Future Implementation)

**From Event Metadata:**
- Error codes: `event.metadata.error_code`
- File types: `event.metadata.file_type` or from subject asset
- Context: `event.metadata.context`
- Download type: `event.metadata.download_type`
- Source: `event.metadata.source`
- Access mode: `event.metadata.access_mode`

**Aggregation Logic:**
- Count occurrences of each value
- Store as key-value pairs in metadata JSON
- Preserve all values (no filtering or normalization)

---

## Explicit Non-Goals

Phase 4 Step 1 explicitly does **NOT** include:

- ❌ Aggregation job implementation (Step 2)
- ❌ Pattern detection rules (Step 3)
- ❌ Alert candidate generation (Step 4)
- ❌ AI summary hooks (Step 5)
- ❌ Reporting UI or dashboards
- ❌ Alert delivery systems
- ❌ Notification systems
- ❌ Real-time aggregation
- ❌ Event emission modifications

These are reserved for future steps/phases.

---

## Future Step Integration

### Step 2: Aggregation Jobs

Will consume:
- `EventAggregate` model
- `AssetEventAggregate` model
- `DownloadEventAggregate` model
- Raw `activity_events` table

Will produce:
- Aggregated records in aggregation tables
- Updated metadata JSON

### Step 3: Pattern Detection Rules

Will consume:
- Aggregated records from Step 2
- Metadata JSON for pattern matching

Will produce:
- Pattern detection results (future schema)

### Step 4: Alert Candidate Generation

Will consume:
- Pattern detection results from Step 3
- Aggregated records

Will produce:
- Alert candidates (future schema)

### Step 5: AI Summary Hooks

Will consume:
- Alert candidates from Step 4
- Aggregated records and metadata

Will produce:
- AI-ready summaries (future schema)

---

## Models

### EventAggregate

**Location:** `app/Models/EventAggregate.php`

**Key Relationships:**
- `tenant()` - BelongsTo Tenant
- `brand()` - BelongsTo Brand (nullable)

**Key Scopes (Future):**
- `scopeForTenant($tenantId)` - Filter by tenant
- `scopeOfType($eventType)` - Filter by event type
- `scopeInBucket($startAt, $endAt)` - Filter by time bucket

### AssetEventAggregate

**Location:** `app/Models/AssetEventAggregate.php`

**Key Relationships:**
- `tenant()` - BelongsTo Tenant
- `asset()` - BelongsTo Asset

**Key Scopes (Future):**
- `scopeForAsset($assetId)` - Filter by asset
- `scopeOfType($eventType)` - Filter by event type
- `scopeInBucket($startAt, $endAt)` - Filter by time bucket

### DownloadEventAggregate

**Location:** `app/Models/DownloadEventAggregate.php`

**Key Relationships:**
- `tenant()` - BelongsTo Tenant
- `download()` - BelongsTo Download

**Key Scopes (Future):**
- `scopeForDownload($downloadId)` - Filter by download
- `scopeOfType($eventType)` - Filter by event type
- `scopeInBucket($startAt, $endAt)` - Filter by time bucket

---

## Migration Notes

### Migration Files

1. `2026_01_15_120000_create_event_aggregates_table.php`
   - Creates `event_aggregates` table
   - Includes indexes and unique constraints

2. `2026_01_15_120001_create_asset_event_aggregates_table.php`
   - Creates `asset_event_aggregates` table
   - Includes indexes and unique constraints

3. `2026_01_15_120002_create_download_event_aggregates_table.php`
   - Creates `download_event_aggregates` table
   - Includes indexes and unique constraints

### Running Migrations

```bash
php artisan migrate
```

---

## Related Documentation

- `docs/UPLOAD_AND_QUEUE.md` - Upload pipeline, observability, downloader (merged phase docs)
- `docs/ACTIVITY_LOGGING_IMPLEMENTATION.md` - Activity events infrastructure

---

---

## Step 2: Event Aggregation Jobs (Batch-Based)

### Aggregation Job

**Location:** `app/Jobs/AggregateEventsJob.php`

**Purpose:** Batch job that processes raw activity events and aggregates them into time-bucketed aggregates.

**Responsibilities:**
- Process `activity_events` in configurable time windows
- Determine `bucket_start_at` and `bucket_end_at` based on bucket size
- Group events by:
  - `tenant_id`
  - `event_type`
  - Time bucket (rounded to bucket boundaries)
- Calculate aggregates:
  - `count` - Total event count
  - `success_count` - Events without errors
  - `failure_count` - Events with error codes
- Populate metadata JSON with counts by:
  - Error codes
  - File types
  - Contexts (zip vs single)
  - Download types (snapshot vs living)
  - Sources (grid, drawer, collection, etc.)
  - Access modes

**Service:** `app/Services/EventAggregationService.php`

**Key Methods:**
- `aggregateTimeWindow(Carbon $startAt, Carbon $endAt)` - Main aggregation method
- `processEventsChunk()` - Process events in chunks
- `upsertTenantAggregate()` - Create/update tenant-level aggregates
- `upsertAssetAggregate()` - Create/update asset-level aggregates
- `upsertDownloadAggregate()` - Create/update download-level aggregates
- `extractMetadata()` - Extract and count metadata fields
- `mergeMetadata()` - Merge metadata from new events with existing aggregates

### Aggregation Flow

1. **Time Window Determination:**
   - Default: Process last 15 minutes of events
   - Can be explicitly specified via job constructor
   - Falls back to last processed timestamp if available

2. **Event Processing:**
   - Events queried in time order (`created_at`)
   - Processed in chunks (1000 events per chunk)
   - Grouped by aggregation keys (tenant + event_type + bucket, etc.)

3. **Bucket Calculation:**
   - Buckets aligned to clock time (e.g., 5-minute buckets: 00:00, 00:05, 00:10, ...)
   - `bucket_start_at` = rounded down to bucket boundary
   - `bucket_end_at` = bucket_start + bucket_size - 1 second

4. **Aggregate Creation:**
   - Use `updateOrCreate` with unique constraints
   - Increment counts if aggregate exists
   - Merge metadata (combine counts from both sources)

5. **Per-Asset & Per-Download Aggregation:**
   - Automatically detect if event subject is Asset or Download
   - Create separate aggregates for asset-level and download-level analysis
   - Null-safe: Events without asset_id or download_id are skipped for those aggregates

### Idempotency Strategy

**Last Processed Timestamp Tracking:**
- Stored in cache: `event_aggregation:last_processed_at`
- Cache TTL: 24 hours
- Format: ISO 8601 timestamp string

**Idempotency Guarantees:**
- Job tracks last processed timestamp
- Default window starts from last processed timestamp
- Prevents re-processing of already-aggregated events (when using default window)
- Safe to re-run on new events (after last processed timestamp)

**Duplicate Prevention:**
- Unique constraints prevent duplicate aggregates
- Upsert logic increments counts if aggregate exists
- Metadata merging combines counts correctly

**Limitations:**
- If job is manually re-run on an old time window, events may be double-counted
- This is acceptable for the design: job is intended to process events in time order
- Manual backfill operations should use dedicated backfill logic (future enhancement)

### Late-Arriving Events Handling

**Design:**
- Events processed in time order (by `created_at`)
- Late-arriving events update existing aggregates
- No special handling needed - aggregates update automatically
- Lookback window: Configurable (default: process up to 15 minutes ago)

**Future Enhancement:**
- Configurable lookback window for late events
- Separate job for backfilling missed events

### Performance Considerations

**Chunk Processing:**
- Events processed in chunks of 1000
- Reduces memory usage for large event volumes
- Each chunk processed in a transaction

**Database Queries:**
- Group events in memory (PHP) after fetching
- Use `updateOrCreate` for efficient upserts
- Indexes on aggregation tables support fast lookups

**Optimization Notes:**
- Consider DB-level aggregation for very high volumes (future)
- Current approach prioritizes correctness and metadata extraction
- Chunk size can be tuned via constant

### Failure Handling

**Job Failures:**
- Job retries up to 3 times with exponential backoff
- Individual chunk failures don't stop entire job
- Errors logged but don't block future runs

**Transaction Safety:**
- Each chunk processed in a database transaction
- Partial chunk failures roll back that chunk only
- Other chunks continue processing

**Logging:**
- Structured logging at each step
- Error context includes time window, chunk info
- Stats logged on completion

### Configuration

**Bucket Size:**
- Constant: `EventAggregationService::BUCKET_SIZE_MINUTES = 5`
- Can be changed to 15 minutes for lower-frequency events
- All buckets must use the same size (no mixed buckets)

**Default Time Window:**
- Constant: `AggregateEventsJob::DEFAULT_WINDOW_MINUTES = 15`
- Processes events from (now - 15 minutes) to now
- Override via job constructor parameters

**Chunk Size:**
- Constant: `EventAggregationService::CHUNK_SIZE = 1000`
- Tune based on event volume and memory constraints

### Explicit Non-Goals

Phase 4 Step 2 explicitly does **NOT** include:

- ❌ Pattern detection rules (Step 3)
- ❌ Alert candidate generation (Step 4)
- ❌ AI summary hooks (Step 5)
- ❌ Real-time event streaming
- ❌ Event deduplication (assumes events are unique)
- ❌ Backfill command implementation
- ❌ Event replay or reprocessing safeguards

These are reserved for future steps/phases.

---

## Step 3: Pattern Detection Rules (Declarative)

### Pattern Detection System

**Purpose:** Define reusable, declarative pattern rules that identify system health issues, tenant-specific failures, and cross-tenant anomalies.

**Key Principle:** This step identifies "something is wrong" — NOT what to do about it. No alerting, notifications, or actions are taken. Results are returned for consumption by future steps.

### Detection Rule Model

**Location:** `app/Models/DetectionRule.php`

**Migration:** `database/migrations/2026_01_15_130000_create_detection_rules_table.php`

**Fields:**
- `id` - Primary key
- `name` - Human-readable rule name
- `description` - Rule description and purpose
- `event_type` - Event type to evaluate (references EventType enum)
- `scope` - Detection scope: `global`, `tenant`, `asset`, or `download`
- `threshold_count` - Count threshold to trigger rule
- `threshold_window_minutes` - Time window in minutes to evaluate
- `comparison` - Comparison operator: `greater_than` or `greater_than_or_equal`
- `metadata_filters` - Optional JSON filters (e.g., error_code, file_type)
- `severity` - Severity level: `info`, `warning`, or `critical`
- `enabled` - Whether rule is active (boolean)
- `created_at`, `updated_at` - Timestamps

### Pattern Detection Service

**Location:** `app/Services/PatternDetectionService.php`

**Purpose:** Evaluates declarative detection rules against event aggregates.

**Key Methods:**
- `evaluateAllRules(?Carbon $asOfTime)` - Evaluate all enabled rules
- `evaluateRule(DetectionRule $rule, ?Carbon $asOfTime)` - Evaluate a specific rule

**Evaluation Logic:**
1. **Time Window Calculation:** Evaluate aggregates within `threshold_window_minutes` before `asOfTime`
2. **Scope-Based Queries:**
   - `global`: Aggregates across all tenants
   - `tenant`: Aggregates grouped by tenant
   - `asset`: Asset-level aggregates
   - `download`: Download-level aggregates
3. **Metadata Filtering:** Apply `metadata_filters` if present (e.g., filter by error_code)
4. **Threshold Comparison:** Check if observed count matches threshold using comparison operator
5. **Result Building:** Create match result with observed counts and metadata summary

**NO SIDE EFFECTS:** Service is read-only. No database writes, no alerts, no notifications.

### Result Structure

When a rule matches, the service returns an array with:

```php
[
    'rule_id' => int,
    'rule_name' => string,
    'scope' => string, // 'global', 'tenant', 'asset', 'download'
    'subject_id' => string|null, // tenant_id, asset_id, download_id, or null for global
    'severity' => string, // 'info', 'warning', 'critical'
    'observed_count' => int,
    'threshold_count' => int,
    'window_minutes' => int,
    'metadata_summary' => array // Aggregated metadata from matching aggregates
]
```

**Usage:** Results can be consumed by:
- Alert candidate generation (Step 4)
- AI analysis (Step 5)
- Dashboard display (future phase)
- Support ticket creation (future phase)

### Example Rules

**Seeder:** `database/seeders/DetectionRuleSeeder.php`

**Example Rules (all disabled by default):**

1. **High Download ZIP Failure Rate (Tenant)**
   - Event: `DOWNLOAD_ZIP_FAILED`
   - Scope: `tenant`
   - Threshold: 5 failures in 15 minutes
   - Severity: `warning`

2. **Global ZIP Generation Failure Rate**
   - Event: `DOWNLOAD_ZIP_FAILED`
   - Scope: `global`
   - Threshold: 20 failures in 60 minutes
   - Severity: `critical`

3. **Repeated Asset Download Failures**
   - Event: `ASSET_DOWNLOAD_FAILED`
   - Scope: `asset`
   - Threshold: 3 failures in 30 minutes
   - Severity: `warning`

4. **High Upload Validation Errors (Tenant)**
   - Event: `ASSET_UPLOAD_FINALIZED`
   - Scope: `tenant`
   - Threshold: 10 validation errors in 15 minutes
   - Metadata Filter: `error_code = UPLOAD_VALIDATION_FAILED`
   - Severity: `info`

5. **Thumbnail Generation Failures (Tenant)**
   - Event: `ASSET_THUMBNAIL_FAILED`
   - Scope: `tenant`
   - Threshold: 10 failures in 60 minutes
   - Severity: `warning`

6. **Download Group Creation Failures (Global)**
   - Event: `DOWNLOAD_GROUP_FAILED`
   - Scope: `global`
   - Threshold: 5 failures in 15 minutes
   - Severity: `critical`

### Rule Evaluation Flow

1. **Load Enabled Rules:** Query `detection_rules` where `enabled = true`
2. **For Each Rule:**
   - Calculate time window: `[asOfTime - threshold_window_minutes, asOfTime]`
   - Query appropriate aggregate table based on scope:
     - Global/Tenant: `event_aggregates`
     - Asset: `asset_event_aggregates`
     - Download: `download_event_aggregates`
   - Apply metadata filters if present
   - Sum counts across matching aggregates
   - Compare sum to threshold using comparison operator
   - If match: Build and return result
3. **Return All Matches:** Collection of match results

### Metadata Filters

**Purpose:** Narrow rule evaluation to specific conditions.

**Format:** JSON object with key-value pairs

**Examples:**
```json
{
  "error_code": "UPLOAD_VALIDATION_FAILED"
}
```

```json
{
  "file_type": "pdf"
}
```

**Filtering Logic:**
- Checks if aggregate metadata contains filter key
- For array values (e.g., `error_codes` counts), checks if filter value exists in array
- Filters out aggregates that don't match all filter criteria

### Explicit Non-Goals

Phase 4 Step 3 explicitly does **NOT** include:

- ❌ Alert generation or delivery (Step 4)
- ❌ AI calls or analysis (Step 5)
- ❌ Notification systems (email, Slack, etc.)
- ❌ Support ticket creation
- ❌ UI dashboards or rule management
- ❌ Real-time rule evaluation
- ❌ Automatic rule activation/deactivation
- ❌ Rule scheduling or cron jobs

These are reserved for future steps/phases.

---

## Step 4: Alert Candidate Generation (No Delivery)

### Alert Candidate System

**Purpose:** Persist alert candidates representing detected anomalous conditions without sending notifications or creating alerts.

**Key Principle:** This step stores "what was detected" — NOT "what to do about it". Alert candidates are persisted for:
- Manual review
- Suppression
- Escalation
- AI explanation (Step 5)

**NO NOTIFICATIONS** — No email, Slack, or other delivery mechanisms.
**NO ACTIONS** — Only record keeping.

### Alert Candidate Model

**Location:** `app/Models/AlertCandidate.php`

**Migration:** `database/migrations/2026_01_15_140000_create_alert_candidates_table.php`

**Fields:**
- `id` - Primary key
- `rule_id` - Foreign key to `detection_rules`
- `scope` - Alert scope: `global`, `tenant`, `asset`, or `download`
- `subject_id` - ID of the subject (tenant_id, asset_id, download_id, or null for global)
- `tenant_id` - Tenant ID if applicable (nullable for global scope)
- `severity` - Severity level: `info`, `warning`, or `critical`
- `observed_count` - Observed event count that triggered the alert
- `threshold_count` - Threshold count from the detection rule
- `window_minutes` - Time window in minutes from the detection rule
- `status` - Alert status: `open`, `acknowledged`, or `resolved`
- `first_detected_at` - When this alert was first detected
- `last_detected_at` - When this alert was last detected (updated on repeat detections)
- `detection_count` - Number of times this alert has been detected (incremented on repeat detections)
- `context` - JSON field with additional context (metadata_summary from pattern detection)
- `created_at`, `updated_at` - Timestamps

**Unique Constraint:**
- `['rule_id', 'scope', 'subject_id', 'status']` - One open alert per rule+scope+subject combination
- Allows multiple alerts if previous is acknowledged or resolved

### Alert Candidate Service

**Location:** `app/Services/AlertCandidateService.php`

**Purpose:** Manages creation and updates of alert candidates based on pattern detection results.

**Key Methods:**
- `processDetectionResults(Collection $results, ?Carbon $detectedAt)` - Process pattern detection results and create/update alerts
- `createOrUpdateAlert(array $result, Carbon $detectedAt)` - Create or update a single alert candidate
- `getOpenAlerts(array $filters)` - Get open alert candidates with optional filters
- `acknowledgeAlert(int $alertId)` - Transition alert status: open → acknowledged
- `resolveAlert(int $alertId)` - Transition alert status: open/acknowledged → resolved

### Deduplication Strategy

**Principle:** Same rule + scope + subject should not create duplicate open alerts.

**Logic:**
1. When processing a detection result, look for existing open alert with:
   - Same `rule_id`
   - Same `scope`
   - Same `subject_id`
   - `status = 'open'`

2. **If found (existing open alert):**
   - Update existing alert:
     - Increment `detection_count`
     - Update `last_detected_at` to current time
     - Update `observed_count` to latest value
     - Update `context` with latest metadata summary

3. **If not found (new alert):**
   - Create new alert candidate:
     - Set `first_detected_at` and `last_detected_at` to detection time
     - Set `detection_count` to 1
     - Set `status` to `'open'`

**Repeat Detection Handling:**
- Repeated detections of the same condition roll up into one alert
- `detection_count` tracks how many times the condition has been detected
- Allows tracking of recurring issues without creating multiple alerts

**Acknowledged/Resolved Alerts:**
- When an alert is acknowledged or resolved, a new alert can be created for the same rule+scope+subject
- This allows tracking if the condition recurs after being addressed
- Unique constraint only applies to `status = 'open'` alerts

### Alert Lifecycle

**Status Transitions:**

1. **open** (default)
   - Newly detected alert
   - Active condition requiring attention
   - Can be acknowledged or resolved

2. **acknowledged**
   - Alert has been seen/acknowledged
   - Condition may still be active
   - Can transition to resolved
   - Allows new alert to be created for same rule+scope+subject if condition persists

3. **resolved**
   - Alert condition has been resolved or closed
   - Final state
   - Allows new alert to be created for same rule+scope+subject if condition recurs

**Lifecycle Flow:**
```
Detection → open → acknowledged → resolved
              ↓         ↓
           resolved   resolved
```

**Status Management:**
- Status transitions are currently manual (via service methods)
- No automatic status transitions in this step
- No UI for status management (future phase)
- Status transitions can be automated in future phases (e.g., auto-resolve after condition clears)

### Context Storage

**Purpose:** Store additional context from pattern detection for later review or AI analysis.

**Content:**
- `metadata_summary` from pattern detection results
- May include:
  - Error code counts
  - File type breakdowns
  - Download type distributions
  - Source distributions
  - Other aggregated metadata

**Usage:**
- Manual review: Provides context for understanding the alert
- AI explanation: Can be consumed by AI agents to explain the alert (Step 5)
- Analytics: Can be analyzed to understand patterns

### Explicit Non-Goals

Phase 4 Step 4 explicitly does **NOT** include:

- ❌ Notification delivery (email, Slack, SMS, etc.)
- ❌ Alert delivery systems
- ❌ Support ticket creation
- ❌ UI dashboards or alert management interfaces
- ❌ Automatic status transitions
- ❌ Alert escalation workflows
- ❌ Alert suppression rules
- ❌ AI calls or analysis (Step 5)
- ❌ Real-time alert processing

These are reserved for future steps/phases.

---

## Step 5: AI Summaries for Alert Candidates (No Actions)

### AI Summary System

**Purpose:** Generate human-readable AI summaries for alert candidates that explain what is happening, who is affected, severity, and suggest next steps.

**Key Principle:** Summaries are for human consumption only — support agents, admin dashboards, and future automated ticket creation. NO actions are taken based on summaries.

**AI Call Behavior:**
- Uses existing `AIService` if available
- Falls back to stub summary if AI call fails
- Must not block core flows
- Retry-safe (can regenerate summaries)

### Alert Summary Model

**Location:** `app/Models/AlertSummary.php`

**Migration:** `database/migrations/2026_01_15_150000_create_alert_summaries_table.php`

**Fields:**
- `id` - Primary key
- `alert_candidate_id` - Foreign key to `alert_candidates` (1:1 relationship)
- `summary_text` - Human-readable summary explaining the alert
- `impact_summary` - Summary of who/what is affected
- `affected_scope` - Description of affected entities (e.g., "Tenant ABC", "Asset XYZ")
- `severity` - Severity level (copied from alert candidate)
- `suggested_actions` - JSON array of suggested next steps (non-binding recommendations)
- `confidence_score` - AI confidence score (0.00-1.00)
- `generated_at` - When the summary was generated
- `created_at`, `updated_at` - Timestamps

**Relationship:**
- `alertCandidate()` - BelongsTo AlertCandidate
- `AlertCandidate->summary()` - HasOne AlertSummary

### Alert Summary Service

**Location:** `app/Services/AlertSummaryService.php`

**Purpose:** Generates AI summaries for alert candidates.

**Key Methods:**
- `generateSummary(AlertCandidate $alertCandidate, bool $forceRegenerate)` - Generate or update summary
- `buildPrompt(AlertCandidate $alertCandidate)` - Build structured prompt for AI
- `parseAIResponse(string $aiResponse, AlertCandidate $alertCandidate)` - Parse AI response into structured summary
- `generateStubSummary(AlertCandidate $alertCandidate)` - Generate fallback stub summary

**AI Integration:**
- Uses `AIService::executeAgent()` with agent ID: `alert_summarizer`
- Task type: `AITaskType::ALERT_SUMMARY`
- Falls back to stub summary if AI call fails
- Does not block if AI service is unavailable

### Prompt Structure

**Purpose:** Documented prompt structure for generating alert summaries.

**Sections:**

1. **Alert Description**
   - Rule name and description
   - Event type
   - Scope

2. **Detection Pattern**
   - Observed count vs threshold
   - Time window
   - Severity

3. **Frequency**
   - Detection count (how many times detected)
   - First detected timestamp
   - Last detected timestamp

4. **Affected Entities**
   - Scope (global, tenant, asset, download)
   - Subject ID (if applicable)
   - Tenant ID (if applicable)

5. **Context Metadata**
   - Metadata summary from pattern detection
   - Error codes, file types, sources, etc.

6. **Historical Context** (optional)
   - Recent event aggregates
   - Trend information

**Output Format:**
- Structured JSON response with:
  - `summary_text` - Main explanation
  - `impact_summary` - Who/what is affected
  - `affected_scope` - Entity description
  - `suggested_actions` - Array of actionable steps

**Prompt Design Principles:**
- No vendor-specific logic hardcoded
- Structured, repeatable format
- Includes all relevant context
- Clear output format specification

### Summary Update Strategy

**One Summary Per Alert Candidate:**
- 1:1 relationship enforced by unique constraint
- One summary record per alert candidate

**Regeneration Triggers:**
- When `detection_count` increases significantly (threshold multiplier: 1.5x)
- When severity changes
- Manual regeneration via `forceRegenerate` parameter

**Regeneration Logic:**
- Compares current `detection_count` with previous state
- Checks if severity changed
- Considers time since last generation (regenerate if >24 hours and count >= 3)

**Update Behavior:**
- Updates existing summary record (does not create duplicates)
- Previous summaries are overwritten (no history kept in this step)
- Future enhancement: Keep summary history (optional)

### Stub Summary Fallback

**Purpose:** Provide basic summaries when AI calls fail or AI service is unavailable.

**Features:**
- Uses structured template based on alert candidate data
- Includes basic information: rule name, counts, thresholds, severity
- Lower confidence score (0.50) to indicate stub origin
- Ensures summaries are always available for review

**When Used:**
- AI service unavailable
- AI call fails or times out
- AI agent not configured
- Budget limits exceeded

### Data Inputs

**From AlertCandidate:**
- Rule definition (name, description, event_type)
- Detection metrics (observed_count, threshold_count, window_minutes)
- Frequency data (detection_count, first_detected_at, last_detected_at)
- Scope and subject information
- Context metadata (from pattern detection)

**From Aggregates (Optional):**
- Recent event aggregates for historical context
- Trend information from time-bucketed data
- Metadata breakdowns (error codes, file types, etc.)

### Output Structure

**Summary Fields:**
- `summary_text` - Main explanation (2-3 sentences)
- `impact_summary` - Who/what is affected (1-2 sentences)
- `affected_scope` - Specific entity description
- `severity` - Copied from alert candidate
- `suggested_actions` - Array of actionable steps (2-3 items)
- `confidence_score` - AI confidence (0.00-1.00)
  - AI-generated: 0.85
  - Parsed but unparsed format: 0.70
  - Stub fallback: 0.50

### Explicit Non-Goals

Phase 4 Step 5 explicitly does **NOT** include:

- ❌ Notification delivery based on summaries
- ❌ Automated ticket creation
- ❌ Automated actions based on summaries
- ❌ UI dashboards or summary display
- ❌ Summary history/versioning (future enhancement)
- ❌ Real-time summary generation
- ❌ Summary scheduling or cron jobs
- ❌ Alert candidate lifecycle modifications

These are reserved for future steps/phases.

---

**Last Updated:** 2024  
**Current Step:** Step 5 (AI Summaries)  
**Phase 4 Status:** COMPLETE


## Source: PHASE_5A_SUPPORT_TICKETS.md


**Status:** In Progress  
**Step:** Step 1 (Support Ticket Foundations)  
**Locked Dependencies:** Phase 4 (Analytics Aggregation & AI Support Alerts)

---

## Overview

Phase 5A creates a durable, auditable link between alert candidates and support tickets. This phase enables:

- Automatic ticket creation from alert candidates
- Manual ticket creation
- Ticket lifecycle management
- Future integration with external ticket systems (Zendesk, Linear, Jira, etc.)

**Key Principle:** Phase 4 is LOCKED. This phase **consumes** alerts only and does not modify alert candidate lifecycle, detection rules, or aggregation logic.

---

## Step 1: Support Ticket Foundations

### Support Ticket Model

**Location:** `app/Models/SupportTicket.php`

**Migration:** `database/migrations/2026_01_15_160000_create_support_tickets_table.php`

**Fields:**
- `id` - Primary key
- `alert_candidate_id` - Foreign key to `alert_candidates` (nullable)
- `summary` - Brief summary of the ticket
- `description` - Detailed description of the issue
- `severity` - Severity level: `info`, `warning`, or `critical` (copied from alert if present)
- `status` - Ticket status: `open`, `in_progress`, `resolved`, or `closed`
- `source` - How ticket was created: `system` (from alert) or `manual`
- `external_reference` - Reference to external ticket system (Zendesk, Linear, Jira, etc.)
- `created_at`, `updated_at` - Timestamps

### Relationships

**AlertCandidate → SupportTicket:**
- `AlertCandidate::supportTicket()` - HasOne relationship
- One alert candidate can have one support ticket (1:1)

**SupportTicket → AlertCandidate:**
- `SupportTicket::alertCandidate()` - BelongsTo relationship
- Ticket can be linked to an alert candidate (nullable)

### Ticket Lifecycle

**Status Transitions:**

1. **open** (default)
   - New ticket, not yet assigned or worked on
   - Initial state for all tickets

2. **in_progress**
   - Ticket is being actively worked on
   - Assigned to support agent or team

3. **resolved**
   - Issue has been resolved
   - May transition back to `in_progress` if issue recurs

4. **closed**
   - Ticket is closed (final state)
   - Issue resolved and ticket closed

**Lifecycle Flow:**
```
Creation → open → in_progress → resolved → closed
              ↓         ↓            ↓
           resolved   resolved    closed
```

**Status Management:**
- Status transitions are manual (via service methods)
- No automatic status transitions in Step 1
- Future phases may add automated transitions based on alert resolution

### Support Ticket Service

**Location:** `app/Services/SupportTicketService.php`

**Purpose:** Manages creation and management of support tickets.

**Key Methods:**
- `createTicketFromAlert(AlertCandidate $alertCandidate, bool $forceCreate)` - Create ticket from alert candidate
- `createManualTicket(string $summary, ?string $description, string $severity)` - Create manual ticket
- `updateStatus(SupportTicket $ticket, string $status)` - Update ticket status
- `setExternalReference(SupportTicket $ticket, string $externalReference)` - Link to external ticket system

**Idempotency:**
- `createTicketFromAlert()` ensures one ticket per alert by default
- Returns existing ticket if one already exists for the alert
- Use `forceCreate = true` to create multiple tickets for same alert (rare use case)

### Ticket Creation from Alerts

**Process:**
1. Check if ticket already exists for alert (idempotency check)
2. Load alert summary if available (for richer ticket content)
3. Build ticket data from:
   - Alert candidate information
   - AI-generated summary (if available)
   - Alert context metadata
   - Suggested actions from summary
4. Create ticket with `source = 'system'`
5. Link ticket to alert candidate via `alert_candidate_id`

**Ticket Content:**
- **Summary:** AI-generated summary text, or fallback to alert rule name + counts
- **Description:** Includes:
  - Impact summary (from AI summary if available)
  - Alert details (rule, event type, scope, counts, timestamps)
  - Context metadata (error codes, file types, etc.)
  - Suggested actions (from AI summary if available)

### Manual Ticket Creation

**Purpose:** Allow support staff to create tickets manually (not linked to alerts).

**Use Cases:**
- Customer-reported issues not yet captured as alerts
- Proactive support actions
- Escalations from other sources

**Process:**
- Call `createManualTicket()` with summary, description, and severity
- Ticket created with `source = 'manual'`
- No alert candidate linked (`alert_candidate_id = null`)

### Relationship to Alerts

**One Ticket Per Alert (Default):**
- Each alert candidate can have one support ticket
- Enforced by idempotency check in `createTicketFromAlert()`
- Prevents duplicate tickets for the same alert

**Alert → Ticket Flow:**
```
AlertCandidate (detected) 
  → AlertSummary (AI-generated) 
    → SupportTicket (created) 
      → External System (future)
```

**Alert Independence:**
- Alert candidates remain independent of tickets
- Alert lifecycle is NOT affected by ticket creation
- Tickets can be created, updated, or closed without modifying alerts
- This ensures Phase 4 remains locked

### External Reference Support

**Purpose:** Link tickets to external ticket systems for future integrations.

**Fields:**
- `external_reference` - Stores external ticket ID/key (e.g., "ZENDESK-12345", "JIRA-PROJ-456")

**Future Integration:**
- Step 2+ will add integration with external systems
- This field enables bidirectional syncing
- Can store multiple formats (system-specific keys)

### Explicit Non-Goals

Phase 5A Step 1 explicitly does **NOT** include:

- ❌ UI for ticket management
- ❌ Ticket assignment or routing
- ❌ SLA enforcement
- ❌ Escalation rules
- ❌ Automated status transitions based on alert resolution
- ❌ Integration with external ticket systems (Zendesk, Linear, Jira)
- ❌ Email notifications for ticket creation
- ❌ Customer-facing ticket portal
- ❌ Ticket comments or messages
- ❌ Ticket attachments
- ❌ Modification of Phase 4 alert lifecycle

These are reserved for future steps/phases.

---

## Step 2: Automatic Ticket Creation Rules

### Automatic Ticket Creation System

**Purpose:** Define when alert candidates should automatically generate support tickets based on configurable rules.

**Key Principle:** Tickets are created automatically when alert candidates match enabled ticket creation rules. This ensures critical issues are escalated to support without manual intervention.

### Ticket Creation Rule Model

**Location:** `app/Models/TicketCreationRule.php`

**Migration:** `database/migrations/2026_01_15_170000_create_ticket_creation_rules_table.php`

**Fields:**
- `id` - Primary key
- `rule_id` - Foreign key to `detection_rules` (unique - one ticket rule per detection rule)
- `min_severity` - Minimum severity level: `warning` or `critical`
- `required_detection_count` - Minimum detection_count required before creating ticket
- `auto_create` - Whether to automatically create tickets when rule matches
- `enabled` - Whether this rule is active
- `created_at`, `updated_at` - Timestamps

**Relationship:**
- `TicketCreationRule::rule()` - BelongsTo DetectionRule

### Auto Ticket Creation Service

**Location:** `app/Services/AutoTicketCreationService.php`

**Purpose:** Evaluates alert candidates against ticket creation rules and automatically creates support tickets.

**Key Methods:**
- `evaluateAndCreateTickets(?Collection $alertCandidates)` - Evaluate alerts and create tickets for those matching rules
- `evaluateAlert(AlertCandidate $alertCandidate)` - Evaluate a single alert and create ticket if rule matches
- `shouldCreateTicket(AlertCandidate $alertCandidate)` - Check if an alert should have a ticket created

**Evaluation Logic:**
1. Get all enabled ticket creation rules
2. For each alert candidate:
   - Find matching ticket creation rule (by `rule_id`)
   - Check if alert meets requirements:
     - Severity meets minimum (`min_severity`)
     - Detection count meets minimum (`required_detection_count`)
     - `auto_create` is true
   - Check if ticket already exists (idempotency)
   - Create ticket via `SupportTicketService::createTicketFromAlert()`

**Idempotency:**
- Checks if ticket already exists for alert before creating
- Prevents duplicate tickets for the same alert
- Uses `AlertCandidate::supportTicket` relationship to check

### Default Rules (Seed Data)

**Seeder:** `database/seeders/TicketCreationRuleSeeder.php`

**Default Rules (all disabled by default):**

1. **Critical Alerts Auto-Create Tickets Immediately**
   - `min_severity`: `critical`
   - `required_detection_count`: 1
   - `auto_create`: true
   - `enabled`: false (disabled by default)

2. **Warning Alerts Auto-Create After N Detections**
   - `min_severity`: `warning`
   - `required_detection_count`: 3
   - `auto_create`: true
   - `enabled`: false (disabled by default)

**Rule Application:**
- Default rules are created for ALL detection rules
- One ticket creation rule per detection rule (enforced by unique constraint)
- Rules can be enabled/disabled individually per detection rule
- Custom `required_detection_count` can be set per rule

**Special Cases:**
- Global-scope critical alerts: Handled by critical severity rule with `detection_count = 1`
- Multiple severity rules: Only enabled rules are evaluated

### Ticket Creation Logic Examples

**Example 1: Critical Alert (Immediate Ticket)**
- Detection rule triggers alert with severity `critical`
- Ticket creation rule: `min_severity = critical`, `required_detection_count = 1`, `enabled = true`
- Result: Ticket created immediately (on first detection)

**Example 2: Warning Alert (After Multiple Detections)**
- Detection rule triggers alert with severity `warning`
- Alert detection_count increments: 1, 2, 3...
- Ticket creation rule: `min_severity = warning`, `required_detection_count = 3`, `enabled = true`
- Result: Ticket created when detection_count reaches 3

**Example 3: Alert with Existing Ticket**
- Alert already has a support ticket
- Ticket creation rule matches
- Result: No duplicate ticket created (idempotency check)

**Example 4: Alert Below Threshold**
- Alert severity: `info`
- Ticket creation rule: `min_severity = warning`
- Result: No ticket created (severity too low)

### Explicit Non-Goals

Phase 5A Step 2 explicitly does **NOT** include:

- ❌ UI for managing ticket creation rules
- ❌ Email notifications for ticket creation
- ❌ External ticket system integration (Zendesk, Linear, Jira)
- ❌ Automatic ticket assignment or routing
- ❌ SLA enforcement based on ticket creation
- ❌ Ticket priority assignment
- ❌ Modification of alert or ticket schemas

These are reserved for future steps/phases.

---

## Step 3: External Ticket System Adapter (Stub)

### Adapter Pattern Overview

**Purpose:** Create a pluggable adapter layer for external ticket systems without requiring them to be configured.

**Key Principle:** External systems are optional. The system works with internal tickets only, and can optionally sync with external systems when configured.

### Adapter Interface

**Location:** `app/Services/Tickets/Contracts/ExternalTicketAdapter.php`

**Interface:** `ExternalTicketAdapter`

**Methods:**
- `createTicket(SupportTicket): ExternalTicketResult` - Create ticket in external system
- `updateTicketStatus(SupportTicket): void` - Update ticket status in external system
- `addComment(SupportTicket, string): void` - Add comment to ticket in external system
- `getAdapterName(): string` - Get adapter identifier

**Purpose:**
- Vendor independence: Switch external systems without code changes
- Consistent interface: All adapters implement the same methods
- Easy testing: Mock adapters for unit tests
- Future extensibility: Add new systems by implementing this interface

### Null Adapter (Default)

**Location:** `app/Services/Tickets/Adapters/NullTicketAdapter.php`

**Behavior:**
- Logs intent to create/update tickets in external system
- Returns fake external_reference for testing/development (format: `NULL-{SOURCE}-{ID}`)
- Does NOT make actual API calls
- Used when `tickets.driver = null` or not configured

**Purpose:**
- Allows system to work without external ticket system configured
- Enables testing without external dependencies
- Provides reference implementation for adapter pattern

### External Ticket Service

**Location:** `app/Services/ExternalTicketService.php`

**Purpose:** Resolves and manages external ticket system adapters.

**Configuration:**
- Reads `tickets.driver` from config
- Defaults to `'null'` if not configured
- Maps driver names to adapter classes

**Driver Options:**
- `'null'`: Null adapter (stub, no external API calls) - **DEFAULT**
- `'zendesk'`: Zendesk adapter (future implementation)
- `'jira'`: Jira adapter (future implementation)
- `'linear'`: Linear adapter (future implementation)

**Adapter Resolution:**
1. Reads `tickets.driver` from config
2. Maps driver to adapter class
3. Returns adapter instance
4. Falls back to `NullTicketAdapter` if driver not found/configured

### Configuration

**Location:** `config/tickets.php`

**Environment Variable:** `TICKETS_DRIVER`

**Example:**
```env
TICKETS_DRIVER=null  # Use null adapter (default)
# TICKETS_DRIVER=zendesk  # Future: Use Zendesk adapter
# TICKETS_DRIVER=jira     # Future: Use Jira adapter
# TICKETS_DRIVER=linear   # Future: Use Linear adapter
```

**Driver-Specific Config:**
- Placeholder configuration sections for future adapters (Zendesk, Jira, Linear)
- Environment variables prepared but not used in Step 3

### Integration Point

**Location:** `app/Services/SupportTicketService.php`

**When SupportTicket is Created:**
1. Internal ticket is always created first
2. If `tickets.driver !== 'null'`:
   - Call `ExternalTicketService::createTicket()`
   - Store `external_reference` from adapter result
   - Log success or failure (failures do not block ticket creation)

**When SupportTicket Status is Updated:**
1. Internal ticket status is updated first
2. If `tickets.driver !== 'null'` and `external_reference` exists:
   - Call `ExternalTicketService::updateTicketStatus()`
   - Log success or failure (failures are logged but don't block update)

**Error Handling:**
- External adapter failures are logged but do not block internal ticket operations
- System continues to work with internal tickets even if external system is unavailable
- Failed external operations can be retried manually or via future webhook/job systems

### External Ticket Result

**Location:** `app/Services/Tickets/Contracts/ExternalTicketResult.php`

**Purpose:** Value object representing the result of creating a ticket in an external system.

**Properties:**
- `externalReference` (string) - External ticket ID/key
- `metadata` (array) - Optional adapter-specific metadata

**Usage:**
- Returned by adapter `createTicket()` methods
- `external_reference` is stored in `SupportTicket` model
- Metadata can be used for future sync operations

### Why Stub Exists

**Benefits:**
1. **Zero Dependencies:** System works without external ticket systems
2. **Development/Testing:** No need to configure external APIs during development
3. **Progressive Enhancement:** Can enable external integrations when ready
4. **Pattern Established:** Future adapters follow the same interface pattern
5. **Logging:** Intent is logged even without external system, enabling audit trail

**Future Steps:**
- Real adapter implementations will make actual API calls
- Null adapter remains as fallback for development/testing
- Bidirectional sync will be added in future phases

### Supported Drivers (Future)

**Zendesk:**
- Configuration: `TICKETS_DRIVER=zendesk`
- Requires: `ZENDESK_SUBDOMAIN`, `ZENDESK_API_TOKEN`, `ZENDESK_USER_EMAIL`
- Status: Not implemented in Step 3

**Jira:**
- Configuration: `TICKETS_DRIVER=jira`
- Requires: `JIRA_URL`, `JIRA_USERNAME`, `JIRA_API_TOKEN`
- Status: Not implemented in Step 3

**Linear:**
- Configuration: `TICKETS_DRIVER=linear`
- Requires: `LINEAR_API_KEY`, `LINEAR_TEAM_ID`
- Status: Not implemented in Step 3

### Explicit Non-Goals

Phase 5A Step 3 explicitly does **NOT** include:

- ❌ Actual API calls to external systems (stub only)
- ❌ Bidirectional ticket syncing
- ❌ Webhook handlers for external systems
- ❌ Status sync from external → internal
- ❌ Comment sync from external → internal
- ❌ UI for configuring external systems
- ❌ Removal of internal tickets (internal tickets always exist)

These are reserved for future steps/phases.

---

## Future Steps

**Step 4 (Planned):** Ticket Lifecycle Syncing
- Sync ticket status from external systems
- Update alerts based on ticket resolution (optional)
- Webhook handlers
- Bidirectional sync

---

**Last Updated:** 2024  
**Current Step:** Step 3 (External Ticket System Adapter - Stub)


## Source: PHASE_5B_ADMIN_UI.md


**Status:** In Progress  
**Step:** Step 1 (Admin Alerts Tab - Read-Only)  
**Locked Dependencies:** Phase 4 (Analytics & AI), Phase 5A (Support Tickets)

---

## Overview

Phase 5B provides internal admin visibility into alert candidates, AI summaries, and support tickets through a read-only observability interface.

**Key Principle:** This phase is PRESENTATION ONLY. It exposes existing data without modifying alert detection logic, ticket creation logic, or any locked Phase 4/5A components.

---

## Step 1: Admin Alerts Tab (Read-Only)

### Decision: AI Dashboard Integration

**Location:** `/app/admin/ai`  
**Tab Name:** "Alerts"  
**Integration:** New tab in existing AI Dashboard

**Rationale:**
- AI Dashboard already provides system-level observability
- Reuses existing permissions (`ai.dashboard.view`)
- Consistent admin navigation and layout
- AI summaries are generated via AI system (Phase 4 Step 5)
- Centralizes all admin observability tools

### Implementation

**Controller:** `app/Http/Controllers/Admin/AIDashboardController.php`

**Component:** `resources/js/Components/AI/TabContent.jsx` → `AlertsTabContent`

**Page:** `resources/js/Pages/Admin/AI/Index.jsx`

### Alert List View

**Default Behavior:**
- Shows **open alerts only** (by default)
- Sorted by severity (critical > warning > info) then `last_detected_at` (descending)
- Paginated (50 per page)

**Table Columns:**
1. Expand/Collapse (chevron icon)
2. **Severity** - Badge (critical/warning/info)
3. **Rule Name** - Detection rule name + detection counts
4. **Scope** - global/tenant/asset/download + tenant name + subject ID
5. **Detections** - Detection count
6. **Alert Status** - Badge (open/acknowledged/resolved)
7. **Ticket Status** - Ticket status if exists, or "No ticket"
8. **First Detected** - Timestamp
9. **Last Detected** - Timestamp

### Expandable Detail View

**Trigger:** Click chevron icon to expand/collapse alert row

**Detail Sections (when expanded):**

1. **AI Summary** (if available)
   - Summary text (full)
   - Impact summary
   - Affected scope
   - Suggested actions (bulleted list)
   - Confidence score (percentage)

2. **Support Ticket Details** (if linked)
   - Ticket ID
   - Ticket status
   - Source (system/manual)
   - Ticket summary

3. **Detection Metadata** (collapsed by default)
   - Expandable `<details>` section
   - Raw JSON context metadata
   - Includes error codes, file types, sources, etc.

### Filtering

**Available Filters:**
- **Severity** - info, warning, critical
- **Alert Status** - open, acknowledged, resolved
- **Scope** - global, tenant, asset, download
- **Detection Rule** - All detection rules (dropdown)
- **Tenant** - All tenants with alerts (dropdown)
- **Ticket Status** - open, in_progress, resolved, closed, or "No Ticket"
- **Has Summary** - yes/no

**Filter Behavior:**
- Filters persist in URL query parameters
- Clear Filters button appears when filters are active
- Default view: open alerts only (unless status filter is explicitly set)

### Access Rules

**Permissions:**
- Requires `ai.dashboard.view` permission (same as other AI Dashboard tabs)
- No new permissions needed
- Internal-only (admin access)

**Read-Only:**
- No acknowledge/resolve buttons
- No ticket status changes
- No alert modifications
- No mutations or API calls
- Display only

### Explicit Non-Goals

Phase 5B Step 1 explicitly does **NOT** include:

- ❌ Alert acknowledgment or resolution actions
- ❌ Ticket status updates
- ❌ Ticket creation or deletion
- ❌ Alert suppression or deletion
- ❌ Customer-facing UI
- ❌ Notifications or alerts
- ❌ Real-time updates or polling
- ❌ WebSocket subscriptions
- ❌ Modification of Phase 4 or Phase 5A logic
- ❌ Export functionality
- ❌ Bulk actions
- ❌ Alert detail pages (expandable view is sufficient)

These are reserved for future steps/phases.

---

## Step 2: Admin Alert Actions (Minimal & Safe)

### Alert Lifecycle Management

**Purpose:** Allow internal admins to manage alert lifecycle through acknowledgment and resolution actions.

**Key Principle:** Minimal, safe mutations only. No auto-resolving, no ticket sync, no bulk actions.

### Backend Endpoints

**Controller:** `app/Http/Controllers/Admin/AdminAlertController.php`

**Routes:**
- `POST /app/admin/alerts/{alert}/acknowledge` - Acknowledge alert
- `POST /app/admin/alerts/{alert}/resolve` - Resolve alert

**Authorization:**
- Requires `ai.dashboard.view` permission (same as viewing alerts)
- Middleware enforces permission check

**Status Transitions:**
- **Acknowledge:** `open` → `acknowledged`
- **Resolve:** `open` / `acknowledged` → `resolved`

**Validation:**
- Only open alerts can be acknowledged
- Only open or acknowledged alerts can be resolved
- Returns error message if invalid transition attempted

**Implementation:**
- Uses `AlertCandidateService::acknowledgeAlert()` and `resolveAlert()` methods
- Updates alert status via service layer
- Logs all transitions for audit trail

### Frontend Actions

**Location:** `resources/js/Components/AI/TabContent.jsx` → `AlertsTabContent`

**Actions Column:**
- Appears only for users with `ai.dashboard.view` permission
- Shows action buttons based on alert status:
  - **Open alerts:** "Acknowledge" and "Resolve" buttons
  - **Acknowledged alerts:** "Resolve" button only
  - **Resolved/Closed alerts:** No actions (display "—")

**Button Styling:**
- Acknowledge: Yellow button (matches acknowledged status badge)
- Resolve: Green button (matches resolved status badge)
- Small, compact buttons (text-xs)

**Confirmation Dialog:**
- Required for both acknowledge and resolve actions
- Modal dialog with:
  - Alert name/rule name displayed
  - Clear action confirmation message
  - Color-coded confirm button (yellow for acknowledge, green for resolve)
  - Cancel button
- Prevents accidental status changes

**Optimistic Updates:**
- Uses Inertia.js `preserveState` and `preserveScroll` for smooth UX
- Page updates without full reload after action completion
- Only refreshes `tabContent` data

### Ticket Sync Behavior

**One-Way Sync Rule:**
- When alert is resolved, linked support tickets are **NOT** automatically modified
- Ticket status remains independent
- Ticket lifecycle is NOT affected by alert resolution
- Future phase may add bidirectional sync if needed

**Rationale:**
- Tickets may require separate resolution process
- Support team may need to close tickets independently
- Maintains separation of concerns (Phase 5A remains locked)

### Explicit Non-Goals

Phase 5B Step 2 explicitly does **NOT** include:

- ❌ Auto-resolution of alerts
- ❌ Automatic ticket status changes
- ❌ Bulk acknowledge/resolve actions
- ❌ Alert suppression or deletion
- ❌ Ticket creation from alerts (already handled by Phase 5A Step 2)
- ❌ Modification of detection logic
- ❌ Modification of ticket creation rules
- ❌ Customer-facing UI
- ❌ Notifications on status change

These are reserved for future steps/phases.

---

## Future Steps

**Step 3 (Planned):** Enhanced Views
- Alert detail pages
- Timeline views
- Trend analysis

---

**Last Updated:** 2024  
**Current Step:** Step 2 (Admin Alert Actions - Minimal & Safe)

