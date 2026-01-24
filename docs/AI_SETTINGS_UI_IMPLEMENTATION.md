# AI Settings UI Implementation - Phase J.2.5

**Status:** ✅ IMPLEMENTED  
**Last Updated:** January 2026  
**Dependencies:** Phase J.2.2 (AI Tagging Controls), Phase J.2.3 (Tag UX)

---

## Overview

The AI Settings UI provides company admins with full control over AI tagging behavior through an intuitive interface. This phase introduces the frontend components and API endpoints needed to manage the AI tag policy settings implemented in Phase J.2.2.

**Critical Principle:** This is pure UI + API wiring. No AI behavior changes. No policy logic duplication. The UI is a client of the existing `AiTagPolicyService`.

---

## User Experience

### ✅ Requirements Satisfied

1. **Reflects existing tenant AI settings** - UI loads current policy state
2. **Allows safe toggling of AI behavior** - Debounced updates with rollback on error
3. **Displays AI usage & caps** - Resilient usage panel with graceful error handling
4. **Handles empty / disabled / error states gracefully** - No hard crashes

---

## UI Components

### 1️⃣ AiTaggingSettings Component

**Location:** `resources/js/Components/Companies/AiTaggingSettings.jsx`

**Features:**
- **Master Toggle** - Disable AI Tagging completely
- **Suggested Tags Toggle** - Enable/disable AI tag suggestions
- **Auto-Apply Toggle** - Automatically apply AI tags (OFF by default)
- **Quantity Control** - Best practices vs custom limits
- **Optimistic UI** - Immediate feedback with error recovery
- **Debounced saves** - 500ms delay to prevent excessive API calls

#### Master Toggle Behavior

When AI Tagging is disabled:
- All child controls become read-only (grayed out)
- Helper text explains AI is fully skipped
- No AI calls will be made, no costs incurred

```jsx
{/* Master Toggle */}
<Switch
    checked={!settings.disable_ai_tagging}
    onChange={(enabled) => updateSetting('disable_ai_tagging', !enabled)}
    disabled={!canEdit}
/>

{/* Child settings disabled when AI is off */}
<div className={`${isAiDisabled ? 'opacity-50 pointer-events-none' : ''}`}>
    {/* Suggestion and auto-apply toggles */}
</div>
```

#### Auto-Apply Controls

- **Default State:** OFF (safe default)
- **Warning indicator:** Orange text warns about careful usage
- **Quantity selector:** Best practices (recommended) vs custom numeric input
- **Validation:** 1-50 tag limit range

### 2️⃣ AiUsagePanel Component  

**Location:** `resources/js/Components/Companies/AiUsagePanel.jsx`

**Features:**
- **Resilient error handling** - No hard crashes
- **Multiple states:** loading, success, disabled, empty, error, permission denied
- **Retry functionality** - Manual retry button with dev console hints
- **Feature breakdown** - Tagging and suggestions usage display
- **Progress indicators** - Visual usage bars with percentage

#### Error States Handled

| State | Display | Actions |
|-------|---------|---------|
| **Permission Denied** | Yellow warning with explanation | None (expected) |
| **API Error** | Red error with details | Retry button |
| **AI Disabled** | Gray info box | Link to settings above |
| **No Data** | Blue info about no usage yet | None |
| **Loading** | Spinner with status text | None |

#### Usage Display

```jsx
// Feature usage with progress bar
{features.map((featureKey) => {
    const feature = usageData.status?.[featureKey]
    
    return (
        <div className="rounded-md border border-gray-200 p-4">
            <div className="flex items-center justify-between mb-3">
                <h4 className="font-medium capitalize">{featureKey}</h4>
                <div className={`badge ${statusColor}`}>
                    {isExceeded ? 'Limit Exceeded' : 
                     percentage > 80 ? 'Near Limit' : 'Available'}
                </div>
            </div>
            
            {/* Progress bar and usage numbers */}
        </div>
    )
})}
```

---

## API Implementation

### CompanyController Extensions

**File:** `app/Http/Controllers/CompanyController.php`

#### New Endpoints

**GET `/api/companies/ai-settings`** - Get current AI settings
- Permission: `companies.settings.edit`
- Returns: Current tenant AI tag policy settings
- Error handling: Permission denied, tenant context missing

**PATCH `/api/companies/ai-settings`** - Update AI settings  
- Permission: `companies.settings.edit`
- Validation: Boolean toggles, enum modes, numeric limits
- Uses: `AiTagPolicyService::updateTenantSettings()`

#### Settings Schema

```php
$validated = $request->validate([
    'disable_ai_tagging' => 'boolean',
    'enable_ai_tag_suggestions' => 'boolean', 
    'enable_ai_tag_auto_apply' => 'boolean',
    'ai_auto_tag_limit_mode' => 'in:best_practices,custom',
    'ai_auto_tag_limit_value' => 'nullable|integer|min:1|max:50',
]);
```

