# Phase 2.5 — Upload Observability & Diagnostics (LOCKED)

**Status:** ✅ COMPLETE & LOCKED  
**Date Locked:** 2024  
**Next Phase:** Downloader System (not yet implemented)  
**Phase 3:** EXPLICITLY SKIPPED

---

## Lock Declaration

**Phase 2.5 is COMPLETE and IMMUTABLE.**

This phase implements upload error observability and diagnostics infrastructure. All code, contracts, and behavior in this phase are **LOCKED** and must not be refactored, renamed, or modified.

Future phases may only:
- **CONSUME** error signals emitted by Phase 2.5
- **READ** normalized error data
- **DISPLAY** error information

Future phases must **NOT**:
- Modify error normalization logic
- Change error shape contracts
- Refactor observability utilities
- Remove or weaken environment gating
- Modify diagnostics panel behavior

---

## Scope & Deliverables

Phase 2.5 implemented:

1. **Frontend Error Normalization** (Step 1)
   - Centralized error normalization utility
   - Consistent error shapes across upload flow
   - AI-ready error signals

2. **Backend Error Response Consistency** (Step 2)
   - Centralized error response helper
   - Normalized error payloads from backend
   - Consistent HTTP status code mapping

3. **Dev-Only Diagnostics Panel** (Step 3)
   - Read-only developer diagnostics UI
   - Environment-gated visibility
   - Normalized error inspection

4. **Environment Awareness** (Step 4)
   - Centralized environment detection
   - Consistent logging behavior
   - Dev-only gating utilities

5. **Retry-State Clarity** (Step 5)
   - Visual retryability indicators
   - Clear user-facing error messages
   - UI-only improvements

---

## Canonical Upload Error Contract

### Frontend Error Shape (Normalized)

```typescript
{
  category: "AUTH" | "CORS" | "NETWORK" | "VALIDATION" | "PIPELINE" | "UNKNOWN",
  error_code: string,  // Stable enum (e.g., "UPLOAD_AUTH_EXPIRED")
  message: string,     // Human-readable message
  http_status?: number,
  upload_session_id: string | null,
  asset_id: string | null,
  file_name: string,
  file_type: string,   // File extension (e.g., "pdf", "jpg")
  retryable: boolean,
  raw: {}              // Dev-only: original error payload
}
```

### Backend Error Response Shape

```json
{
  "error_code": "UPLOAD_AUTH_EXPIRED",
  "message": "Your session has expired. Please refresh and try again.",
  "category": "AUTH",
  "context": {
    "upload_session_id": "uuid",
    "asset_id": null,
    "file_type": "pdf",
    "pipeline_stage": "upload|finalize|thumbnail"
  }
}
```

### Error Categories

- **AUTH**: Authentication/authorization failures (401, 403, 419)
- **CORS**: Browser CORS/preflight blocking
- **NETWORK**: Network failures, timeouts, server errors
- **VALIDATION**: File validation errors (size, type, format)
- **PIPELINE**: Pipeline/resource conflicts (409, 410, 423, expired sessions)
- **UNKNOWN**: Unclassified errors

### Stable Error Codes

Error codes are **stable string enums** for AI pattern detection:
- `UPLOAD_AUTH_EXPIRED`
- `UPLOAD_AUTH_REQUIRED`
- `UPLOAD_PERMISSION_DENIED`
- `UPLOAD_SESSION_NOT_FOUND`
- `UPLOAD_SESSION_EXPIRED`
- `UPLOAD_PIPELINE_CONFLICT`
- `UPLOAD_FILE_TOO_LARGE`
- `UPLOAD_VALIDATION_FAILED`
- `UPLOAD_FINALIZE_VALIDATION_FAILED`
- `UPLOAD_FILE_MISSING`
- `UPLOAD_SERVER_ERROR`
- `UPLOAD_UNKNOWN_ERROR`

---

## Key Files (Locked)

### Frontend
- `resources/js/utils/uploadErrorNormalizer.js` - Error normalization utility
- `resources/js/utils/environment.js` - Environment detection utility
- `resources/js/Components/DevUploadDiagnostics.jsx` - Diagnostics panel
- `resources/js/Components/UploadItemRow.jsx` - Retry-state UI (UI-only)

### Backend
- `app/Http/Responses/UploadErrorResponse.php` - Error response helper
- `app/Http/Controllers/UploadController.php` - Uses UploadErrorResponse

---

## AI-Support Intent

Phase 2.5 establishes signals for future AI agent consumption:

