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
- `docs/PHASE_4_ANALYTICS_FOUNDATIONS.md`
- `docs/PHASE_5A_SUPPORT_TICKETS.md`
- `docs/PHASE_5B_ADMIN_UI.md`
- `docs/DEV_TOOLING.md`
