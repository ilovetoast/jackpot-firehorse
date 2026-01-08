# Flash Messages Standard

This document outlines the standard pattern for sending flash messages from the backend to the frontend using Inertia.js.

## Overview

Flash messages are displayed using the `FlashMessage` component, which automatically shows success, error, warning, and info messages based on the flash data passed from the backend.

## Backend Pattern

### Using the HandlesFlashMessages Trait

The `HandlesFlashMessages` trait provides standardized methods for sending flash messages:

```php
use App\Traits\HandlesFlashMessages;

class YourController extends Controller
{
    use HandlesFlashMessages;

    public function update(Request $request)
    {
        // ... validation and update logic ...

        // Success message (default: "Updated")
        return $this->redirectWithSuccess('route.name', 'Updated');
        
        // Or with custom message
        return $this->redirectWithSuccess('route.name', 'Settings saved successfully');
        
        // Or use back() for same page
        return $this->backWithSuccess('Updated');
    }
}
```

### Available Methods

#### Success Messages
- `redirectWithSuccess(string $route, string $message = 'Updated')` - Redirect to route with success
- `backWithSuccess(string $message = 'Updated')` - Go back with success

#### Error Messages
- `redirectWithError(string $route, string $message)` - Redirect to route with error
- `backWithError(string $message)` - Go back with error

#### Warning Messages
- `redirectWithWarning(string $route, string $message)` - Redirect to route with warning
- `backWithWarning(string $message)` - Go back with warning

#### Info Messages
- `redirectWithInfo(string $route, string $message)` - Redirect to route with info
- `backWithInfo(string $message)` - Go back with info

### Manual Pattern (Without Trait)

If you prefer not to use the trait, you can use Laravel's standard flash pattern:

```php
// Success
return redirect()->route('route.name')->with('success', 'Updated');
return back()->with('success', 'Updated');

// Error
return redirect()->route('route.name')->with('error', 'Something went wrong');
return back()->with('error', 'Something went wrong');

// Warning
return redirect()->route('route.name')->with('warning', 'Please review');
return back()->with('warning', 'Please review');

// Info
return redirect()->route('route.name')->with('info', 'Information message');
return back()->with('info', 'Information message');
```

## Frontend

The `FlashMessage` component automatically displays flash messages from the backend. It's already integrated into the app via `app.jsx`.

### Flash Message Types

The component supports four types:
- **success** - Green background, checkmark icon
- **error** - Red background, X icon
- **warning** - Yellow background, warning icon
- **info** - Blue background, info icon

### Auto-dismiss

Flash messages automatically dismiss after 5 seconds, or users can manually close them.

## Best Practices

1. **Use concise messages**: Keep messages short and clear (e.g., "Updated", "Saved", "Deleted")
2. **Default to "Updated"**: For simple update operations, use the default "Updated" message
3. **Be specific when needed**: For complex operations, provide more context (e.g., "Subscription upgraded successfully")
4. **Use appropriate types**: 
   - `success` for successful operations
   - `error` for failures or validation errors
   - `warning` for important notices that aren't errors
   - `info` for informational messages
5. **Consistent messaging**: Use similar wording across similar operations

## Examples

### Simple Update
```php
public function update(Request $request)
{
    $model->update($request->validated());
    return $this->backWithSuccess(); // Shows "Updated"
}
```

### Create with Custom Message
```php
public function store(Request $request)
{
    Model::create($request->validated());
    return $this->redirectWithSuccess('models.index', 'Created successfully');
}
```

### Delete with Custom Message
```php
public function destroy(Model $model)
{
    $model->delete();
    return $this->backWithSuccess('Deleted');
}
```

### Error Handling
```php
public function update(Request $request)
{
    try {
        $model->update($request->validated());
        return $this->backWithSuccess();
    } catch (\Exception $e) {
        return $this->backWithError('Failed to update. Please try again.');
    }
}
```

## Migration Guide

To migrate existing controllers to use the new standard:

1. Add the trait to your controller:
```php
use App\Traits\HandlesFlashMessages;

class YourController extends Controller
{
    use HandlesFlashMessages;
}
```

2. Replace existing flash messages:
```php
// Old
return redirect()->route('route.name')->with('success', 'Updated successfully.');

// New
return $this->redirectWithSuccess('route.name', 'Updated');
```

3. For simple updates, use the default:
```php
// Old
return back()->with('success', 'Settings updated successfully.');

// New
return $this->backWithSuccess(); // Shows "Updated"
```