#### Error Handling

```php
try {
    $settings = $this->aiTagPolicyService->getTenantSettings($tenant);
    return response()->json(['settings' => $settings]);
} catch (\Exception $e) {
    \Log::error('Error fetching AI settings', [
        'tenant_id' => $tenant->id,
        'error' => $e->getMessage(),
    ]);
    
    return response()->json([
        'error' => 'Failed to load AI settings. Please try again later.',
    ], 500);
}
```

---

## Integration with Company Settings

### Navigation Tabs

**AI Settings tab** appears before AI Usage tab for logical flow:
1. Company Information
2. Metadata Settings  
3. **AI Settings** (new - configure behavior)
4. **AI Usage** (existing - view consumption)
5. Ownership Transfer

### Permission Requirements

| Component | Permission | Fallback |
|-----------|------------|----------|
| **AI Settings** | `companies.settings.edit` | Shows "no permission" message |
| **AI Usage** | `ai.usage.view` | Shows "no permission" message |

### Tab Visibility Logic

```jsx
{/* AI Settings Tab - Edit Permission */}
{canEditCompanySettings && (
    <button onClick={() => handleSectionClick('ai-settings')}>
        AI Settings
    </button>
)}

{/* AI Usage Tab - View Permission */}  
{canViewAiUsage && (
    <button onClick={() => handleSectionClick('ai-usage')}>
        AI Usage
    </button>
)}
```

---

## State Management

### AiTaggingSettings State

```jsx
const [settings, setSettings] = useState(null)           // Current settings
const [loading, setLoading] = useState(true)             // Initial load
const [error, setError] = useState(null)                 // Load/save errors  
const [saving, setSaving] = useState(false)              // Save in progress
const [lastSaved, setLastSaved] = useState(null)         // Success feedback
```

### Optimistic Updates

```jsx
const updateSetting = (key, value) => {
    // 1. Optimistic UI update
    setSettings({ ...settings, [key]: value })
    
    // 2. Debounced API call
    debouncedUpdateSettings({ ...settings, [key]: value })
}

// Error recovery - revert optimistic update
catch (err) {
    await loadSettings() // Reload from server
    setError(err.message)
}
```

### Debounced Saves

```jsx
const debouncedUpdateSettings = useCallback(
    debounce(async (newSettings) => {
        // API call with error handling
    }, 500),
    []
)
```

---

## Visual States & Screenshots

### 1️⃣ AI Enabled - Default State

**Master Toggle:** ON  
**Suggestions:** ON  
**Auto-Apply:** OFF (safe default)  
**Quantity:** Best Practices

*Clean interface with blue accent colors for enabled features*

### 2️⃣ AI Disabled - Master Toggle OFF

**Master Toggle:** OFF  
**All child controls:** Grayed out and disabled  
**Help text:** "All AI tagging features are disabled..."

*Clear visual hierarchy showing disabled state*

### 3️⃣ Auto-Apply Enabled with Custom Limit

**Auto-Apply:** ON  
**Quantity Control:** Custom, 8 tags per asset  
**Warning:** Orange text about careful usage

*Quantity selector shows numeric input for custom limits*

### 4️⃣ Saving State

**Status indicator:** Blue spinner "Saving changes..."  
**Controls:** Remain interactive for additional changes

*Non-blocking save feedback*

### 5️⃣ Success State

**Status indicator:** Green checkmark "Settings saved at 2:34 PM"  
**Auto-hide:** Success message fades after few seconds

*Positive reinforcement for successful updates*

### 6️⃣ Error State  

**Status indicator:** Red warning "Failed to update AI settings"  
**Recovery:** Settings revert to server state automatically  
**User action:** Can retry or modify and try again

*Graceful error recovery maintains data integrity*

### 7️⃣ AI Usage - Success State

**Current month:** January 2026 usage period  
**Feature breakdown:** Tagging and suggestions with usage bars  
**Status badges:** Available, Near Limit, or Limit Exceeded

*Clean data visualization with progress indicators*

### 8️⃣ AI Usage - Error State

**Retry button:** "Try again" with explanatory text  
**Debug hint:** "check browser console for details"  
**No crash:** Graceful fallback maintains page functionality

*Resilient error handling guides troubleshooting*

### 9️⃣ AI Usage - Disabled State

**Info box:** "AI is currently disabled for this company"  
**Action link:** "Enable AI Tagging in the settings above"  
**Context:** Clear connection between settings and usage

*Cross-component integration guides user workflow*

### 🔟 Permission Denied States

