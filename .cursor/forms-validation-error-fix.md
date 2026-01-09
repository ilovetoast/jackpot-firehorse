# Form Data Clearing on Validation Errors - Fix Documentation

## Issue Description

When Inertia.js forms encounter validation errors, form data was being cleared instead of preserved. This happened because:

1. **Backend**: Laravel's `$request->old()` returns empty when catching `ValidationException` for multipart/form-data requests
2. **Middleware**: Inertia's `parent::share($request)` doesn't always include `old` input in shared props
3. **Frontend**: `useForm` initializes before `old` values are available in props

## Affected Forms

- ✅ **Fixed**: `/app/support/tickets/create` - Ticket creation form
- ✅ **Fixed**: `/app/support/tickets/{id}` - Ticket reply form  
- ⚠️ **Needs Fix**: `/invite/complete/{token}/{tenant}` - Brand member invitation signup form

## Solution Pattern

### Backend Controller Pattern

When catching `ValidationException`, manually extract and preserve input:

```php
try {
    $validated = $request->validate([...]);
} catch (\Illuminate\Validation\ValidationException $e) {
    // Manually preserve old input for Inertia
    // Extract input directly from request since $request->old() may be empty at this point
    $inputToPreserve = $request->only(['field1', 'field2', 'field3']);
    
    return back()
        ->withErrors($e->errors())
        ->withInput($inputToPreserve);
}
```

### Middleware Pattern

In `HandleInertiaRequests.php`, ensure `old` input is included in shared props:

```php
$parentShared = parent::share($request);

// Manually ensure 'old' input is included if it exists in session but not in parent shared
$sessionOldInput = $request->session()->getOldInput();
if (!empty($sessionOldInput) && !isset($parentShared['old'])) {
    $parentShared['old'] = $sessionOldInput;
}

$shared = [
    ...$parentShared,
    // ... rest of shared data
];
```

### Frontend Pattern

Use `useMemo` to initialize form with old values and `useEffect` with ref to sync:

```jsx
import { useForm, usePage } from '@inertiajs/react'
import { useState, useEffect, useRef, useMemo } from 'react'

export default function MyForm() {
    const { auth, old } = usePage().props
    
    // Initialize useForm with old values if they exist (from validation errors)
    // Use useMemo to ensure we always use the latest old values
    const initialFormData = useMemo(() => ({
        field1: old?.field1 || '',
        field2: old?.field2 || '',
        // ... other fields
    }), [old])

    const { data, setData, post, processing, errors } = useForm(initialFormData)
    
    const hasSyncedOldRef = useRef(false)

    // Sync form data with old input when validation errors occur
    // This ensures form data is preserved after validation errors
    // Use a ref to track if we've synced to avoid infinite loops
    useEffect(() => {
        // Only sync once when old becomes available
        if (old && !hasSyncedOldRef.current) {
            hasSyncedOldRef.current = true
            
            // Update all fields from old input
            if (old.field1 !== undefined) {
                setData('field1', old.field1)
            }
            if (old.field2 !== undefined) {
                setData('field2', old.field2)
            }
            // ... update other fields
        }
        
        // Reset sync flag when old becomes unavailable (new form submission)
        if (!old) {
            hasSyncedOldRef.current = false
        }
    }, [old, setData])
    
    // ... rest of component
}
```

## Key Points

1. **Backend**: Always use `$request->only()` to extract input, not `$request->old()` when catching exceptions
2. **Middleware**: Check session for old input and manually add to Inertia shared props if missing
3. **Frontend**: 
   - Initialize `useForm` with `old` values using `useMemo`
   - Use `useEffect` with a ref to sync when `old` becomes available
   - Never include `data` in `useEffect` dependencies to avoid infinite loops

## Files Modified

- `app/Http/Controllers/TenantTicketController.php` - Added manual input preservation
- `app/Http/Middleware/HandleInertiaRequests.php` - Added manual old input inclusion
- `resources/js/Pages/Support/Tickets/Create.jsx` - Added old input initialization and sync
- `resources/js/Pages/Support/Tickets/Show.jsx` - Same pattern for reply form

## Date Fixed

January 8, 2026

## Forms Status

### ✅ Fixed Forms
- `/app/support/tickets/create` - Ticket creation form
- `/app/support/tickets/{id}` - Ticket reply form  
- `/invite/complete/{token}/{tenant}` - Brand member invitation signup form

### ⚠️ Forms That May Need Fix (if issues reported)
Forms using `forceFormData: true` (file uploads) are most susceptible:
- `/app/brands/create` - Brand creation (file upload)
- `/app/brands/{id}/edit` - Brand editing (file upload)
- `/app/profile` - Profile update (file upload)

Other forms should benefit from the middleware fix, but may need controller fixes if issues occur.

## Optimization Notes

The middleware fix (`HandleInertiaRequests.php`) applies globally to all Inertia requests, so it helps all forms. However, forms using `forceFormData: true` (multipart/form-data) are more likely to need the controller-level fix because:

1. Laravel's `$request->old()` is often empty for multipart requests when catching ValidationException
2. The middleware fix ensures old input is in shared props, but controllers still need to preserve it with `->withInput()`

## Testing Checklist

When adding new forms or fixing existing ones:
- [ ] Test form submission with validation errors
- [ ] Verify form data is preserved (not cleared)
- [ ] Check that `old` prop is available in frontend
- [ ] For file uploads (`forceFormData: true`), ensure controller uses `$request->only()` pattern
- [ ] Verify no infinite loops in `useEffect` (don't include `data` in dependencies)
