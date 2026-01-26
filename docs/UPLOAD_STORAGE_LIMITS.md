# Upload Storage Limits Implementation

## Overview

This implementation adds comprehensive storage limit checking and user warnings before file uploads. It prevents users from exceeding their plan's storage limits and provides clear feedback about usage and restrictions.

## Features

### 🛡️ **Plan-Based Storage Limits**
- Different storage limits per plan (Free: 100MB, Starter: 1GB, Pro: ~10GB, Enterprise: ~1TB)
- Real-time usage calculation based on visible assets
- Automatic enforcement during upload initiation

### ⚠️ **Pre-Upload Validation**
- Check files before upload starts
- Validate both individual file size limits and total storage limits
- Batch validation for multiple files
- Clear error messages for different limit types

### 🎨 **User-Friendly Warnings**
- Visual storage usage indicators
- Progressive warning levels (info → warning → error)
- Detailed breakdown of storage usage
- Upgrade prompts when limits are reached

### 🔄 **Real-Time Feedback**
- Live validation as users select files
- Immediate feedback without server round-trips for basic checks
- Server validation for accurate storage calculations

## Backend Implementation

### 1. **PlanService Extensions**

Added new methods to `app/Services/PlanService.php`:

```php
// Get current storage usage in bytes
public function getCurrentStorageUsage(Tenant $tenant): int

// Get storage usage as percentage of plan limit  
public function getStorageUsagePercentage(Tenant $tenant): float

// Check if adding a file would exceed limits
public function canAddFile(Tenant $tenant, int $fileSizeBytes): bool

// Get comprehensive storage information
public function getStorageInfo(Tenant $tenant): array

// Enforce storage limits (throws exception if exceeded)
public function enforceStorageLimit(Tenant $tenant, int $additionalBytes): void
```

### 2. **Upload Validation Integration**

Enhanced `app/Services/UploadInitiationService.php`:

```php
protected function validatePlanLimits(Tenant $tenant, int $fileSize): void
{
    // Check individual file size limit
    $maxUploadSize = $this->planService->getMaxUploadSize($tenant);
    if ($fileSize > $maxUploadSize) {
        throw new PlanLimitExceededException(/*...*/);
    }

    // Check total storage limit
    $this->planService->enforceStorageLimit($tenant, $fileSize);
}
```

### 3. **New API Endpoints**

Added to `app/Http/Controllers/UploadController.php`:

#### `GET /app/uploads/storage-check`
Returns current storage information:
```json
{
  "storage": {
    "current_usage_mb": 245.47,
    "max_storage_mb": 1024,
    "usage_percentage": 23.97,
    "remaining_mb": 778.53,
    "is_unlimited": false,
    "is_near_limit": false,
    "is_at_limit": false
  },
  "limits": {
    "max_upload_size_mb": 50
  },
  "plan": {
    "name": "pro"
  }
}
```

#### `POST /app/uploads/validate`
Validates specific files before upload:
```json
{
  "files": [
    {
      "file_name": "large-image.jpg",
      "file_size": 52428800,
      "can_upload": false,
      "errors": [
        {
          "type": "file_size_limit",
          "message": "File size (50 MB) exceeds maximum upload size (10 MB) for your plan."
        }
      ]
    }
  ],
  "batch_summary": {
    "total_files": 1,
    "total_size_mb": 50,
    "can_upload_batch": false,
    "storage_exceeded": true
  }
}
```

## Frontend Implementation

### 1. **React Hook: `useStorageLimits`**

Located at `resources/js/hooks/useStorageLimits.js`:

```jsx
const {
  storageInfo,
  isLoading,
  validateFiles,
  canUploadFile,
  canUploadFiles,
  isNearStorageLimit,
  isAtStorageLimit
} = useStorageLimits()
```

### 2. **Storage Warning Component**

`resources/js/Components/StorageWarning.jsx` displays:
- Current storage usage with visual progress bar
- Projected usage when files are selected
- Warning levels (info, warning, error)
- Upgrade prompts when needed

### 3. **Upload Gate Component**

`resources/js/Components/UploadGate.jsx` provides:
- Automatic file validation
- Error/warning display
- Upload prevention when limits exceeded
- Integration with existing upload dialogs

