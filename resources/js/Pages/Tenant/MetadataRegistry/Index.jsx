import { useState, useMemo, useEffect } from 'react'
import { router } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import ByCategoryView from './ByCategory'
import FilterView from './FilterView'
import MetadataFieldModal from '../../../Components/MetadataFieldModal'
import {
    EyeIcon,
    EyeSlashIcon,
    XMarkIcon,
    InformationCircleIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    CheckCircleIcon,
    PlusIcon,
    PencilIcon,
} from '@heroicons/react/24/outline'

/**
 * Metadata Management Index Component
 * 
 * ⚠️ OVERVIEW-ONLY TABS (All Metadata, Custom Fields)
 * 
 * The "All Metadata" and "Custom Fields" tabs are overview-only and must never
 * reintroduce filter controls. Filter visibility and primary/secondary placement
 * are configured exclusively in the "By Category" tab.
 * 
 * What these tabs CAN do:
 * - Display system-wide field overview
 * - Toggle Upload/Edit visibility (global)
 * - Show category scope information
 * - Provide advanced drill-down details
 * 
 * What these tabs CANNOT do:
 * - Configure filter visibility (show_in_filters)
 * - Configure primary/secondary placement (is_primary)
 * - Add filter-related toggles or controls
 */
export default function TenantMetadataRegistryIndex({ registry, brands = [], active_brand_id = null, categories, canManageVisibility, canManageFields, customFieldsLimit = null }) {
    const [activeTab, setActiveTab] = useState('by-category') // Phase G.2: Category-first is PRIMARY
    const [expandedField, setExpandedField] = useState(null)
    const [categoryModal, setCategoryModal] = useState({ open: false, field: null, suppressedCategories: [] })
    const [selectedCategoryFilter, setSelectedCategoryFilter] = useState(null) // Category Lens filter
    // By Category: initial brand = active brand (session/context) when in list, else first brand
    const brandIds = useMemo(() => brands.map(b => b.id), [brands])
    const initialBrandId = active_brand_id != null && brandIds.includes(active_brand_id) ? active_brand_id : (brands[0]?.id ?? null)
    const [selectedBrandId, setSelectedBrandId] = useState(initialBrandId)
    useEffect(() => {
        if (brands.length === 0) return
        const ids = brands.map(b => b.id)
        if (selectedBrandId !== null && !ids.includes(selectedBrandId)) setSelectedBrandId(initialBrandId != null && ids.includes(initialBrandId) ? initialBrandId : brands[0].id)
    }, [brands, selectedBrandId, initialBrandId])
    const [fieldCategoryData, setFieldCategoryData] = useState({}) // Cache category data per field
    const [successMessage, setSuccessMessage] = useState(null) // Success message state
    const [modalOpen, setModalOpen] = useState(false)
    const [editingField, setEditingField] = useState(null)
    const [loadingFieldData, setLoadingFieldData] = useState(false)

    const { system_fields = [], tenant_fields = [] } = registry || {}
    const allFields = [...system_fields, ...tenant_fields]

    // Phase G.2: Filter out automated/AI fields (population_mode = automatic AND readonly = true)
    // These fields continue to function but are not shown in tenant management UI
    const manageableFields = useMemo(() => {
        return allFields.filter(field => {
            const isAutomated = (field.population_mode === 'automatic' && field.readonly === true)
            return !isAutomated
        })
    }, [allFields])

    const handleVisibilityToggle = async (fieldId, context, currentValue) => {
        if (!canManageVisibility) return

        const newValue = !currentValue
        const visibilityKey = context === 'upload' ? 'show_on_upload' : context === 'edit' ? 'show_on_edit' : 'show_in_filters'
        const contextLabel = context === 'upload' ? 'Upload' : context === 'edit' ? 'Edit' : 'Filter'

        try {
            await router.post(`/api/tenant/metadata/fields/${fieldId}/visibility`, {
                [visibilityKey]: newValue,
            }, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    // Show success message
                    setSuccessMessage(`${contextLabel} visibility ${newValue ? 'enabled' : 'disabled'}`)
                    setTimeout(() => setSuccessMessage(null), 3000)
                    
                    // Silently reload registry in background without page refresh
                    router.reload({ 
                        only: ['registry'],
                        preserveState: true,
                        preserveScroll: true,
                    })
                },
            })
        } catch (error) {
            console.error('Failed to update visibility:', error)
            setSuccessMessage(null)
        }
    }

    const handleRemoveOverride = async (fieldId) => {
        if (!canManageVisibility) return

        try {
            await router.delete(`/api/tenant/metadata/fields/${fieldId}/visibility`, {
                preserveScroll: true,
                onSuccess: () => {
                    router.reload({ only: ['registry'] })
                },
            })
        } catch (error) {
            console.error('Failed to remove override:', error)
        }
    }

    const openCategoryModal = async (field) => {
        try {
            const response = await fetch(`/api/tenant/metadata/fields/${field.id}/categories`)
            const data = await response.json()
            const suppressedIds = data.suppressed_category_ids || []
            
            // Compute visible categories (all categories minus suppressed)
            const visibleCategories = categories.filter(cat => !suppressedIds.includes(cat.id))
            
            // Cache category data for this field
            setFieldCategoryData(prev => ({
                ...prev,
                [field.id]: {
                    suppressed: suppressedIds,
                    visible: visibleCategories.map(cat => cat.id),
                }
            }))
            
            setCategoryModal({
                open: true,
                field,
                suppressedCategories: suppressedIds,
            })
        } catch (error) {
            console.error('Failed to load suppressed categories:', error)
        }
    }

    // Load category data for a field if not cached
    const loadFieldCategoryData = async (field) => {
        if (fieldCategoryData[field.id]) {
            return fieldCategoryData[field.id]
        }

        try {
            const response = await fetch(`/api/tenant/metadata/fields/${field.id}/categories`)
            const data = await response.json()
            const suppressedIds = data.suppressed_category_ids || []
            const visibleCategories = categories.filter(cat => !suppressedIds.includes(cat.id))
            
            const categoryData = {
                suppressed: suppressedIds,
                visible: visibleCategories.map(cat => cat.id),
            }
            
            setFieldCategoryData(prev => ({
                ...prev,
                [field.id]: categoryData,
            }))
            
            return categoryData
        } catch (error) {
            console.error('Failed to load category data:', error)
            return { suppressed: [], visible: categories.map(cat => cat.id) }
        }
    }

    const toggleCategorySuppression = async (fieldId, categoryId, isSuppressed) => {
        if (!canManageVisibility) return

        try {
            if (isSuppressed) {
                await router.delete(`/api/tenant/metadata/fields/${fieldId}/categories/${categoryId}/suppress`, {
                    preserveScroll: true,
                    onSuccess: () => {
                        const newSuppressed = categoryModal.suppressedCategories.filter(id => id !== categoryId)
                        setCategoryModal(prev => ({
                            ...prev,
                            suppressedCategories: newSuppressed,
                        }))
                        
                        // Update cached category data
                        const visibleCategories = categories.filter(cat => !newSuppressed.includes(cat.id))
                        setFieldCategoryData(prev => ({
                            ...prev,
                            [fieldId]: {
                                suppressed: newSuppressed,
                                visible: visibleCategories.map(cat => cat.id),
                            }
                        }))
                    },
                })
            } else {
                await router.post(`/api/tenant/metadata/fields/${fieldId}/categories/${categoryId}/suppress`, {}, {
                    preserveScroll: true,
                    onSuccess: () => {
                        const newSuppressed = [...categoryModal.suppressedCategories, categoryId]
                        setCategoryModal(prev => ({
                            ...prev,
                            suppressedCategories: newSuppressed,
                        }))
                        
                        // Update cached category data
                        const visibleCategories = categories.filter(cat => !newSuppressed.includes(cat.id))
                        setFieldCategoryData(prev => ({
                            ...prev,
                            [fieldId]: {
                                suppressed: newSuppressed,
                                visible: visibleCategories.map(cat => cat.id),
                            }
                        }))
                    },
                })
            }
        } catch (error) {
            console.error('Failed to toggle category suppression:', error)
        }
    }

    // Get category data for a field (use cache or load)
    const getFieldCategoryInfo = (field) => {
        const cached = fieldCategoryData[field.id]
        if (cached) {
            return cached
        }
        
        // If modal is open, use modal data
        if (categoryModal.open && categoryModal.field?.id === field.id) {
            const suppressedIds = categoryModal.suppressedCategories
            return {
                suppressed: suppressedIds,
                visible: categories.filter(cat => !suppressedIds.includes(cat.id)).map(cat => cat.id),
            }
        }
        
        // Default: assume all categories visible (will be loaded on demand)
        return {
            suppressed: [],
            visible: categories.map(cat => cat.id),
        }
    }

    // Render category badges for a field
    const renderCategoryBadges = (field) => {
        const categoryInfo = getFieldCategoryInfo(field)
        const visibleCategoryIds = categoryInfo.visible || []
        const suppressedIds = categoryInfo.suppressed || []
        
        // Get visible category objects
        const visibleCategories = categories.filter(cat => visibleCategoryIds.includes(cat.id))
        const suppressedCategories = categories.filter(cat => suppressedIds.includes(cat.id))
        
        // Show up to 3 visible category badges
        const visibleToShow = visibleCategories.slice(0, 3)
        const remainingCount = visibleCategories.length - 3
        
        return (
            <div className="flex flex-wrap items-center gap-2">
                {visibleToShow.length > 0 ? (
                    <>
                        {visibleToShow.map(category => (
                            <button
                                key={category.id}
                                onClick={() => openCategoryModal(field)}
                                disabled={!canManageVisibility}
                                className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                                title={`${category.brand_name} / ${category.name}`}
                            >
                                {category.name}
                            </button>
                        ))}
                        {remainingCount > 0 && (
                            <button
                                onClick={() => openCategoryModal(field)}
                                disabled={!canManageVisibility}
                                className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                                title={`${remainingCount} more categories`}
                            >
                                +{remainingCount} more
                            </button>
                        )}
                    </>
                ) : (
                    <span className="text-xs text-gray-400 italic">Not enabled for any categories</span>
                )}
                
                {suppressedCategories.length > 0 && (
                    <div className="flex items-center gap-1 text-xs text-gray-500">
                        <span className="text-gray-400">•</span>
                        <span className="text-gray-500">Hidden in:</span>
                        {suppressedCategories.slice(0, 2).map(category => (
                            <button
                                key={category.id}
                                onClick={() => openCategoryModal(field)}
                                disabled={!canManageVisibility}
                                className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-50 text-red-700 hover:bg-red-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                                title={`${category.brand_name} / ${category.name}`}
                            >
                                {category.name}
                            </button>
                        ))}
                        {suppressedCategories.length > 2 && (
                            <span className="text-gray-500">+{suppressedCategories.length - 2}</span>
                        )}
                    </div>
                )}
            </div>
        )
    }

    // Check if field is visible in selected category
    const isFieldVisibleInCategory = (field, categoryId) => {
        if (!categoryId) return true // "All Categories" shows everything
        
        const categoryInfo = getFieldCategoryInfo(field)
        const visibleCategoryIds = categoryInfo.visible || []
        return visibleCategoryIds.includes(categoryId)
    }

    const getFieldStatus = (field) => {
        const effectiveUpload = field.effective_show_on_upload ?? field.show_on_upload ?? true
        const effectiveEdit = field.effective_show_on_edit ?? field.show_on_edit ?? true
        const effectiveFilter = field.effective_show_in_filters ?? field.show_in_filters ?? true

        // Field is "active" if visible in at least one context
        const isActive = effectiveUpload || effectiveEdit || effectiveFilter
        return isActive ? 'Active' : 'Hidden'
    }

    const handleEditField = async (field) => {
        setLoadingFieldData(true)
        
        try {
            // Check if it's a custom field (tenant field)
            const isCustom = !system_fields.some(sf => sf.id === field.id)
            
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
                        setModalOpen(true)
                    } else {
                        // Fallback: use field data as-is
                        setEditingField({
                            ...field,
                            scope: 'system',
                            is_system: true,
                        })
                        setModalOpen(true)
                    }
                } catch (error) {
                    console.error('Failed to load field data:', error)
                    // Fallback: use field data as-is
                    setEditingField({
                        ...field,
                        scope: 'system',
                        is_system: true,
                    })
                    setModalOpen(true)
                }
            }
        } catch (error) {
            console.error('Failed to load field data:', error)
        } finally {
            setLoadingFieldData(false)
        }
    }

    const renderAdvancedDetails = (field) => {
        if (expandedField !== field.id) return null

        const isSystem = system_fields.some(sf => sf.id === field.id)

        return (
            <tr>
                <td colSpan="5" className="px-4 py-4 bg-gray-50">
                    <div className="space-y-3">
                        <div className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span className="font-medium text-gray-700">Type:</span>
                                <span className="ml-2 text-gray-600">{field.field_type}</span>
                            </div>
                            <div>
                                <span className="font-medium text-gray-700">Population Mode:</span>
                                <span className="ml-2 text-gray-600">{field.population_mode || 'manual'}</span>
                            </div>
                            {field.is_ai_related && (
                                <div>
                                    <span className="font-medium text-gray-700">AI-Related:</span>
                                    <span className="ml-2 text-gray-600">Yes</span>
                                </div>
                            )}
                            {field.is_system_generated && (
                                <div>
                                    <span className="font-medium text-gray-700">Auto-Generated:</span>
                                    <span className="ml-2 text-gray-600">Yes</span>
                                </div>
                            )}
                            {field.readonly && (
                                <div>
                                    <span className="font-medium text-gray-700">Read-Only:</span>
                                    <span className="ml-2 text-gray-600">Yes</span>
                                </div>
                            )}
                            {field.has_tenant_override && canManageVisibility && (
                                <div className="col-span-2">
                                    <button
                                        onClick={() => handleRemoveOverride(field.id)}
                                        className="text-xs text-red-600 hover:text-red-700"
                                    >
                                        Remove all visibility overrides
                                    </button>
                                </div>
                            )}
                        </div>
                        {isSystem && (
                            <div className="text-xs text-gray-500 italic">
                                System fields cannot be edited. Only visibility can be controlled.
                            </div>
                        )}
                    </div>
                </td>
            </tr>
        )
    }

    // Apply tab filter (using manageableFields to exclude automated fields)
    let filteredFields = activeTab === 'custom' 
        ? tenant_fields.filter(f => {
            const isAutomated = (f.population_mode === 'automatic' && f.readonly === true)
            return !isAutomated
        })
        : manageableFields
    
    // Apply category lens filter (view-only, no state changes)
    if (selectedCategoryFilter) {
        filteredFields = filteredFields.filter(field => isFieldVisibleInCategory(field, selectedCategoryFilter))
    }

    return (
        <div className="min-h-screen bg-gray-50">
            <AppNav />
            
            {/* Success Message Toast */}
            {successMessage && (
                <div className="fixed top-4 right-4 z-50 max-w-md w-full">
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
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-200">
                        <h1 className="text-2xl font-bold text-gray-900">Metadata Management</h1>
                        <p className="mt-1 text-sm text-gray-600">
                            Control where metadata fields appear in your workflow
                        </p>
                    </div>

                    {/* Tabs */}
                    <div className="border-b border-gray-200">
                        <nav className="-mb-px flex space-x-8 px-6" aria-label="Tabs">
                            {/* Phase G.2: Category-first is PRIMARY */}
                            <button
                                onClick={() => setActiveTab('by-category')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium ${
                                    activeTab === 'by-category'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                By Category
                            </button>
                            {/* Phase G.2: All Metadata is now SECONDARY / advanced view */}
                            <button
                                onClick={() => setActiveTab('all')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium ${
                                    activeTab === 'all'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                All Metadata ({manageableFields.length})
                            </button>
                            <button
                                onClick={() => setActiveTab('filters')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium ${
                                    activeTab === 'filters'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                Filters
                            </button>
                            <button
                                onClick={() => setActiveTab('custom')}
                                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium ${
                                    activeTab === 'custom'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                Custom Fields ({tenant_fields.filter(f => {
                                    const isAutomated = (f.population_mode === 'automatic' && f.readonly === true)
                                    return !isAutomated
                                }).length})
                            </button>
                        </nav>
                    </div>

                    {/* Phase G.2: Category-First View */}
                    {activeTab === 'by-category' ? (
                        <div className="px-6 py-4">
                            <ByCategoryView
                                registry={registry}
                                brands={brands}
                                selectedBrandId={selectedBrandId}
                                onBrandChange={setSelectedBrandId}
                                categories={categories}
                                canManageVisibility={canManageVisibility}
                                canManageFields={canManageFields}
                                customFieldsLimit={customFieldsLimit}
                            />
                        </div>
                    ) : activeTab === 'filters' ? (
                        <FilterView
                            onSwitchToByCategory={() => setActiveTab('by-category')}
                        />
                    ) : (
                        <>
                            {/* Header with New Field Button for Custom Fields tab */}
                            {activeTab === 'custom' && canManageFields && (
                                <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between bg-gray-50">
                                    <div>
                                        <h2 className="text-lg font-semibold text-gray-900">Custom Fields</h2>
                                        <p className="mt-1 text-sm text-gray-600">
                                            Manage your custom metadata fields
                                        </p>
                                    </div>
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
                                            : 'Create new custom metadata field'}
                                    >
                                        <PlusIcon className="h-4 w-4" />
                                        New Field
                                    </button>
                                </div>
                            )}

                            {/* Category Lens Filter (for All Metadata view) */}
                            {activeTab !== 'custom' && (
                            <div className="px-6 py-4 border-b border-gray-200 bg-gray-50">
                                <div className="flex items-center gap-3">
                                    <label className="text-sm font-medium text-gray-700">
                                        Viewing metadata as used in:
                                    </label>
                                    <select
                                        value={selectedCategoryFilter || ''}
                                        onChange={(e) => setSelectedCategoryFilter(e.target.value ? parseInt(e.target.value) : null)}
                                        className="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    >
                                        <option value="">All Categories</option>
                                        {categories.map(category => (
                                            <option key={category.id} value={category.id}>
                                                {category.brand_name} / {category.name}
                                            </option>
                                        ))}
                                    </select>
                                    {selectedCategoryFilter && (
                                        <button
                                            onClick={() => setSelectedCategoryFilter(null)}
                                            className="text-xs text-gray-500 hover:text-gray-700"
                                        >
                                            Clear filter
                                        </button>
                                    )}
                                </div>
                            </div>
                            )}

                            {/* Info Banner */}
                            <div className="px-6 py-3 bg-blue-50 border-b border-blue-200">
                                <div className="flex items-start gap-2">
                                    <InformationCircleIcon className="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" />
                                    <div className="text-sm text-blue-800">
                                        <p className="mb-1">
                                            Hiding a field does not delete existing data. Toggle fields on/off to control where they appear in upload and edit interfaces.
                                        </p>
                                        <p className="font-medium">
                                            Filter visibility and primary placement are configured per category in the <button onClick={() => setActiveTab('by-category')} className="underline hover:text-blue-900">By Category</button> tab.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {/* Table */}
                            <div className="px-6 py-4">
                                {filteredFields.length === 0 ? (
                                    <div className="text-center py-12">
                                        <p className="text-sm text-gray-500">
                                            {selectedCategoryFilter
                                                ? 'No metadata fields visible in the selected category.'
                                                : activeTab === 'custom' 
                                                    ? 'No custom fields created yet.' 
                                                    : 'No metadata fields found.'}
                                        </p>
                                    </div>
                                ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Field
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Appears On
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Category Scope
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Status
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {filteredFields.map(field => {
                                            const effectiveUpload = field.effective_show_on_upload ?? field.show_on_upload ?? true
                                            const effectiveEdit = field.effective_show_on_edit ?? field.show_on_edit ?? true
                                            const effectiveFilter = field.effective_show_in_filters ?? field.show_in_filters ?? true
                                            const isSystem = system_fields.some(sf => sf.id === field.id)
                                            const status = getFieldStatus(field)

                                            return (
                                                <>
                                                    <tr key={field.id} className="hover:bg-gray-50">
                                                        <td className="px-4 py-3 align-top">
                                                            <div className="flex items-start gap-2">
                                                                <span className="text-sm font-medium text-gray-900">
                                                                    {field.label || field.system_label || field.key || 'Unnamed Field'}
                                                                </span>
                                                                {isSystem && (
                                                                    <span className="px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 rounded">
                                                                        System
                                                                    </span>
                                                                )}
                                                                {!isSystem && (
                                                                    <span className="px-2 py-0.5 text-xs font-medium bg-purple-100 text-purple-800 rounded">
                                                                        Custom
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3 align-top">
                                                            <div className="flex items-center gap-4">
                                                                {/* Upload Toggle */}
                                                                <label className="flex items-center gap-2 cursor-pointer">
                                                                    <input
                                                                        type="checkbox"
                                                                        checked={effectiveUpload}
                                                                        onChange={() => handleVisibilityToggle(field.id, 'upload', effectiveUpload)}
                                                                        disabled={!canManageVisibility}
                                                                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                    />
                                                                    <span className="text-sm text-gray-700">Upload</span>
                                                                </label>

                                                                {/* Edit Toggle */}
                                                                <label className="flex items-center gap-2 cursor-pointer">
                                                                    <input
                                                                        type="checkbox"
                                                                        checked={effectiveEdit}
                                                                        onChange={() => handleVisibilityToggle(field.id, 'edit', effectiveEdit)}
                                                                        disabled={!canManageVisibility}
                                                                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                    />
                                                                    <span className="text-sm text-gray-700">Edit</span>
                                                                </label>

                                                                {/* Filter - Read-only */}
                                                                <div className="flex items-center gap-2">
                                                                    <span className="text-sm text-gray-500 italic">
                                                                        Filter → configured in <button onClick={() => setActiveTab('by-category')} className="text-indigo-600 hover:text-indigo-700 underline">By Category</button>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3 align-top">
                                                            <div 
                                                                onMouseEnter={() => loadFieldCategoryData(field)}
                                                                className="min-w-[200px]"
                                                            >
                                                                {renderCategoryBadges(field)}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3 align-top">
                                                            <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                                status === 'Active' 
                                                                    ? 'bg-green-100 text-green-800' 
                                                                    : 'bg-gray-100 text-gray-800'
                                                            }`}>
                                                                {status}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3 align-top">
                                                            <div className="flex items-center gap-3">
                                                                {/* Edit Button */}
                                                                {(canManageFields || !isSystem) && (
                                                                    <button
                                                                        onClick={() => handleEditField(field)}
                                                                        disabled={loadingFieldData}
                                                                        className="text-gray-400 hover:text-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 rounded-md p-1 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                        title="Edit field"
                                                                    >
                                                                        <PencilIcon className="h-4 w-4" />
                                                                    </button>
                                                                )}
                                                                {/* Advanced Details Toggle */}
                                                                <button
                                                                    onClick={() => setExpandedField(expandedField === field.id ? null : field.id)}
                                                                    className="text-sm text-gray-600 hover:text-gray-900"
                                                                >
                                                                    {expandedField === field.id ? (
                                                                        <span className="flex items-center gap-1">
                                                                            <ChevronDownIcon className="w-4 h-4" />
                                                                            Hide Details
                                                                        </span>
                                                                    ) : (
                                                                        <span className="flex items-center gap-1">
                                                                            <ChevronRightIcon className="w-4 h-4" />
                                                                            Advanced
                                                                        </span>
                                                                    )}
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    {renderAdvancedDetails(field)}
                                                </>
                                            )
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                            </div>
                        </>
                    )}
                </div>
            </div>

            {/* Category Suppression Modal */}
            {categoryModal.open && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div className="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4">
                        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Category Visibility: {categoryModal.field?.label}
                            </h3>
                            <button
                                onClick={() => setCategoryModal({ open: false, field: null, suppressedCategories: [] })}
                                className="text-gray-400 hover:text-gray-500"
                            >
                                <XMarkIcon className="w-6 h-6" />
                            </button>
                        </div>
                        <div className="px-6 py-4 max-h-96 overflow-y-auto">
                            <p className="text-sm text-gray-600 mb-4">
                                Select categories where this field should be hidden:
                            </p>
                            <div className="space-y-2">
                                {categories.map(category => {
                                    const isSuppressed = categoryModal.suppressedCategories.includes(category.id)
                                    return (
                                        <label
                                            key={category.id}
                                            className="flex items-center gap-2 p-2 hover:bg-gray-50 rounded cursor-pointer"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={isSuppressed}
                                                onChange={() => toggleCategorySuppression(
                                                    categoryModal.field.id,
                                                    category.id,
                                                    isSuppressed
                                                )}
                                                className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                            <span className="text-sm text-gray-700">
                                                {category.brand_name} / {category.name}
                                            </span>
                                        </label>
                                    )
                                })}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Metadata Field Modal */}
            <MetadataFieldModal
                isOpen={modalOpen}
                onClose={() => {
                    setModalOpen(false)
                    setEditingField(null)
                }}
                field={editingField}
                categories={categories}
                canManageFields={canManageFields}
                customFieldsLimit={customFieldsLimit}
                onSuccess={() => {
                    router.reload({ 
                        only: ['registry'],
                        preserveState: true,
                        preserveScroll: true,
                    })
                }}
            />

            <AppFooter />
        </div>
    )
}