### Pattern Detection Capabilities
- Group by `error_code` to detect repeated failures
- Group by `file_type` to identify file-type-specific issues
- Group by `pipeline_stage` to pinpoint failure location
- Group by `category` for high-level failure analysis
- Track `upload_session_id` for session-level correlation

### Future AI Use Cases (Not Implemented)
- "Company X had 5 failed uploads in 1 hour"
- "All PDFs are failing thumbnail generation"
- "Upload failures spike during peak hours"
- Automatic support ticket generation
- Error pattern alerts

**Note:** Phase 2.5 only emits signals. AI consumption logic is not part of this phase.

---

## Dev-Only Diagnostics Panel

### Visibility Rules
- **Environment-gated**: Only visible in development
- **Read-only**: No mutations, no actions, no buttons
- **Auto-hidden**: Returns `null` in production

### Displayed Information
- Upload session IDs
- File metadata (name, type, size)
- Upload status
- Normalized error details
- AI-support context signals

### Gating Logic
Uses `allowDiagnostics()` from `utils/environment.js`:
- Checks `window.__DEV_UPLOAD_DIAGNOSTICS__`
- Checks `process.env.NODE_ENV === 'development'`
- Checks Vite environment variables

**DO NOT** remove or weaken this gating.

---

## Explicit Non-Goals

Phase 2.5 explicitly does **NOT** include:

- ❌ Retry logic implementation
- ❌ Automatic error recovery
- ❌ Analytics aggregation
- ❌ Alert systems
- ❌ Support ticket generation
- ❌ Realtime error monitoring
- ❌ WebSocket/polling systems
- ❌ Production error exposure
- ❌ Error mutation endpoints
- ❌ Admin error management UI

These are out of scope and reserved for future phases.

---

## Phase 3 Status

**Phase 3 (Asset Interaction UX) is EXPLICITLY SKIPPED.**

The next planned phase after Phase 2.5 is the **Downloader System** phase.

Do not implement Phase 3 features or refactor Phase 3 code that may exist in the codebase.

---

## Migration & Consumption Rules

### For Future Phases

Future phases that need upload error information should:

1. **Consume normalized errors** from `item.error.normalized` (frontend)
2. **Consume error responses** matching the backend contract (API)
3. **Use error codes** for stable pattern matching
4. **Respect retryability** flags for user guidance
5. **Preserve environment gating** for diagnostics

### Anti-Patterns (DO NOT)

- ❌ Re-implementing error normalization
- ❌ Creating alternate error shapes
- ❌ Bypassing normalization utilities
- ❌ Exposing diagnostics in production
- ❌ Weakening environment checks
- ❌ Modifying error category mappings

---

## Lock Enforcement

### Code Guardrails

All Phase 2.5 files contain lock guard comments:

```
🔒 Phase 2.5 — Observability Layer (LOCKED)
This file is part of a locked phase. Do not refactor or change behavior.
Future phases may consume emitted signals only.
```

### Review Guidelines

When reviewing code changes:

1. ✅ **ALLOW**: Consuming normalized errors
2. ✅ **ALLOW**: Adding features that read error signals
3. ❌ **REJECT**: Modifying error normalization logic
4. ❌ **REJECT**: Changing error shape contracts
5. ❌ **REJECT**: Removing environment gating
6. ❌ **REJECT**: Refactoring Phase 2.5 utilities

### Breaking Change Policy

Any proposed changes to Phase 2.5 must:
- Maintain backward compatibility
- Preserve all error signals
- Keep environment gating intact
- Not modify error shapes

If breaking changes are required, they must be approved as a new phase, not a modification to Phase 2.5.

---

## Testing & Validation

Phase 2.5 behavior is validated by:

1. **Error Normalization**: All errors produce normalized shapes
2. **Backend Consistency**: All upload endpoints return consistent errors
3. **Environment Gating**: Diagnostics only visible in dev
4. **Retry Clarity**: Retryability is clearly communicated
5. **AI Signals**: Required fields present for pattern detection

**DO NOT** modify test expectations without explicit approval.

---

## History

- **Step 1**: Frontend error normalization
- **Step 2**: Backend error response consistency
- **Step 3**: Dev-only diagnostics panel
- **Step 4**: Environment awareness & logging polish
- **Step 5**: Retry-state clarity (UI only)

---

## Related Documentation

- `docs/PHASE_2_UPLOAD_SYSTEM.md` - Phase 2 upload system (locked)
- `docs/PHASE_3_UPLOADER_FIXES.md` - Phase 3 fixes (locked)

---

**Last Updated:** 2024  
**Lock Status:** 🔒 ACTIVE  
**Next Review:** Only when Downloader System phase is planned
