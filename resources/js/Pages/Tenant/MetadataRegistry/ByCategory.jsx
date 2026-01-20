import { useState, useEffect, useMemo } from 'react'
import { router } from '@inertiajs/react'
import {
    Bars3Icon,
} from '@heroicons/react/24/outline'

/**
 * By Category View Component
 * 
 * Phase G.5: Category-First Metadata Enablement UX with improved presentation
 * 
 * ⚠️ PHASE LOCK: Phase G complete and paused. UI behavior verified and intentionally paused.
 * Do not refactor structure or behavior. Future improvements will be in Phase H.
 * 
 * Displays metadata fields organized by category, making it clear
 * which fields are enabled for each category.
 */
export default function ByCategoryView({ 
    registry, 
    categories, 
    canManageVisibility 
}) {
    const [selectedCategoryId, setSelectedCategoryId] = useState(null)
    const [fieldCategoryData, setFieldCategoryData] = useState({}) // Cache category data per field
    const [loadingFields, setLoadingFields] = useState(new Set())
    const [draggedFieldId, setDraggedFieldId] = useState(null)
    const [fieldOrder, setFieldOrder] = useState({}) // Store order per category: { categoryId: [fieldId, ...] }

    const { system_fields: systemFields = [], tenant_fields = [] } = registry || {}
    const allFields = [...systemFields, ...tenant_fields]

    // Filter out automated/AI fields (population_mode = automatic AND readonly = true)
    const manageableFields = useMemo(() => {
        return allFields.filter(field => {
            const isAutomated = (field.population_mode === 'automatic' && field.readonly === true)
            return !isAutomated
        })
    }, [allFields])

    // Group categories by asset_type
    const groupedCategories = useMemo(() => {
        const groups = {
            asset: [],
            marketing: []
        }

        categories.forEach(category => {
            const assetType = category.asset_type || 'asset'
            if (!groups[assetType]) {
                groups[assetType] = []
            }
            groups[assetType].push(category)
        })

        return groups
    }, [categories])

    // Load category data for a field
    const loadFieldCategoryData = async (field) => {
        if (fieldCategoryData[field.id]) {
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
            
            const categoryData = {
                suppressed: suppressedIds,
                visible: categories.filter(cat => !suppressedIds.includes(cat.id)).map(cat => cat.id),
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
            manageableFields.forEach(field => {
                loadFieldCategoryData(field)
            })
        }
    }, [selectedCategoryId, manageableFields.length])

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
        const visibilityKey = context === 'upload' ? 'show_on_upload' : context === 'edit' ? 'show_on_edit' : 'show_in_filters'

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
                    [visibilityKey]: newValue,
                }),
            })
            
            if (response.ok) {
                // Reload registry to get updated visibility values
                router.reload({ only: ['registry'] })
            }
        } catch (error) {
            console.error('Failed to update visibility:', error)
        }
    }

    // Get fields for selected category with ordering
    const getFieldsForCategory = useMemo(() => {
        if (!selectedCategoryId) {
            return { enabled: [], available: [] }
        }

        const enabled = []
        const available = []

        manageableFields.forEach(field => {
            const categoryData = fieldCategoryData[field.id] || { suppressed: [], visible: [] }
            const isEnabled = !categoryData.suppressed.includes(selectedCategoryId)
            
            if (isEnabled) {
                enabled.push(field)
            } else {
                available.push(field)
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
            return { enabled: [...ordered, ...unordered], available }
        }

        return { enabled, available }
    }, [selectedCategoryId, manageableFields, fieldCategoryData, fieldOrder])

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

    return (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Category List (Left Sidebar) */}
            <div className="lg:col-span-1">
                <div className="bg-white rounded-lg border border-gray-200 p-4">
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
                                        {category.brand_name && (
                                            <div className="text-xs text-gray-500 mt-0.5">
                                                {category.brand_name}
                                            </div>
                                        )}
                                    </button>
                                ))}
                            </div>
                        )}

                        {/* Marketing Categories */}
                        {groupedCategories.marketing.length > 0 && (
                            <div className="mt-4">
                                <div className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">
                                    Marketing Asset Categories
                                </div>
                                {groupedCategories.marketing.map(category => (
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
                                        {category.brand_name && (
                                            <div className="text-xs text-gray-500 mt-0.5">
                                                {category.brand_name}
                                            </div>
                                        )}
                                    </button>
                                ))}
                            </div>
                        )}

                        {categories.length === 0 && (
                            <p className="text-sm text-gray-500 italic">No categories available</p>
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
                                Enable which metadata fields appear for assets in this category.
                            </p>
                        </div>

                        {/* Enabled Fields */}
                        <div className="bg-white rounded-lg border border-gray-200">
                            <div className="px-6 py-4 border-b border-gray-200">
                                <h3 className="text-sm font-semibold text-gray-900">
                                    Enabled for this category ({getFieldsForCategory.enabled.length})
                                </h3>
                                <p className="mt-1 text-xs text-gray-500">
                                    These fields are visible for assets in this category
                                </p>
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
                                            canManage={canManageVisibility}
                                            systemFields={systemFields}
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

                        {/* Available Fields */}
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
                                {getFieldsForCategory.available.length > 0 ? (
                                    getFieldsForCategory.available.map(field => (
                                        <FieldRow
                                            key={field.id}
                                            field={field}
                                            categoryId={selectedCategoryId}
                                            isEnabled={false}
                                            onToggle={toggleCategoryField}
                                            onVisibilityToggle={toggleVisibility}
                                            canManage={canManageVisibility}
                                            systemFields={systemFields}
                                            isDraggable={false}
                                        />
                                    ))
                                ) : (
                                    <div className="px-6 py-8 text-center text-sm text-gray-500">
                                        All available fields are enabled for this category.
                                    </div>
                                )}
                            </div>
                        </div>
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
    canManage, 
    systemFields,
    isDraggable = false,
    onDragStart,
    onDragOver,
    onDrop,
    onDragEnd,
    isDragging = false
}) {
    const isSystem = !field.scope || field.scope === 'system' || (systemFields && systemFields.some(sf => sf.id === field.id))
    const effectiveUpload = field.effective_show_on_upload ?? field.show_on_upload ?? true
    const effectiveEdit = field.effective_show_on_edit ?? field.show_on_edit ?? true
    const effectiveFilter = field.effective_show_in_filters ?? field.show_in_filters ?? true

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
                        <span className="text-sm font-medium text-gray-900">{field.label}</span>
                        {isSystem ? (
                            <span className="px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 rounded">
                                System
                            </span>
                        ) : (
                            <span className="px-2 py-0.5 text-xs font-medium bg-purple-100 text-purple-800 rounded">
                                Custom
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
                                <span>Edit</span>
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
                        </div>
                    )}
                </div>

                {/* Toggle Switch */}
                <div className="flex-shrink-0">
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
