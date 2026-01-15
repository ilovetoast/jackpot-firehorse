# Phase 4 — Analytics Aggregation Foundations

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

- `docs/PHASE_2_5_OBSERVABILITY_LOCK.md` - Upload observability (locked)
- `docs/PHASE_3_1_DOWNLOADER_LOCK.md` - Downloader system (locked)
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
