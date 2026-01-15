# Phase 5A — Support Ticket Integration

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