### 4. **Integration Example**

See `resources/js/Components/Examples/UploadDialogWithGate.jsx` for a complete example of how to integrate the upload gate into existing upload dialogs.

## Plan Configuration

Storage limits are configured in `config/plans.php`:

```php
'free' => [
    'limits' => [
        'max_storage_mb' => 100,        // 100 MB
        'max_upload_size_mb' => 10,     // 10 MB per file
    ],
],
'starter' => [
    'limits' => [
        'max_storage_mb' => 1024,       // 1 GB  
        'max_upload_size_mb' => 50,     // 50 MB per file
    ],
],
'pro' => [
    'limits' => [
        'max_storage_mb' => 999999,     // ~1 TB (unlimited)
        'max_upload_size_mb' => 999999, // ~1 TB (unlimited)
    ],
],
```

## Usage Examples

### Basic Integration

```jsx
import UploadGate from '../Components/UploadGate'

function MyUploadDialog({ selectedFiles, onUpload }) {
  const [validationResults, setValidationResults] = useState(null)
  
  return (
    <div>
      {/* File selection UI */}
      
      <UploadGate
        selectedFiles={selectedFiles}
        onValidationChange={setValidationResults}
        autoValidate={true}
        showStorageDetails={true}
      />
      
      <button 
        disabled={!validationResults?.canProceed}
        onClick={onUpload}
      >
        Upload
      </button>
    </div>
  )
}
```

### Manual Validation

```jsx
import { useStorageLimits } from '../hooks/useStorageLimits'

function FileValidator() {
  const { validateFiles, storageInfo } = useStorageLimits()
  
  const handleValidation = async (files) => {
    const results = await validateFiles(files)
    
    if (results.batch_summary.can_upload_batch) {
      // Proceed with upload
    } else {
      // Show errors to user
    }
  }
}
```

### Storage Information Display

```jsx
import StorageWarning from '../Components/StorageWarning'

function StorageUsageWidget() {
  const { storageInfo } = useStorageLimits()
  
  return (
    <StorageWarning
      storageInfo={storageInfo?.storage}
      showDetails={true}
    />
  )
}
```

## Error Handling

The system provides different types of errors:

### File Size Limit Exceeded
- **Type**: `file_size_limit`
- **Cause**: Individual file exceeds plan's max upload size
- **Solution**: Reduce file size or upgrade plan

### Storage Limit Exceeded
- **Type**: `storage_limit`  
- **Cause**: Adding files would exceed total storage limit
- **Solution**: Delete existing assets or upgrade plan

### Batch Storage Exceeded
- **Cause**: Multiple files together exceed available storage
- **Solution**: Upload fewer files at once or upgrade plan

## Testing

### Backend Testing

```php
// Test storage calculation
$planService = new PlanService();
$storageInfo = $planService->getStorageInfo($tenant);

// Test limit enforcement
try {
    $planService->enforceStorageLimit($tenant, $fileSize);
} catch (PlanLimitExceededException $e) {
    // Handle limit exceeded
}
```

### Frontend Testing

```javascript
// Test validation hook
const { validateFiles } = useStorageLimits()
const results = await validateFiles(mockFiles)

// Test storage checking
const { canUploadFile } = useStorageLimits()
const canUpload = canUploadFile(mockFile)
```

## Integration Checklist

To integrate into existing upload dialogs:

- [ ] Import `UploadGate` component
- [ ] Add `selectedFiles` state tracking  
- [ ] Add `validationResults` state handling
- [ ] Disable upload button when `!validationResults?.canProceed`
- [ ] Show storage warnings in dialog
- [ ] Handle validation errors appropriately
- [ ] Test with different plan limits
- [ ] Test with files that exceed limits
- [ ] Test batch uploads
- [ ] Test upgrade flow from warnings

## Future Enhancements

Potential improvements:
- [ ] Real-time storage usage updates via WebSockets
- [ ] File compression suggestions for large files
- [ ] Smart batching recommendations
- [ ] Storage cleanup suggestions
- [ ] Usage analytics and insights
- [ ] Predictive warnings ("at current usage, you'll hit limit in X days")
- [ ] Integration with file optimization services