**Settings:** "You don't have permission to edit AI settings..."  
**Usage:** "You don't have permission to view AI usage data..."  
**Styling:** Neutral gray info boxes (not error red)

*Permission boundaries clearly communicated*

---

## Technical Implementation

### Frontend Stack

- **React 18** with hooks for state management  
- **Headless UI** for accessible toggle switches
- **Tailwind CSS** for styling and responsive design
- **Heroicons** for consistent iconography
- **Lodash-es** for debounced API calls

### Backend Integration

- **Laravel 11** with existing service architecture
- **AiTagPolicyService** for all policy operations (no logic duplication)
- **Validation** via Laravel form requests
- **Logging** for all setting changes and errors
- **Permission system** integration with Spatie

### API Design Principles  

1. **Use existing services** - No policy logic duplication
2. **Graceful degradation** - Handle missing tenant context
3. **Comprehensive validation** - Client and server-side validation  
4. **Audit logging** - All changes logged with user context
5. **Error transparency** - Helpful error messages for debugging

---

## Error Handling Strategy

### API Error Responses

| HTTP Code | Situation | Response | UI Behavior |
|-----------|-----------|----------|-------------|
| **200** | Success | Settings data | Show success indicator |
| **400** | Bad request | Error message | Show error, allow retry |
| **403** | No permission | Permission denied | Show permission message |
| **422** | Validation failed | Field errors | Highlight invalid fields |
| **500** | Server error | Generic error + details | Revert optimistic update |

### Frontend Error Recovery

```jsx
try {
    // API call
    const response = await axios.patch('/api/companies/ai-settings', settings)
    setSettings(response.data.settings) // Use server response (authoritative)
    setLastSaved(new Date())
    setError(null)
} catch (err) {
    // Revert optimistic update
    await loadSettings()
    setError(err.response?.data?.error || 'Failed to update')
}
```

### Resilience Patterns

1. **Optimistic UI** - Immediate feedback, revert on error
2. **Graceful degradation** - Partial failures don't break entire UI  
3. **User guidance** - Clear error messages with suggested actions
4. **Automatic retry** - Some errors auto-retry with exponential backoff
5. **Developer hints** - Console logging for debugging production issues

---

## Testing Coverage

### API Tests: `CompanyAiSettingsTest`

**Test Coverage:**
- ✅ Get settings as admin (200)
- ✅ Get settings without permission (403)  
- ✅ Update settings as admin (200)
- ✅ Update settings without permission (403)
- ✅ Input validation (422)
- ✅ Partial updates work correctly
- ✅ Settings persist across requests  
- ✅ Tenant isolation enforced
- ✅ Error handling for missing context (400)

### Component Tests

**AiTaggingSettings Tests:**
- Toggle interactions work correctly
- Debounced saves prevent excessive API calls
- Optimistic UI updates and reverts on error
- Permission states render correctly
- Master toggle disables child controls

**AiUsagePanel Tests:**  
- All error states render without crashing
- Retry functionality works
- Permission denied state shows correctly
- Usage data displays with proper formatting
- Loading states provide feedback

---

## Performance Considerations

### Frontend Optimizations

1. **Debounced API calls** (500ms) - Prevents excessive requests during typing
2. **Optimistic UI updates** - Immediate feedback without waiting for server
3. **Component memoization** - Prevents unnecessary re-renders
4. **Conditional rendering** - Only load components when sections are active
5. **Error boundaries** - Isolate component failures

### Backend Optimizations  

1. **Service layer reuse** - No duplicated policy logic
2. **Cached settings** - Tenant settings cached by AiTagPolicyService
3. **Minimal queries** - Single database update per settings change
4. **Indexed lookups** - All tenant queries use proper indexes
5. **Request validation** - Early validation prevents unnecessary processing

---

## Security & Permissions

### Permission Model

**Required for AI Settings:**
- Permission: `companies.settings.edit`
- Scope: Tenant-level (company admin)
- Fallback: Read-only view with permission message

**Required for AI Usage:**  
- Permission: `ai.usage.view`
- Scope: Tenant-level  
- Fallback: Permission denied message

### Data Protection

1. **Tenant isolation** - All queries scoped to current tenant
2. **Permission validation** - Both frontend and backend checks
3. **Input sanitization** - All settings validated before storage
4. **Audit logging** - All changes logged with user context
5. **CSRF protection** - All API calls include CSRF token

---

## Migration & Deployment

### Database Changes

**No new migrations required** - Uses existing:
- `tenant_ai_tag_settings` table (from Phase J.2.2)
- Permission system (existing)
- User-tenant relationships (existing)

### Frontend Changes

**New Files:**
- `AiTaggingSettings.jsx` - Settings UI component
- `AiUsagePanel.jsx` - Usage display component  

**Modified Files:**
- `CompanySettings.jsx` - Added AI Settings section, replaced AI Usage implementation

