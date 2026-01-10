# Phase 3.1 Upload Manager Hook

**Status: State Management Layer (No UI)**

The `usePhase3UploadManager` hook provides state management for upload batches with metadata support. This is a **state layer only** - it manages state but does not handle actual uploads or render UI components.

---

## Overview

This hook manages:
- **Upload items** (files) in a batch
- **Global and per-file metadata** with override protection
- **Category selection** with automatic metadata cleanup
- **Validation and warnings** for user feedback

It integrates with:
- **Phase 3.0 helpers** for metadata resolution and category switching
- **Phase 2 UploadManager** for actual upload operations (via integration layer)

---

## Usage

```javascript
import { usePhase3UploadManager } from '../hooks/usePhase3UploadManager';

function MyComponent() {
    const context = {
        companyId: 1,
        brandId: 2,
        categoryId: 3
    };

    const uploadManager = usePhase3UploadManager(context);

    // Add files
    const handleFileSelect = (files) => {
        uploadManager.addFiles(Array.from(files));
    };

    // Set global metadata
    uploadManager.setGlobalMetadata('color', 'blue');
    uploadManager.setGlobalMetadata('tags', ['photo', 'outdoor']);

    // Override metadata for specific file
    uploadManager.overrideItemMetadata(clientId, 'color', 'red');

    // Change category
    uploadManager.changeCategory(newCategoryId, metadataFields);

    // Access state
    const { items, globalMetadataDraft, warnings } = uploadManager.state;
}
```

---

## API Reference

### State Properties

- `state: UploadManagerState` - Complete state object
- `context: UploadContext` - Upload context (company, brand, category)
- `items: UploadItem[]` - Array of upload items
- `globalMetadataDraft: Record<string, any>` - Global metadata
- `availableMetadataFields: MetadataField[]` - Available metadata fields
- `warnings: UploadWarning[]` - Warning messages

### Computed Properties

- `queuedItems: UploadItem[]` - Items in 'queued' status
- `uploadingItems: UploadItem[]` - Items in 'uploading' status
- `completedItems: UploadItem[]` - Items in 'complete' status
- `failedItems: UploadItem[]` - Items in 'failed' status
- `hasItems: boolean` - Whether batch has any items
- `canFinalize: boolean` - Whether batch can be finalized

### Mutation Methods

#### File Management

- `addFiles(files: File[]): string[]` - Add files to batch, returns clientIds
- `removeItem(clientId: string): void` - Remove item from batch
- `setResolvedFilename(clientId: string, filename: string): void` - Edit filename

#### Upload Status

- `updateUploadProgress(clientId: string, progress: number): void` - Update progress (0-100)
- `markUploadComplete(clientId: string, uploadSessionId?: string): void` - Mark as complete
- `markUploadFailed(clientId: string, error: UploadError | string): void` - Mark as failed
- `setUploadSessionId(clientId: string, uploadSessionId: string): void` - Set session ID

#### Metadata Management

- `setGlobalMetadata(key: string, value: any): void` - Set global metadata field
- `overrideItemMetadata(clientId: string, key: string, value: any): void` - Override per-file
- `clearItemOverride(clientId: string, key: string): void` - Clear override flag

#### Category Management

- `changeCategory(categoryId: string | number | null, metadataFields?: MetadataField[]): void` - Change category
- `setAvailableMetadataFields(metadataFields: MetadataField[]): void` - Update available fields

#### Validation

- `validateMetadata(): UploadWarning[]` - Validate required fields, returns warnings
- `clearWarnings(): void` - Clear all warnings

### Query Methods

- `getEffectiveMetadata(clientId: string): Record<string, any>` - Get final metadata (global + overrides)
- `getItem(clientId: string): UploadItem | undefined` - Get item by client ID

---

## Integration with Phase 2

The hook manages state but does not handle actual uploads. To integrate with Phase 2 UploadManager:

```javascript
import { usePhase3UploadManager } from '../hooks/usePhase3UploadManager';
import { useUploadManager } from '../hooks/useUploadManager'; // Phase 2

function UploadIntegration() {
    const phase3 = usePhase3UploadManager(context);
    const phase2 = useUploadManager(); // Phase 2 upload manager

    // When Phase 2 reports progress, update Phase 3 state
    useEffect(() => {
        phase2.uploads.forEach(upload => {
            const item = phase3.items.find(i => 
                i.uploadSessionId === upload.uploadSessionId
            );
            if (item) {
                phase3.updateUploadProgress(item.clientId, upload.progress);
            }
        });
    }, [phase2.uploads]);

    // When Phase 2 completes, mark in Phase 3
    useEffect(() => {
        phase2.completedUploads.forEach(upload => {
            const item = phase3.items.find(i => 
                i.uploadSessionId === upload.uploadSessionId
            );
            if (item) {
                phase3.markUploadComplete(item.clientId, upload.uploadSessionId);
            }
        });
    }, [phase2.completedUploads]);
}
```

---

## State Rules

1. **Global metadata changes respect overrides** - Overridden fields are never overwritten
2. **Upload state changes don't mutate metadata** - Progress/status updates are independent
3. **Category changes trigger warnings** - User is informed of metadata cleanup
4. **Uploads are never discarded** - Category changes preserve upload sessions

---

## Error Handling

Errors are stored as structured `UploadError` objects:

```typescript
interface UploadError {
    message: string;           // User-friendly message
    type: 'network' | 'auth' | 'expired' | 'server' | 'validation' | 'unknown';
    httpStatus?: number;        // HTTP status if applicable
    rawError?: any;            // Raw error (dev only)
}
```

No alerts, toasts, or console spam - errors are stored in state for UI to display.

---

## Validation

The `validateMetadata()` method checks:
- Required metadata fields have values
- Global metadata is valid
- Per-file overrides are valid

Returns warnings array - does not throw. UI should check `canFinalize` before allowing finalization.

---

## Design Principles

1. **Pure State Management** - No side effects, no upload logic
2. **Override Protection** - Global changes never overwrite user edits
3. **Category Safety** - Automatic cleanup of invalid metadata
4. **Structured Errors** - All errors are typed and structured
5. **Computed Values** - Derived state is memoized for performance

---

## What This Does NOT Do

- ❌ Handle actual file uploads (Phase 2 responsibility)
- ❌ Render UI components
- ❌ Persist metadata to backend
- ❌ Create assets
- ❌ Finalize uploads
- ❌ Call backend APIs

These are deferred to integration layers or future phases.

---

## Next Steps

After state management is complete:

1. **Integration Layer** - Connect Phase 2 UploadManager to Phase 3 state
2. **UI Components** - Build React components using this hook
3. **Backend Integration** - Add endpoints for metadata persistence
4. **Finalization** - Implement upload finalization with metadata

---

## Safety Guarantees

- ✅ No backend changes required
- ✅ No Phase 2 code modifications
- ✅ No upload session contract changes
- ✅ Frontend-only state management
- ✅ Safe to layer on top of locked backend
