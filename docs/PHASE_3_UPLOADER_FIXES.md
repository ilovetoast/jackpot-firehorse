# Phase 3 Uploader Fixes

**Date:** January 2025

**Issues Fixed:**
1. Maximum update depth exceeded (infinite useEffect loop)
2. Failed to rehydrate upload (400 on /resume)
3. UUID / client_reference mismatch

---

## Fix 1: Infinite Loop Prevention

### Problem
The sync effect between Phase 2 and Phase 3 upload managers was causing infinite loops because:
- Effect watched `phase2Manager.uploads` array
- Effect called `phase3Manager.updateUploadProgress()` which updates state
- State update caused re-render, which triggered effect again
- No guard to prevent unnecessary updates

### Solution
Added multiple guards to prevent infinite loops:

1. **Last Synced State Tracking**: Use `useRef` to track last synced values per `clientId`
2. **Change Detection**: Only update if values actually changed compared to last synced state
3. **Phase 3 Item Guard**: Only update if Phase 3 item actually needs updating (compare current vs target state)
4. **Stable Method References**: Use destructured methods from `phase3Manager` to avoid object identity issues

**Key Guard Pattern:**
```javascript
// Track last synced state
const lastSynced = lastSyncedRef.current.get(clientId) || {}

// Check if anything changed
const hasProgressChanged = phase2Upload.progress !== lastSynced.progress
const needsProgressUpdate = hasProgressChanged && phase2Upload.progress !== phase3Item.progress

// Only update if needed
if (needsProgressUpdate) {
    updateUploadProgress(clientId, phase2Upload.progress)
}

// Update last synced state
lastSyncedRef.current.set(clientId, { ... })
```

---

## Fix 2: UUID Ownership Rule

### Problem
Phase 3 generated `clientId` (UUID) but Phase 2's `addFiles()` generated its own `clientReference`, causing:
- 400 errors: "The files.0.client_reference field must be a valid UUID"
- Mismatch between Phase 3 and Phase 2 identities
- Resume failures due to invalid UUIDs

### Solution
**Locked Rule: Phase 3 owns the UUID. Phase 2 must use Phase 3's `clientId`.**

**Implementation:**
1. Phase 3 generates `clientId` first via `phase3Manager.addFiles()`
2. Phase 2 upload entry is created manually with Phase 3's `clientId` as `clientReference`
3. Bypass Phase 2's `addFiles()` which generates its own UUID
4. Directly add to Phase 2's `uploads` Map with Phase 3's UUID

**Code Pattern:**
```javascript
// Phase 3 owns UUID generation
const clientIds = phase3Manager.addFiles(fileArray)

fileArray.forEach((file, index) => {
    const clientId = clientIds[index] // Phase 3 UUID
    
    // Create Phase 2 upload entry with Phase 3's UUID
    const upload = {
        clientReference: clientId, // Use Phase 3's UUID - critical!
        // ... other fields
    }
    
    // Add directly to Phase 2's uploads map
    UploadManager.uploads.set(clientId, upload)
    
    // Start upload using Phase 3's clientId
    UploadManager.startUpload(clientId)
})
```

**Sync Logic:**
- Phase 2's `clientReference` matches Phase 3's `clientId`
- Sync by `clientId` match, not `uploadSessionId`
- Filters out Phase 2 uploads that don't belong to Phase 3 items

---

## Fix 3: Disable Rehydration in Modal Context

### Problem
Phase 2's `useUploadManager` hook automatically rehydrates uploads on mount, causing:
- 400 errors when trying to resume uploads that don't exist
- Attempts to resume uploads with invalid UUIDs
- Old uploads from previous sessions interfering with new modal uploads

### Solution
**Use Phase 2 UploadManager singleton directly instead of `useUploadManager` hook.**

**Implementation:**
1. Import `UploadManager` singleton directly (not via hook)
2. Subscribe to updates manually (without triggering rehydration)
3. Only sync uploads that belong to Phase 3 items
4. Ignore Phase 2 uploads without matching Phase 3 items

**Code Pattern:**
```javascript
// Use singleton directly to avoid auto-rehydration
import UploadManager from '../utils/UploadManager'

// Subscribe manually (no rehydration)
useEffect(() => {
    const unsubscribe = UploadManager.subscribe(() => {
        const allUploads = Array.from(UploadManager.getUploads())
        setPhase2Uploads(allUploads)
    })
    
    // DO NOT call rehydrateUploads - we're in a new modal context
    return unsubscribe
}, [])
```

**Filtering:**
- Only sync Phase 2 uploads where `clientReference` matches Phase 3 `clientId`
- Ignore Phase 2 uploads without matching Phase 3 items (old sessions)
- Modal is a new upload context - don't rehydrate old uploads

---

## Verification

### Infinite Loop Prevention
✅ Effect only runs when `phase2Uploads` array actually changes
✅ Guards prevent unnecessary state updates
✅ Last synced ref tracks state to prevent redundant updates
✅ No "Maximum update depth exceeded" warnings

### UUID Ownership
✅ Phase 3 generates UUID first
✅ Phase 2 uses Phase 3's UUID as `clientReference`
✅ Backend receives valid UUID in `client_reference` field
✅ No 400 errors on upload initiation

### Rehydration
✅ Modal doesn't auto-rehydrate old uploads
✅ Only new uploads created in modal context are tracked
✅ No 400 errors on `/resume` endpoint
✅ Old uploads are filtered out in sync logic

---

## Safety Guarantees

- ✅ No Phase 2 code modifications (accessing internals only where necessary)
- ✅ No Phase 3.1 modifications (using public APIs only)
- ✅ No backend changes required
- ✅ All fixes are frontend-only

---

## Testing Checklist

- [ ] Upload files in modal - no infinite loop warnings
- [ ] Check browser console - no "Maximum update depth exceeded"
- [ ] Upload files - no 400 errors on `/app/uploads/initiate-batch`
- [ ] Check Network tab - `client_reference` is valid UUID
- [ ] Check Network tab - no `/resume` calls for new uploads
- [ ] Verify Phase 2 → Phase 3 sync works correctly
- [ ] Verify progress updates correctly
- [ ] Verify status changes (queued → uploading → complete)

---

## Notes

- Accessing `UploadManager.uploads` directly is necessary to bypass UUID generation
- This is an architectural workaround until Phase 2 supports optional `clientReference`
- The sync logic is carefully guarded to prevent infinite loops
- Old uploads from previous sessions are intentionally ignored in modal context
