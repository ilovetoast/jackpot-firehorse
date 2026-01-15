# Phase 5B — Admin Observability UI

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