### Backend Changes

**Modified Files:**
- `CompanyController.php` - Added `getAiSettings()` and `updateAiSettings()` methods
- `web.php` - Added 2 new API routes

**No Service Changes** - Uses existing `AiTagPolicyService`

---

## Validation Results

### ✅ Requirements Validated

**Reflects existing tenant AI settings:**
- ✅ UI loads current policy state from `AiTagPolicyService`
- ✅ All toggle states match backend truth
- ✅ Defaults preserved correctly (auto-apply OFF)

**Allows safe toggling of AI behavior:**  
- ✅ Master toggle completely disables AI tagging
- ✅ Child controls properly disabled when AI is off
- ✅ Debounced saves prevent API spam
- ✅ Optimistic UI with error rollback

**Displays AI usage & caps:**
- ✅ Current month usage with progress bars  
- ✅ Feature breakdown (tagging, suggestions)
- ✅ Status badges (available, near limit, exceeded)
- ✅ Graceful handling of zero usage

**Handles empty / disabled / error states gracefully:**
- ✅ No hard crashes on any error condition
- ✅ Permission denied states clearly communicated  
- ✅ AI disabled state shows helpful guidance
- ✅ Network errors provide retry functionality
- ✅ Invalid data structures handled gracefully

### ✅ Technical Validation

**No AI behavior changes:**
- ✅ Uses existing `AiTagPolicyService` without modification
- ✅ No new AI calls or pipeline changes
- ✅ Policy enforcement unchanged (UI is just a client)

**No policy logic duplication:**
- ✅ All policy decisions made by `AiTagPolicyService`  
- ✅ UI only manages display and user interaction
- ✅ Server responses are authoritative

**Screenshot states render cleanly:**
- ✅ All toggle combinations render correctly
- ✅ Error states provide clear guidance
- ✅ Success states give positive feedback
- ✅ Permission states explain restrictions

---

## User Workflows

### Admin Configures AI Settings

1. **Navigate to Company Settings** → AI Settings tab
2. **Configure master toggle** → Enable/disable all AI tagging
3. **Set suggestion visibility** → Control user-facing suggestions
4. **Configure auto-apply** → Enable with quantity limits (optional)
5. **See immediate feedback** → Optimistic UI updates, success confirmation

### Admin Views AI Usage  

1. **Navigate to Company Settings** → AI Usage tab
2. **View current month data** → Usage bars and percentages
3. **Check feature breakdown** → Tagging vs suggestions usage
4. **Monitor status badges** → Available, near limit, exceeded indicators

### Error Recovery Scenarios

1. **Network error during save** → Settings revert automatically, error message shown
2. **Permission denied** → Clear explanation, settings become read-only
3. **Invalid input** → Field validation errors, user guided to fix
4. **API service down** → Usage panel shows retry option, AI settings load from cache

---

## Future Enhancements

### Phase J.2.6: Enhanced Controls
- Tag category-specific auto-apply limits
- Time-based auto-apply scheduling  
- Confidence threshold controls per feature
- Bulk settings management for multi-tenant admins

### Advanced UX Improvements
- Settings export/import for tenant templates
- Usage trend charts (weekly/monthly views)
- Predictive usage alerts before hitting limits
- A/B testing toggle for AI feature experiments

### Integration Enhancements  
- Webhook notifications for setting changes
- API rate limiting visualization
- Cost prediction based on current settings
- Integration with external monitoring systems

---

## Summary

Phase J.2.5 successfully delivers a complete AI Settings UI that:

- **Provides intuitive control** over AI tagging behavior with clear visual hierarchy
- **Ensures safe defaults** with auto-apply OFF and prominent warnings
- **Handles all edge cases** gracefully without hard crashes or confusing states  
- **Maintains data integrity** through optimistic UI with automatic error recovery
- **Respects permissions** with clear messaging about access restrictions
- **Integrates seamlessly** with existing Company Settings workflow

**Key Accomplishments:**
- ✅ Master toggle immediately affects backend behavior
- ✅ Child controls properly disabled when AI is off
- ✅ AI Usage panel no longer crashes or shows confusing errors
- ✅ Screenshot-ready states render cleanly across all conditions
- ✅ No AI behavior regressions or policy logic duplication
- ✅ Zero AI costs incurred (pure UI improvements)

**Technical Excellence:**
- Debounced saves prevent API abuse
- Optimistic UI provides immediate feedback
- Comprehensive error handling with user-friendly messages  
- Permission-based visibility with helpful guidance
- Resilient usage panel handles all failure modes gracefully

**Status:** Ready for company admin testing and screenshot documentation.

**Next Phase:** Awaiting approval to proceed with enhanced controls or ready for production deployment of current AI tagging management system.