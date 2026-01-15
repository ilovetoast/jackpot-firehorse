# Dev Tooling

Development-only commands and utilities for testing and data generation.

---

## Commands

### `php artisan dev:generate-alert`

**Purpose:** Generate realistic alert pipeline data end-to-end for testing.

**Environment:** Only runs in `local` or `testing` environments. Aborts in production.

**Pipeline Flow:**
1. Generates fake `activity_events` matching a `DetectionRule`
2. Runs `AggregateEventsJob` to aggregate events into time buckets
3. Runs `PatternDetectionService` to evaluate detection rules
4. Creates `AlertCandidate` via `AlertCandidateService`
5. Creates `SupportTicket` via `AutoTicketCreationService` (if rules allow)
6. Generates `AlertSummary` via `AlertSummaryService` (AI or stub)

**Usage:**

```bash
# Basic usage (uses first enabled rule or first rule found)
php artisan dev:generate-alert --tenant=1

# Specify rule
php artisan dev:generate-alert --tenant=1 --rule=5

# Custom event count and window
php artisan dev:generate-alert --tenant=1 --count=10 --window=30

# Specify severity
php artisan dev:generate-alert --tenant=1 --severity=warning
```

**Options:**

| Option | Description | Default | Required |
|--------|-------------|---------|----------|
| `--tenant` | Tenant ID | - | Yes |
| `--rule` | Detection Rule ID | First enabled/available rule | No |
| `--severity` | Severity level (`critical\|warning`) | `critical` | No |
| `--count` | Number of events to generate | `5` | No |
| `--window` | Time window in minutes | `15` | No |

**Output:**

The command provides console output showing:
- Configuration summary (tenant, rule, settings)
- Progress through each pipeline step
- Final summary with created IDs and statuses
- Link to view alert in admin UI

**Example Output:**

```
🚀 Generating alert pipeline data...

📋 Configuration:
+------------------+-------------------------------------------+
| Setting          | Value                                     |
+------------------+-------------------------------------------+
| Tenant           | Acme Corp (ID: 1)                        |
| Detection Rule   | High Failure Rate (ID: 5)                |
| Event Type       | DOWNLOAD_ZIP_FAILED                       |
| Scope            | tenant                                    |
| Threshold        | 5 in 15min                                |
| Severity         | critical                                  |
| Events to Generate | 5                                        |
| Time Window      | 15 minutes                                |
+------------------+-------------------------------------------+

📝 Step 1: Generating activity events...
   ✓ Created 5 events
📊 Step 2: Running event aggregation...
   ✓ Aggregation complete
🔍 Step 3: Running pattern detection...
   ✓ Pattern detection complete
⚠️  Step 4: Creating alert candidates...
   ✓ Created AlertCandidate #42
🎫 Step 5: Auto-creating support tickets...
   ✓ Created SupportTicket #23
🤖 Step 6: Generating AI summaries...
   ✓ Generated AlertSummary (AI: Stub)

✅ Pipeline Complete!

+------------------+--------+----------------------------------+
| Item             | Status | Details                          |
+------------------+--------+----------------------------------+
| Events Generated | ✓      | 5 events                         |
| Alert Candidate  | ✓      | ID: 42, Status: open             |
| Support Ticket   | ✓      | ID: 23, Status: open             |
| Alert Summary    | ✓      | Stub generated                   |
+------------------+--------+----------------------------------+

🔗 View alert in admin UI:
   /app/admin/ai?tab=alerts
```

**Data Generation Details:**

- **Activity Events:** Events are generated with:
  - Matching `event_type` from detection rule
  - Appropriate `subject_type` and `subject_id` based on rule scope
  - Metadata matching rule's `metadata_filters` (if any)
  - Timestamps distributed across the specified time window
  - Tagged with `_dev_generated: true` in metadata

- **Event Distribution:** Events are evenly distributed across the time window to simulate realistic timing.

- **Subject Resolution:** 
  - `global` scope: Uses tenant as subject
  - `tenant` scope: Uses tenant as subject
  - `asset` scope: Uses fake asset ID (format: `dev-test-asset-{tenant_id}`)
  - `download` scope: Uses fake download ID (format: `dev-test-download-{tenant_id}`)

**Safety Features:**

- **Environment Check:** Command aborts immediately in production
- **Data Tagging:** All generated events include `_dev_generated: true` in metadata for identification
- **Validation:** Command validates tenant and rule exist before proceeding
- **Error Handling:** Failures are logged and reported with clear error messages
- **Non-Destructive:** Only creates new data, never deletes existing data

**Use Cases:**

1. **Testing Alert Detection:** Generate events to verify detection rules work correctly
2. **Testing Ticket Creation:** Verify auto-ticket creation rules trigger appropriately
3. **Testing AI Summaries:** Generate alerts to test AI summary generation (or stub fallback)
4. **UI Testing:** Create test data for viewing alerts in the admin UI
5. **End-to-End Testing:** Verify the entire alert pipeline works correctly

**Prerequisites:**

- At least one `DetectionRule` must exist (command will use first available)
- Specified `tenant` must exist
- Detection rule must have appropriate `event_type` and `scope` configured
- For ticket creation: `TicketCreationRule` must be enabled for the detection rule

**Troubleshooting:**

- **"No DetectionRule found":** Create at least one detection rule using the seeder or manually
- **"Tenant not found":** Verify the tenant ID exists in the database
- **"No alert candidate created":** Generated event count may be below the rule's threshold. Try increasing `--count`
- **"No ticket created":** Check that `TicketCreationRule` exists and is enabled for the detection rule
- **"Summary generation failed":** Check logs for AI service errors. Stub summary will be generated if AI fails

**Future Enhancements:**

- Support for generating events for multiple tenants simultaneously
- Support for generating events across multiple time windows
- Option to generate events with specific error codes or metadata
- Option to clean up generated test data
- Support for generating events for asset or download scopes with actual asset/download IDs

---

**Last Updated:** 2024  
**Status:** Active
