import { useState, useEffect, useMemo, useRef } from 'react'
import { router } from '@inertiajs/react'
import {
    Bars3Icon,
    CheckCircleIcon,
    PencilIcon,
    PlusIcon,
} from '@heroicons/react/24/outline'
import MetadataFieldModal from '../../../Components/MetadataFieldModal'
import PlanLimitIndicator from '../../../Components/PlanLimitIndicator'

/**
 * By Category View Component
 * 
 * Phase G.5: Category-First Metadata Enablement UX with improved presentation
 * 
 * ⚠️ PHASE LOCK: Phase G complete and paused. UI behavior verified and intentionally paused.
 * Do not refactor structure or behavior. Future improvements will be in Phase H.
 * 
 * 🎯 CANONICAL CONTROL SURFACE FOR ASSET GRID FILTERS
 * 
 * This is the ONLY place where filter visibility and primary/secondary placement
 * can be configured. All other tabs (All Metadata, Custom Fields, Filters) are
 * read-only or overview-only and must never reintroduce filter controls.
 * 
 * Ownership:
 * - Filter visibility (show_in_filters) → configured here per category
 * - Primary vs Secondary placement (is_primary) → configured here per category
 * - Category enablement → configured here
 * 
 * Displays metadata fields organized by category, making it clear
 * which fields are enabled for each category.
 */
