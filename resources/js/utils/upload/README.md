# Phase 3 Upload Architecture

**Status: Architecture & State Modeling (No UI)**

This directory contains the frontend architecture and pure utility functions for Phase 3 upload management. These are **architecture-only** components with no UI, styling, or backend dependencies.

---

## Overview

Phase 3 builds a state management layer on top of the existing Phase 2 upload system. The Phase 2 backend (UploadSession, S3, presigned URLs) remains **locked and unchanged**. Phase 3 adds:

- **Type-safe state models** for upload context, items, and metadata
- **Pure helper functions** for metadata resolution and category switching
- **Explicit separation** between global and per-file metadata
- **Category change handling** with automatic metadata cleanup

---

## File Structure

```
resources/js/
├── types/upload/              # TypeScript type definitions
│   ├── UploadContext.d.ts     # Company, brand, category context
│   ├── MetadataField.d.ts     # Metadata field definitions
│   ├── UploadItem.d.ts        # Single file upload item
│   ├── UploadManagerState.d.ts # Complete state model
│   └── index.d.ts             # Central type exports
│
└── utils/upload/               # Pure helper functions
    ├── metadataResolution.js  # Metadata draft resolution
    ├── categorySwitching.js   # Category change handling
    ├── index.js               # Utility exports
    └── README.md              # This file
```

---

## Core Concepts

### UploadContext

The canonical context for an upload session:
- `companyId`: Tenant/organization ID
- `brandId`: Brand within the company
- `categoryId`: Category for asset assignment (can change until finalization)

### UploadItem

Represents a single file in the upload batch:
- `clientId`: Frontend UUID for state management
- `file`: Native File object
- `uploadSessionId`: Backend session ID (from Phase 2)
- `uploadStatus`: queued | uploading | complete | failed
- `metadataDraft`: Per-file metadata overrides
- `isMetadataOverridden`: Tracks which fields are overridden

### Metadata Resolution

**Global metadata** applies to all files by default. **Per-file overrides** supersede global values. The resolution rules ensure:

1. Global changes don't overwrite overridden fields
2. New fields are automatically applied to non-overridden items
3. Override flags prevent accidental overwrites

### Category Switching

Category changes are allowed until finalization. When category changes:

1. **Available metadata fields** are recomputed from the new category
2. **Invalid metadata keys** are removed from global and per-file drafts
3. **Warnings** are generated describing what changed
4. **Upload sessions are preserved** (no interruption to uploads)

---

## Pure Functions

All functions in `utils/upload/` are **pure** (no side effects):

### Metadata Resolution

- `applyGlobalMetadata(items, globalMetadataDraft)`: Applies global metadata to items, respecting overrides
- `overrideItemMetadata(item, overrides)`: Sets per-file metadata overrides
- `resolveEffectiveMetadata(item, globalMetadataDraft)`: Gets final metadata for an item
- `markFieldAsOverridden(item, fieldKey)`: Locks a field to prevent global changes
- `unmarkFieldAsOverridden(item, fieldKey)`: Unlocks a field

### Category Switching

- `handleCategoryChange(state, newCategoryId, newMetadataFields)`: Handles category change with cleanup
- `validateRequiredMetadataFields(state)`: Validates required fields are filled

---

## Usage Example

```javascript
import { 
    applyGlobalMetadata, 
    overrideItemMetadata, 
    resolveEffectiveMetadata,
    handleCategoryChange 
} from '../utils/upload';
import type { UploadManagerState } from '../types/upload';

// Apply global metadata to all items
const updatedItems = applyGlobalMetadata(state.items, { color: 'blue', tags: ['photo'] });

// Override metadata for a specific file
const itemWithOverride = overrideItemMetadata(item, { color: 'red' });

// Get effective metadata (global + overrides)
const effective = resolveEffectiveMetadata(item, state.globalMetadataDraft);

// Handle category change
const newState = handleCategoryChange(state, newCategoryId, newMetadataFields);
```

---

## Design Principles

1. **No Side Effects**: All functions are pure and testable
2. **Immutable Updates**: Functions return new objects/arrays, not mutations
3. **Explicit Overrides**: Per-file overrides are explicitly tracked
4. **Category Safety**: Category changes clean up invalid metadata automatically
5. **Warning System**: User-friendly warnings describe state changes
6. **Type Safety**: TypeScript definitions provide compile-time safety

---

## Integration with Phase 2

Phase 3 functions work **on top of** Phase 2's `UploadManager`:

- Phase 2 handles: Upload sessions, S3 transfers, resume logic
- Phase 3 handles: Metadata drafts, category switching, state management

The `UploadItem.uploadSessionId` connects Phase 3 state to Phase 2 upload sessions.

---

## What This Does NOT Do

- ❌ Persist metadata to backend
- ❌ Create assets
- ❌ Finalize uploads
- ❌ Call backend APIs
- ❌ Render UI components
- ❌ Handle styling

These are deferred to future phases or UI implementation.

---

## Next Steps

After architecture is complete:

1. **UI Components**: Build React components using these types and functions
2. **State Management**: Integrate with React state (useState, useReducer, or state library)
3. **Backend Integration**: Add endpoints for metadata persistence (if needed)
4. **Finalization**: Implement upload finalization with metadata

---

## Safety Guarantees

- ✅ No backend changes required
- ✅ No Phase 2 code modifications
- ✅ No upload session contract changes
- ✅ No S3 path assumptions
- ✅ Safe to layer on top of locked backend

If any requirement would force a backend change, **STOP and ask for clarification**.
