# Phase C — Metadata Governance & Visibility

Phase C introduces administrative and tenant-level governance
for metadata fields without modifying the underlying metadata engine.

---

## Scope Overview

This phase provides:
- Observability into system-provided metadata fields
- Controlled visibility and applicability management
- Clear separation between system fields and tenant fields
- Preparation for enterprise governance and monetization

This phase does NOT modify:
- MetadataSchemaResolver
- Field keys, types, or options
- Candidate resolution logic
- Approval workflows

---

## Phase Breakdown

### C1 — System Metadata Governance UI (Admin Only)
Status: ⏳ Pending

Goals:
- View all system metadata fields
- Inspect field definitions (read-only)
- Identify AI vs system vs manual fields
- Configure visibility (upload/edit/filter)
- Configure category suppression
- Observe usage & override statistics

No schema mutation allowed.

---

### C2 — System Visibility Enforcement
Status: ⏳ Pending

Goals:
- Enforce category exclusions in:
  - Upload UI
  - Edit UI
  - Filters
- Ensure consistent behavior across all contexts
- No ownership or duplication of fields

---

### C3 — Tenant Custom Metadata Fields
Status: ⏳ Pending

Goals:
- Allow tenants to define custom fields
- Enforce namespacing (e.g. custom__*)
- Enforce plan-based limits
- Prevent deletion once in use
- Visibility configurable per tenant

System fields remain immutable.

---

### C4 — Tenant Metadata Visibility UI
Status: ⏳ Pending

Goals:
- Tenant UI to manage visibility of:
  - System fields (visibility only)
  - Tenant-created fields
- Configure show/hide per context
- Configure category suppression (visibility only)

---

## Locked Principles

- Categories do not own metadata schemas
- Fields are global, visibility is contextual
- No versioning of metadata fields
- Overrides and candidates replace versioning
- All changes are additive and auditable

---

## Exit Criteria

Phase C is complete when:
- Admins can fully observe system metadata
- Tenants can safely extend metadata
- No schema drift occurs
- Governance rules are explicit and enforceable