export default function ByCategoryView({ 
    registry, 
    brands = [],
    selectedBrandId,
    onBrandChange,
    categories, 
    canManageVisibility,
    canManageFields = false,
    customFieldsLimit = null
}) {
    const [selectedCategoryId, setSelectedCategoryId] = useState(null)
    const selectedCategoryIdRef = useRef(null) // Persist selected category across reloads
    const [fieldCategoryData, setFieldCategoryData] = useState({}) // Cache category data per field
    const [loadingFields, setLoadingFields] = useState(new Set())
    const [draggedFieldId, setDraggedFieldId] = useState(null)
    const [fieldOrder, setFieldOrder] = useState({}) // Store order per category: { categoryId: [fieldId, ...] }
    const [successMessage, setSuccessMessage] = useState(null) // Success message state
    const [modalOpen, setModalOpen] = useState(false)
    const [editingField, setEditingField] = useState(null)
    const [loadingFieldData, setLoadingFieldData] = useState(false)

    // Sync ref with state
    useEffect(() => {
        selectedCategoryIdRef.current = selectedCategoryId
    }, [selectedCategoryId])

    // Restore selected category after reload
    useEffect(() => {
        if (selectedCategoryIdRef.current && !selectedCategoryId) {
            setSelectedCategoryId(selectedCategoryIdRef.current)
        }
    }, [selectedCategoryId])

    const { system_fields: systemFields = [], tenant_fields = [] } = registry || {}
    const allFields = [...systemFields, ...tenant_fields]

    // Separate automated fields (population_mode = automatic AND readonly = true) from manageable fields
    const { manageableFields, automatedFields } = useMemo(() => {
        const manageable = []
        const automated = []
        
        allFields.forEach(field => {
            const isAutomated = (field.population_mode === 'automatic' && field.readonly === true)
            if (isAutomated) {
                automated.push(field)
            } else {
                manageable.push(field)
            }
        })
        
        return { manageableFields: manageable, automatedFields: automated }
    }, [allFields])

    // Scope to one brand at a time so the list has no duplicate category names
    const categoriesForBrand = useMemo(() => {
        if (!selectedBrandId) return categories
        return categories.filter(c => c.brand_id === selectedBrandId)
    }, [categories, selectedBrandId])

    // Clear selected category if it no longer belongs to the selected brand
    useEffect(() => {
        if (!selectedCategoryId) return
        const stillInList = categoriesForBrand.some(c => c.id === selectedCategoryId)
        if (!stillInList) setSelectedCategoryId(null)
    }, [selectedBrandId, categoriesForBrand, selectedCategoryId])

    // Group categories by asset_type (within the selected brand)
    const groupedCategories = useMemo(() => {
        const groups = {
            asset: [],
            deliverable: []
        }

        categoriesForBrand.forEach(category => {
            const assetType = category.asset_type || 'asset'
            if (!groups[assetType]) {
                groups[assetType] = []
            }
            groups[assetType].push(category)
        })

        return groups
    }, [categoriesForBrand])

    // Load category data for a field (forceRefetch = true to skip cache after saving visibility)
    const loadFieldCategoryData = async (field, forceRefetch = false) => {
        if (!forceRefetch && fieldCategoryData[field.id]) {
            return fieldCategoryData[field.id]
        }

        if (loadingFields.has(field.id)) {
            return null // Already loading
        }

        setLoadingFields(prev => new Set(prev).add(field.id))

        try {
            const response = await fetch(`/app/api/tenant/metadata/fields/${field.id}/categories`)
            const data = await response.json()
            const suppressedIds = data.suppressed_category_ids || []
            const categoryOverrides = data.category_overrides || {} // Keyed by category_id, includes is_primary
            
            const categoryData = {
                suppressed: suppressedIds,
                visible: categories.filter(cat => !suppressedIds.includes(cat.id)).map(cat => cat.id),
                overrides: categoryOverrides, // Category-specific overrides including is_primary
            }
            
            setFieldCategoryData(prev => ({
                ...prev,
                [field.id]: categoryData,
            }))

            return categoryData
        } catch (error) {
            console.error('Failed to load category data:', error)
            return { suppressed: [], visible: categories.map(cat => cat.id) }
        } finally {
            setLoadingFields(prev => {
                const next = new Set(prev)
                next.delete(field.id)
                return next
            })
        }
    }

    // Load category data for all fields when category is selected
    useEffect(() => {
        if (selectedCategoryId) {
            [...manageableFields, ...automatedFields].forEach(field => {
                loadFieldCategoryData(field)
            })
        }
    }, [selectedCategoryId, manageableFields.length, automatedFields.length])

    // Get CSRF token helper
    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.content || ''
    }

    // Toggle field visibility for selected category
    const toggleCategoryField = async (fieldId, categoryId, isSuppressed) => {
        if (!canManageVisibility) return

        try {
            if (isSuppressed) {
                // Enable field for category (remove suppression)
                const response = await fetch(`/app/api/tenant/metadata/fields/${fieldId}/categories/${categoryId}/suppress`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                })
                
                if (response.ok) {
                    // Update cached data
                    setFieldCategoryData(prev => {
                        const current = prev[fieldId] || { suppressed: [], visible: [] }
                        const newSuppressed = current.suppressed.filter(id => id !== categoryId)
                        const visibleCategories = categories.filter(cat => !newSuppressed.includes(cat.id))
                        return {
                            ...prev,
                            [fieldId]: {
                                suppressed: newSuppressed,
                                visible: visibleCategories.map(cat => cat.id),
                            }
                        }
                    })
                    
                    // Initialize order for this category if not exists
                    if (!fieldOrder[categoryId]) {
                        setFieldOrder(prev => ({
                            ...prev,
                            [categoryId]: []
                        }))
                    }
                }
            } else {
                // Disable field for category (add suppression)
                const response = await fetch(`/app/api/tenant/metadata/fields/${fieldId}/categories/${categoryId}/suppress`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                })
                
                if (response.ok) {
                    // Update cached data
                    setFieldCategoryData(prev => {
                        const current = prev[fieldId] || { suppressed: [], visible: [] }
                        const newSuppressed = [...current.suppressed, categoryId]
                        const visibleCategories = categories.filter(cat => !newSuppressed.includes(cat.id))
                        return {
                            ...prev,
                            [fieldId]: {
                                suppressed: newSuppressed,
                                visible: visibleCategories.map(cat => cat.id),
                            }
                        }
                    })
                }
            }
        } catch (error) {
            console.error('Failed to toggle category field:', error)
        }
    }

    // Toggle visibility for Upload/Edit/Filter
    const toggleVisibility = async (fieldId, context, currentValue) => {
        if (!canManageVisibility) return

        const newValue = !currentValue
        // Quick View = show_on_edit (controls visibility in drawer and details modal)
        const visibilityKey = context === 'upload' ? 'show_on_upload' : context === 'edit' ? 'show_on_edit' : 'show_in_filters'
        const contextLabel = context === 'upload' ? 'Upload' : context === 'edit' ? 'Quick View' : 'Filter'

        // Store current selected category to restore after reload
        const currentCategoryId = selectedCategoryIdRef.current

        // C9.2: Validate category_id is present for category-scoped settings
        if (!currentCategoryId) {
            console.warn('[ByCategory] No category selected, saving at tenant level', {
                fieldId,
                context,
            })
        }

        try {
            // C9.2: Include category_id when saving category-specific visibility settings
            const requestBody = {
                [visibilityKey]: newValue,
            }
            if (currentCategoryId) {
                requestBody.category_id = currentCategoryId
            }

            console.log('[ByCategory] Saving visibility', {
                fieldId,
                context,
                visibilityKey,
                newValue,
                categoryId: currentCategoryId,
                requestBody,
            })

            const response = await fetch(`/app/api/tenant/metadata/fields/${fieldId}/visibility`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(requestBody),
            })
            
            const responseData = await response.json().catch(() => ({}))
            console.log('[ByCategory] Visibility save response', {
                ok: response.ok,
                status: response.status,
                data: responseData,
            })
            
            if (response.ok) {
                // Show success message
                setSuccessMessage(`${contextLabel} visibility ${newValue ? 'enabled' : 'disabled'}`)
                setTimeout(() => setSuccessMessage(null), 3000)

                // C9.2: Invalidate and refetch category data for this field so checkboxes show saved state
                setFieldCategoryData(prev => {
                    const next = { ...prev }
                    delete next[fieldId]
                    return next
                })
                const fieldToRefetch = manageableFields.find(f => f.id === fieldId) || automatedFields.find(f => f.id === fieldId)
                if (fieldToRefetch) {
                    loadFieldCategoryData(fieldToRefetch, true).catch(() => {})
                }

                // Silently reload registry in background without page refresh
                router.reload({
                    only: ['registry'],
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        if (currentCategoryId) {
                            setTimeout(() => setSelectedCategoryId(currentCategoryId), 0)
                        }
                    }
                })
            } else {
                // C9.2: Enhanced error handling for visibility save failures
                const errorMsg = responseData?.error || responseData?.message || `Failed to update visibility (${response.status})`
                console.error('[ByCategory] Visibility save failed', {
                    status: response.status,
                    error: errorMsg,
                    responseData,
                    fieldId,
                    context,
                    categoryId: currentCategoryId,
                })
                
                // Show user-friendly error message
                setSuccessMessage(`Error: ${errorMsg}. Please try again.`)
                setTimeout(() => setSuccessMessage(null), 5000)
                
                // Revert checkbox state on error (optimistic update rollback)
                // Note: The checkbox will revert when the page reloads, but we could add optimistic state here
            }
        } catch (error) {
            // C9.2: Handle network errors and other exceptions
            console.error('[ByCategory] Network or other error during visibility save', {
                error: error.message,
                stack: error.stack,
                fieldId,
                context,
                categoryId: currentCategoryId,
            })
            
            // Show user-friendly error message
            const errorMsg = error.message || 'Network error. Please check your connection and try again.'
            setSuccessMessage(`Error: ${errorMsg}`)
            setTimeout(() => setSuccessMessage(null), 5000)
        }
    }

    // Toggle primary filter placement for category
    // ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
    // A field may be primary in Photography but secondary in Logos.
    const togglePrimary = async (fieldId, categoryId, currentValue) => {
        if (!canManageVisibility || !categoryId) return

        const newValue = !currentValue

        // Optimistically update local state immediately
        setFieldCategoryData(prev => {
            const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
            const updatedOverrides = {
                ...fieldData.overrides,
                [categoryId]: {
                    ...fieldData.overrides[categoryId],
                    is_primary: newValue,
                }
            }
            return {
                ...prev,
                [fieldId]: {
                    ...fieldData,
                    overrides: updatedOverrides,
                }
            }
        })

        try {
            const response = await fetch(`/app/api/tenant/metadata/fields/${fieldId}/visibility`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    is_primary: newValue,
                    category_id: categoryId, // Category-scoped primary placement
                }),
            })
            
            if (response.ok) {
                // Show success message
                setSuccessMessage(`Primary filter ${newValue ? 'enabled' : 'disabled'} for this category`)
                setTimeout(() => setSuccessMessage(null), 3000)
                // No reload needed - state already updated optimistically
            } else {
                // Revert on error
                setFieldCategoryData(prev => {
                    const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                    const updatedOverrides = {
                        ...fieldData.overrides,
                        [categoryId]: {
                            ...fieldData.overrides[categoryId],
                            is_primary: currentValue,
                        }
                    }
                    return {
                        ...prev,
                        [fieldId]: {
                            ...fieldData,
                            overrides: updatedOverrides,
                        }
                    }
                })
                setSuccessMessage('Failed to update primary filter placement')
                setTimeout(() => setSuccessMessage(null), 3000)
            }
        } catch (error) {
            console.error('Failed to update primary filter placement:', error)
            // Revert on error
            setFieldCategoryData(prev => {
                const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                const updatedOverrides = {
                    ...fieldData.overrides,
                    [categoryId]: {
                        ...fieldData.overrides[categoryId],
                        is_primary: currentValue,
                    }
                }
                return {
                    ...prev,
                    [fieldId]: {
                        ...fieldData,
                        overrides: updatedOverrides,
                    }
                }
            })
            setSuccessMessage('Failed to update primary filter placement')
            setTimeout(() => setSuccessMessage(null), 3000)
        }
    }

    // Toggle AI tagging suggestions (ai_eligible)
    const toggleAiEligible = async (fieldId, currentValue) => {
        if (!canManageVisibility) return

        const newValue = !currentValue

        try {
            const response = await fetch(`/app/tenant/metadata/fields/${fieldId}/ai-eligible`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    ai_eligible: newValue,
                }),
            })
            
            if (response.ok) {
                // Show success message
                setSuccessMessage(`AI tagging suggestions ${newValue ? 'enabled' : 'disabled'}`)
                setTimeout(() => setSuccessMessage(null), 3000)
                
                // Silently reload registry in background without page refresh
                router.reload({ 
                    only: ['registry'],
                    preserveState: true,
                    preserveScroll: true,
                })
            } else {
                const errorData = await response.json().catch(() => ({}))
                setSuccessMessage(errorData.error || 'Failed to update AI tagging suggestions')
                setTimeout(() => setSuccessMessage(null), 3000)
            }
        } catch (error) {
            console.error('Failed to update AI eligible:', error)
            setSuccessMessage('Failed to update AI tagging suggestions')
            setTimeout(() => setSuccessMessage(null), 3000)
        }
    }

    // Get fields for selected category with ordering
    const getFieldsForCategory = useMemo(() => {
        if (!selectedCategoryId) {
            return { enabled: [], available: [], enabledAutomated: [], availableAutomated: [] }
        }

        const enabled = []
        const available = []
        const enabledAutomated = []
        const availableAutomated = []

        manageableFields.forEach(field => {
            const categoryData = fieldCategoryData[field.id] || { suppressed: [], visible: [] }
            const isEnabled = !categoryData.suppressed.includes(selectedCategoryId)
            
            if (isEnabled) {
                enabled.push(field)
            } else {
                available.push(field)
            }
        })

        // Separate automated fields
        automatedFields.forEach(field => {
            const categoryData = fieldCategoryData[field.id] || { suppressed: [], visible: [] }
            const isEnabled = !categoryData.suppressed.includes(selectedCategoryId)
            
            if (isEnabled) {
                enabledAutomated.push(field)
            } else {
                availableAutomated.push(field)
            }
        })

        // Apply ordering to enabled fields
        const order = fieldOrder[selectedCategoryId] || []
        if (order.length > 0) {
            // Sort enabled fields by order, then append any not in order
            const ordered = order
                .map(id => enabled.find(f => f.id === id))
                .filter(Boolean)
            const unordered = enabled.filter(f => !order.includes(f.id))
            return { enabled: [...ordered, ...unordered], available, enabledAutomated, availableAutomated }
        }

        return { enabled, available, enabledAutomated, availableAutomated }
    }, [selectedCategoryId, manageableFields, automatedFields, fieldCategoryData, fieldOrder])

    // Drag handlers
    const handleDragStart = (e, fieldId) => {
        setDraggedFieldId(fieldId)
        e.dataTransfer.effectAllowed = 'move'
        e.dataTransfer.setData('text/html', fieldId)
    }

    const handleDragOver = (e) => {
        e.preventDefault()
        e.dataTransfer.dropEffect = 'move'
    }

    const handleDrop = (e, targetFieldId) => {
        e.preventDefault()
        if (!draggedFieldId || draggedFieldId === targetFieldId || !selectedCategoryId) return

        const enabled = getFieldsForCategory.enabled
        const draggedIndex = enabled.findIndex(f => f.id === draggedFieldId)
        const targetIndex = enabled.findIndex(f => f.id === targetFieldId)

        if (draggedIndex === -1 || targetIndex === -1) return

        // Reorder array
        const newOrder = [...enabled]
        const [removed] = newOrder.splice(draggedIndex, 1)
        newOrder.splice(targetIndex, 0, removed)

        // Update order state
        setFieldOrder(prev => ({
            ...prev,
            [selectedCategoryId]: newOrder.map(f => f.id)
        }))

        setDraggedFieldId(null)
    }

    const handleDragEnd = () => {
        setDraggedFieldId(null)
    }

    const selectedCategory = categories.find(cat => cat.id === selectedCategoryId)

    const handleEditField = async (field) => {
        setLoadingFieldData(true)
        
        try {
            // Check if it's a custom field (tenant field)
            const isCustom = !systemFields.some(sf => sf.id === field.id)
            
            if (isCustom && field.id) {
                // Load field data from API for custom fields
                const response = await fetch(`/app/tenant/metadata/fields/${field.id}`)
                const data = await response.json()
                if (data.field) {
                    setEditingField(data.field)
                    setModalOpen(true)
                } else {
                    console.error('Field not found')
                }
            } else {
                // For system fields, fetch full field data including options and ai_eligible
                // Use the tenant metadata field API which includes ai_eligible
                try {
                    // Fetch field details including options and ai_eligible
                    const fieldResponse = await fetch(`/app/tenant/metadata/fields/${field.id}`)
                    if (fieldResponse.ok) {
                        const fieldData = await fieldResponse.json()
                        setEditingField({
                            ...field,
                            ...fieldData.field,
                            scope: 'system',
                            is_system: true,
                            // Ensure ai_eligible is included from API response or field prop
                            ai_eligible: fieldData.field?.ai_eligible !== undefined 
                                ? fieldData.field.ai_eligible 
                                : (field.ai_eligible !== undefined ? field.ai_eligible : false),
                        })
                    } else {
                        // Fallback to basic field data with ai_eligible from field prop
                        setEditingField({
                            ...field,
                            scope: 'system',
                            is_system: true,
                            ai_eligible: field.ai_eligible !== undefined ? field.ai_eligible : false,
                        })
                    }
                } catch (err) {
                    // Fallback to basic field data with ai_eligible from field prop
                    setEditingField({
                        ...field,
                        scope: 'system',
                        is_system: true,
                        ai_eligible: field.ai_eligible !== undefined ? field.ai_eligible : false,
                    })
                }
                setModalOpen(true)
            }
        } catch (error) {
            console.error('Failed to load field data:', error)
        } finally {
            setLoadingFieldData(false)
        }
    }

    const handleModalSuccess = () => {
        // Reload registry data
        router.reload({ 
            only: ['registry'],
            preserveState: true,
            preserveScroll: true,
        })
    }

    return (
        <div className="space-y-6">
            {/* Success Message Toast */}
            {successMessage && (
                <div className="fixed top-4 right-4 z-50 max-w-md w-full animate-in slide-in-from-top-5">
                    <div className="bg-green-50 border border-green-200 rounded-lg shadow-lg p-4">
                        <div className="flex items-start">
                            <div className="flex-shrink-0">
                                <CheckCircleIcon className="h-5 w-5 text-green-400" />
                            </div>
                            <div className="ml-3 flex-1">
                                <p className="text-sm font-medium text-green-800">{successMessage}</p>
                            </div>
                            <div className="ml-4 flex-shrink-0">
                                <button
                                    type="button"
                                    onClick={() => setSuccessMessage(null)}
                                    className="inline-flex text-green-400 hover:text-green-500"
                                >
                                    <span className="sr-only">Close</span>
                                    <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
            
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Category List (Left Sidebar) */}
            <div className="lg:col-span-1">
                <div className="bg-white rounded-lg border border-gray-200 p-4">
                    {/* Brand selector: one brand at a time so category list has no duplicates */}
                    {brands.length > 1 && (
                        <div className="mb-4">
                            <label htmlFor="by-category-brand" className="block text-xs font-medium text-gray-500 uppercase tracking-wider mb-1.5">
                                Brand
                            </label>
                            <select
                                id="by-category-brand"
                                value={selectedBrandId ?? ''}
                                onChange={(e) => onBrandChange(e.target.value ? parseInt(e.target.value, 10) : null)}
                                className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            >
                                {brands.map(b => (
                                    <option key={b.id} value={b.id}>{b.name}</option>
                                ))}
                            </select>
                        </div>
                    )}
                    {brands.length === 1 && (
                        <p className="text-xs text-gray-500 mb-3">{brands[0].name}</p>
                    )}
                    <h3 className="text-sm font-semibold text-gray-900 mb-4">Categories</h3>
                    <div className="space-y-2">
                        {/* Asset Categories */}
                        {groupedCategories.asset.length > 0 && (
                            <div>
                                <div className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">
                                    Asset Categories
                                </div>
                                {groupedCategories.asset.map(category => (
                                    <button
                                        key={category.id}
                                        onClick={() => setSelectedCategoryId(category.id)}
                                        className={`w-full text-left px-3 py-2 rounded-md text-sm transition-colors ${
                                            selectedCategoryId === category.id
                                                ? 'bg-indigo-50 text-indigo-700 font-medium'
                                                : 'text-gray-700 hover:bg-gray-50'
                                        }`}
                                    >
                                        {category.name}
                                    </button>
                                ))}
                            </div>
                        )}

                        {/* Deliverable Categories */}
                        {groupedCategories.deliverable.length > 0 && (
                            <div className="mt-4">
                                <div className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">
                                    Deliverable Categories
                                </div>
                                {groupedCategories.deliverable.map(category => (
                                    <button
                                        key={category.id}
                                        onClick={() => setSelectedCategoryId(category.id)}
                                        className={`w-full text-left px-3 py-2 rounded-md text-sm transition-colors ${
                                            selectedCategoryId === category.id
                                                ? 'bg-indigo-50 text-indigo-700 font-medium'
                                                : 'text-gray-700 hover:bg-gray-50'
                                        }`}
                                    >
                                        {category.name}
                                    </button>
                                ))}
                            </div>
                        )}

                        {categoriesForBrand.length === 0 && (
                            <p className="text-sm text-gray-500 italic">
                                {selectedBrandId ? 'No categories for this brand' : 'No categories available'}
                            </p>
                        )}
                    </div>
                </div>
            </div>

            {/* Selected Category Panel (Right Side) */}
            <div className="lg:col-span-2">
                {selectedCategory ? (
                    <div className="space-y-6">
                        {/* Header */}
                        <div className="bg-white rounded-lg border border-gray-200 p-6">
                            <h2 className="text-lg font-semibold text-gray-900">
                                Metadata for: {selectedCategory.name}
                            </h2>
                            <p className="mt-2 text-sm text-gray-600">
                                Enable which metadata fields appear for assets in this category. Configure filter visibility and primary placement to control how fields appear in the asset grid.
                            </p>
                        </div>

                        {/* Plan Limit Indicator */}
                        {customFieldsLimit && customFieldsLimit.max > 0 && (
                            <PlanLimitIndicator
                                current={customFieldsLimit.current}
                                max={customFieldsLimit.max}
                                label="Custom Metadata Fields"
                                className="mb-6"
                            />
                        )}

                        {/* Enabled Fields */}
                        <div className="bg-white rounded-lg border border-gray-200">
                            <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                                <div>
                                    <h3 className="text-sm font-semibold text-gray-900">
                                        Enabled for this category ({getFieldsForCategory.enabled.length})
                                    </h3>
                                    <p className="mt-1 text-xs text-gray-500">
                                        These fields are visible for assets in this category
                                    </p>
                                </div>
                                {canManageFields && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setEditingField(null)
                                            setModalOpen(true)
                                        }}
                                        disabled={customFieldsLimit && !customFieldsLimit.can_create}
                                        className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                        title={customFieldsLimit && !customFieldsLimit.can_create 
                                            ? `Plan limit reached (${customFieldsLimit.current}/${customFieldsLimit.max}). Upgrade to create more fields.`
                                            : 'Add custom metadata field'}
                                    >
                                        <PlusIcon className="h-4 w-4" />
                                        Add Field
                                    </button>
                                )}
                            </div>
                            <div className="divide-y divide-gray-200">
                                {getFieldsForCategory.enabled.length > 0 ? (
                                    getFieldsForCategory.enabled.map((field, index) => (
                                        <FieldRow
                                            key={field.id}
                                            field={field}
                                            categoryId={selectedCategoryId}
                                            isEnabled={true}
                                            onToggle={toggleCategoryField}
                                            onVisibilityToggle={toggleVisibility}
                                            onPrimaryToggle={togglePrimary}
                                            onAiEligibleToggle={toggleAiEligible}
                                            onEdit={canManageFields ? handleEditField : null}
                                            canManage={canManageVisibility}
                                            canManageFields={canManageFields}
                                            systemFields={systemFields}
                                            fieldCategoryData={fieldCategoryData[field.id]}
                                            isDraggable={true}
                                            onDragStart={handleDragStart}
                                            onDragOver={handleDragOver}
                                            onDrop={handleDrop}
                                            onDragEnd={handleDragEnd}
                                            isDragging={draggedFieldId === field.id}
                                        />
                                    ))
                                ) : (
                                    <div className="px-6 py-8 text-center text-sm text-gray-500">
                                        No fields enabled for this category yet.
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* System Automated Fields (Subtle Display) */}
                        {(getFieldsForCategory.enabledAutomated.length > 0 || getFieldsForCategory.availableAutomated.length > 0) && (
                            <div className="bg-gray-50 rounded-lg border border-gray-200">
                                <div className="px-6 py-3 border-b border-gray-200">
                                    <h3 className="text-xs font-medium text-gray-600">
                                        System Automated Fields
                                    </h3>
                                    <p className="mt-1 text-xs text-gray-500">
                                        These fields are automatically filled by the system. Upload is disabled, but you can control edit visibility, filter visibility, and disable them per category.
                                    </p>
                                </div>
                                <div className="divide-y divide-gray-200">
                                    {/* Enabled Automated Fields */}
                                    {getFieldsForCategory.enabledAutomated.length > 0 && (
                                        <div className="px-6 py-2 bg-white/50">
                                            <div className="text-xs font-medium text-gray-500 mb-2">Enabled ({getFieldsForCategory.enabledAutomated.length})</div>
                                            <div className="space-y-1">
                                                {getFieldsForCategory.enabledAutomated.map(field => (
                                                    <AutomatedFieldRow
                                                        key={field.id}
                                                        field={field}
                                                        categoryId={selectedCategoryId}
                                                        isEnabled={true}
                                                        onToggle={toggleCategoryField}
                                                        onVisibilityToggle={toggleVisibility}
                                                        canManage={canManageVisibility}
                                                        systemFields={systemFields}
                                                        fieldCategoryData={fieldCategoryData[field.id]}
                                                    />
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                    {/* Available Automated Fields */}
                                    {getFieldsForCategory.availableAutomated.length > 0 && (
                                        <div className="px-6 py-2 bg-white/50">
                                            <div className="text-xs font-medium text-gray-500 mb-2">Available ({getFieldsForCategory.availableAutomated.length})</div>
                                            <div className="space-y-1">
                                                {getFieldsForCategory.availableAutomated.map(field => (
                                                    <AutomatedFieldRow
                                                        key={field.id}
                                                        field={field}
                                                        categoryId={selectedCategoryId}
                                                        isEnabled={false}
                                                        onToggle={toggleCategoryField}
                                                        onVisibilityToggle={toggleVisibility}
                                                        canManage={canManageVisibility}
                                                        systemFields={systemFields}
                                                        fieldCategoryData={fieldCategoryData[field.id]}
                                                    />
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Available Fields */}
                        {getFieldsForCategory.available.length > 0 && (
                            <div className="bg-white rounded-lg border border-gray-200">
                                <div className="px-6 py-4 border-b border-gray-200">
                                    <h3 className="text-sm font-semibold text-gray-900">
                                        Available for this category ({getFieldsForCategory.available.length})
                                    </h3>
                                    <p className="mt-1 text-xs text-gray-500">
                                        Enable these fields to make them visible for this category
                                    </p>
                                </div>
                                <div className="divide-y divide-gray-200">
                                    {getFieldsForCategory.available.map(field => (
                                        <FieldRow
                                            key={field.id}
                                            field={field}
                                            categoryId={selectedCategoryId}
                                            isEnabled={false}
                                            onToggle={toggleCategoryField}
                                            onVisibilityToggle={toggleVisibility}
                                            onPrimaryToggle={togglePrimary}
                                            onAiEligibleToggle={toggleAiEligible}
                                            canManage={canManageVisibility}
                                            systemFields={systemFields}
                                            fieldCategoryData={fieldCategoryData[field.id]}
                                            isDraggable={false}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="bg-white rounded-lg border border-gray-200 p-12 text-center">
                        <p className="text-sm text-gray-500">
                            Select a category from the list to manage its metadata fields.
                        </p>
                    </div>
                )}
            </div>
            </div>

            {/* Metadata Field Modal */}
            <MetadataFieldModal
                isOpen={modalOpen}
                onClose={() => {
                    setModalOpen(false)
                    setEditingField(null)
                }}
                field={editingField}
                preselectedCategoryId={selectedCategoryId}
                categories={categoriesForBrand}
                canManageFields={canManageFields}
                customFieldsLimit={customFieldsLimit}
                onSuccess={handleModalSuccess}
            />
        </div>
    )
}

/**
 * Field Row Component
 * 
 * Phase G.5: Improved presentation with toggle, checkboxes, and drag-and-drop
 */
function FieldRow({ 
    field, 
    categoryId, 
    isEnabled, 
    onToggle, 
    onVisibilityToggle,
    onPrimaryToggle,
    onAiEligibleToggle,
    onEdit,
    canManage, 
    canManageFields = false,
    systemFields,
    fieldCategoryData,
    isDraggable = false,
    onDragStart,
    onDragOver,
    onDrop,
    onDragEnd,
    isDragging = false
}) {
    // Determine if field is system or custom
    // Custom fields have scope === 'tenant', system fields have scope === 'system' or are in systemFields array
    const isSystem = field.scope === 'system' || (systemFields && systemFields.some(sf => sf.id === field.id))
    // C9.2: Use category-level overrides when a category is selected; otherwise tenant-level effective values
    const categoryOverride = categoryId && fieldCategoryData?.overrides?.[categoryId]
    const effectiveUpload = categoryOverride?.show_on_upload !== undefined
        ? categoryOverride.show_on_upload
        : (field.effective_show_on_upload ?? field.show_on_upload ?? true)
    const effectiveEdit = categoryOverride?.show_on_edit !== undefined
        ? categoryOverride.show_on_edit
        : (field.effective_show_on_edit ?? field.show_on_edit ?? true)
    const effectiveFilter = categoryOverride?.show_in_filters !== undefined
        ? categoryOverride.show_in_filters
        : (field.effective_show_in_filters ?? field.show_in_filters ?? true)

    // Resolve effective_is_primary for this category
    // ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
    // A field may be primary in Photography but secondary in Logos.
    // Resolution order: category override > global is_primary (deprecated) > false
    const resolvePrimaryPlacement = (field, categoryId, categoryData) => {
        if (!categoryId || !categoryData) {
            return field.is_primary ?? false // Fallback to global (backward compatibility)
        }
        
        const categoryOverride = categoryData.overrides?.[categoryId]
        if (categoryOverride && categoryOverride.is_primary !== null && categoryOverride.is_primary !== undefined) {
            return categoryOverride.is_primary // Category override (highest priority)
        }
        
        return field.is_primary ?? false // Fallback to global is_primary (deprecated)
    }
    
    // Compute effective_is_primary for the selected category
    // Note: For Asset Grid, MetadataSchemaResolver already computes effective_is_primary
    // and includes it in field.is_primary. This local resolution is for UI display only.
    const effectiveIsPrimary = categoryId && fieldCategoryData
        ? resolvePrimaryPlacement(field, categoryId, fieldCategoryData)
        : field.is_primary ?? false

    // Check if this is the tags field
    const isTagsField = field.key === 'tags'
    const aiEligible = field.ai_eligible ?? false

    return (
        <div 
            className={`px-6 py-4 transition-colors ${
                isDragging ? 'opacity-50' : 'hover:bg-gray-50'
            }`}
            draggable={isDraggable && canManage}
            onDragStart={isDraggable ? (e) => onDragStart(e, field.id) : undefined}
            onDragOver={isDraggable ? onDragOver : undefined}
            onDrop={isDraggable ? (e) => onDrop(e, field.id) : undefined}
            onDragEnd={isDraggable ? onDragEnd : undefined}
        >
            <div className="flex items-start gap-4">
                {/* Drag Handle (only for enabled fields) */}
                {isDraggable && canManage && (
                    <div className="flex-shrink-0 pt-1 cursor-move text-gray-400 hover:text-gray-600">
                        <Bars3Icon className="w-5 h-5" />
                    </div>
                )}
                
                <div className="flex-1 min-w-0">
                    {/* Field Name and Badge */}
                    <div className="flex items-center gap-2 mb-2">
                        <span className="text-sm font-medium text-gray-900">
                            {field.label || field.system_label || field.key || 'Unnamed Field'}
                        </span>
                        {isSystem ? (
                            <span className="px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 rounded">
                                System
                            </span>
                        ) : (
                            <span className="px-2 py-0.5 text-xs font-medium bg-purple-100 text-purple-800 rounded">
                                Custom
                            </span>
                        )}
                        {/* AI Suggestions Indicator - show for all AI-eligible fields */}
                        {aiEligible && (
                            <span className="px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800 rounded">
                                {isTagsField ? 'AI Tagging' : 'AI Suggestions'}
                            </span>
                        )}
                    </div>

                    {/* Upload/Edit/Filter Checkboxes */}
                    {isEnabled && (
                        <div className="flex items-center gap-6 text-xs text-gray-600">
                            <label className="flex items-center gap-1.5 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={effectiveUpload}
                                    onChange={() => onVisibilityToggle(field.id, 'upload', effectiveUpload)}
                                    disabled={!canManage}
                                    className="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                />
                                <span>Upload</span>
                            </label>
                            <label className="flex items-center gap-1.5 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={effectiveEdit}
                                    onChange={() => onVisibilityToggle(field.id, 'edit', effectiveEdit)}
                                    disabled={!canManage}
                                    className="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                />
                                <span>Quick View</span>
                            </label>
                            <label className="flex items-center gap-1.5 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={effectiveFilter}
                                    onChange={() => onVisibilityToggle(field.id, 'filter', effectiveFilter)}
                                    disabled={!canManage}
                                    className="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                />
                                <span>Filter</span>
                            </label>
                            {/* Primary filter toggle - only visible when field is enabled for filtering */}
                            {effectiveFilter && (
                                <div className="flex flex-col gap-1">
                                    <label className="flex items-center gap-1.5 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={effectiveIsPrimary}
                                            onChange={() => onPrimaryToggle(field.id, categoryId, effectiveIsPrimary)}
                                            disabled={!canManage}
                                            className="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                        />
                                        <span className="text-xs text-gray-600">Primary (for this category)</span>
                                    </label>
                                    {effectiveIsPrimary && (
                                        <p className="text-xs text-gray-500 ml-5 italic">
                                            Primary filters appear inline in the asset grid; others appear under "More filters".
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Actions */}
                <div className="flex-shrink-0 flex items-center gap-2">
                    {/* Edit Button (for custom fields or if can manage system overrides) */}
                    {onEdit && (canManageFields || !isSystem) && (
                        <button
                            onClick={() => onEdit(field)}
                            className="text-gray-400 hover:text-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 rounded-md p-1"
                            title="Edit field"
                        >
                            <PencilIcon className="h-4 w-4" />
                        </button>
                    )}
                    {/* Toggle Switch */}
                    <label className="relative inline-flex items-center cursor-pointer">
                        <input
                            type="checkbox"
                            checked={isEnabled}
                            onChange={() => onToggle(field.id, categoryId, !isEnabled)}
                            disabled={!canManage}
                            className="sr-only peer"
                        />
                        <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"></div>
                    </label>
                </div>
            </div>
        </div>
    )
}

/**
 * Automated Field Row Component
 * 
 * Subtle display for system automated fields (population_mode = automatic AND readonly = true).
 * These fields are automatically filled by the system, so Upload is greyed out.
 * Filter visibility and category enablement can still be controlled.
 */
function AutomatedFieldRow({ 
    field, 
    categoryId, 
    isEnabled, 
    onToggle, 
    onVisibilityToggle,
    canManage, 
    systemFields,
    fieldCategoryData
}) {
    const isSystem = field.scope === 'system' || (systemFields && systemFields.some(sf => sf.id === field.id))
    const effectiveFilter = field.effective_show_in_filters ?? field.show_in_filters ?? true
    const effectiveEdit = field.effective_show_on_edit ?? field.show_on_edit ?? false

    return (
        <div className="flex items-center justify-between py-1.5 px-2 rounded hover:bg-gray-100/50 transition-colors">
            <div className="flex items-center gap-2 flex-1 min-w-0">
                {/* Field Name - Subtle */}
                <span className="text-xs text-gray-600 truncate">
                    {field.label || field.system_label || field.key || 'Unnamed Field'}
                </span>
                {isSystem && (
                    <span className="px-1.5 py-0.5 text-xs font-medium bg-gray-200 text-gray-600 rounded flex-shrink-0">
                        Auto
                    </span>
                )}
                
                {/* Upload/Edit/Filter Checkboxes - Only visible when enabled */}
                {isEnabled && (
                    <div className="flex items-center gap-3 ml-auto">
                        {/* Upload - Always disabled/greyed out for automated fields */}
                        <label className="flex items-center gap-1.5 cursor-not-allowed opacity-50">
                            <input
                                type="checkbox"
                                checked={false}
                                disabled={true}
                                className="h-3 w-3 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-not-allowed"
                            />
                            <span className="text-xs text-gray-400">Upload</span>
                        </label>
                        {/* Show in Quick View - Functional */}
                        <label className="flex items-center gap-1.5 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={effectiveEdit}
                                onChange={() => onVisibilityToggle(field.id, 'edit', effectiveEdit)}
                                disabled={!canManage}
                                className="h-3 w-3 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                            />
                            <span className="text-xs text-gray-500">Quick View</span>
                        </label>
                        {/* Filter - Functional */}
                        <label className="flex items-center gap-1.5 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={effectiveFilter}
                                onChange={() => onVisibilityToggle(field.id, 'filter', effectiveFilter)}
                                disabled={!canManage}
                                className="h-3 w-3 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                            />
                            <span className="text-xs text-gray-500">Filter</span>
                        </label>
                    </div>
                )}
            </div>

            {/* Toggle Switch - Compact */}
            <label className="relative inline-flex items-center cursor-pointer ml-3 flex-shrink-0">
                <input
                    type="checkbox"
                    checked={isEnabled}
                    onChange={() => onToggle(field.id, categoryId, !isEnabled)}
                    disabled={!canManage}
                    className="sr-only peer"
                />
                <div className="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"></div>
            </label>
        </div>
    )
}
