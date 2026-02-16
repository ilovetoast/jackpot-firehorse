import { useState, useEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import { router } from '@inertiajs/react'
import { ArrowPathIcon, XMarkIcon, ChevronDownIcon, CheckIcon } from '@heroicons/react/24/outline'
import OptionIconSelector, { OptionIcon } from './OptionIconSelector'
import {
    toSnakeCase,
    validateSnakeCase,
    isDuplicateValue,
    normalizeOptions,
    prepareOptionsForSubmit,
} from '../utils/optionEditorUtils'

/**
 * Metadata Field Modal Component
 * 
 * Modal for creating and editing custom metadata fields.
 * Supports both custom fields and system field overrides (based on permissions).
 */
export default function MetadataFieldModal({
    isOpen,
    onClose,
    field = null, // If provided, we're editing; otherwise creating
    preselectedCategoryId = null,
    categories = [],
    canManageFields = false,
    customFieldsLimit = null,
    onSuccess,
}) {
    const [formData, setFormData] = useState({
        key: '',
        system_label: '',
        type: 'text',
        selectedCategories: [], // Array of category IDs
        options: [],
        ai_eligible: false,
        is_filterable: true,
        show_on_upload: true,
        show_on_edit: true,
        show_in_filters: true,
        is_primary: false,
        is_required: false,
        group_key: '',
    })
    const [newOption, setNewOption] = useState({ value: '', system_label: '', color: '', icon: '' })
    const [optionError, setOptionError] = useState(null)
    const [errors, setErrors] = useState({})
    const [submitting, setSubmitting] = useState(false)
    const [loadingField, setLoadingField] = useState(false)
    const [isCategoryDropdownOpen, setIsCategoryDropdownOpen] = useState(false)
    const categoryDropdownRef = useRef(null)
    const [originalEnabledCategories, setOriginalEnabledCategories] = useState([]) // Track original enabled categories when editing

    const isEditing = !!field
    const isSystemField = field?.scope === 'system' || field?.is_system

    // Group categories by asset_type
    const groupedCategories = categories.reduce((acc, category) => {
        const assetType = category.asset_type || 'asset'
        if (!acc[assetType]) {
            acc[assetType] = []
        }
        acc[assetType].push(category)
        return acc
    }, { asset: [], deliverable: [] })

    // Close category dropdown when clicking outside
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (categoryDropdownRef.current && !categoryDropdownRef.current.contains(event.target)) {
                setIsCategoryDropdownOpen(false)
            }
        }

        if (isCategoryDropdownOpen) {
            document.addEventListener('mousedown', handleClickOutside)
            return () => {
                document.removeEventListener('mousedown', handleClickOutside)
            }
        }
    }, [isCategoryDropdownOpen])

    // Toggle category selection
    const toggleCategorySelection = (categoryId) => {
        setFormData(prev => {
            const currentCategories = prev.selectedCategories || []
            if (currentCategories.includes(categoryId)) {
                return {
                    ...prev,
                    selectedCategories: currentCategories.filter(id => id !== categoryId)
                }
            } else {
                return {
                    ...prev,
                    selectedCategories: [...currentCategories, categoryId]
                }
            }
        })
    }

    // Get selected category names for display
    const selectedCategoryNames = categories
        .filter(cat => formData.selectedCategories.includes(cat.id))
        .map(cat => cat.name)
        .join(', ') || 'Select categories...'

    // Load field data when editing and initialize category selection
    useEffect(() => {
        if (isOpen && field) {
            setLoadingField(true)
            const isSystem = field.scope === 'system' || field.is_system
            
            // If it's a custom field, fetch full details from API
            if (!isSystem && field.id) {
                // Fetch both field details and category data
                Promise.all([
                    fetch(`/app/tenant/metadata/fields/${field.id}`).then(res => res.json()),
                    fetch(`/app/api/tenant/metadata/fields/${field.id}/categories`).then(res => res.json())
                ])
                    .then(([fieldData, categoryData]) => {
                        if (fieldData.field) {
                            // Calculate enabled categories: all categories minus suppressed ones
                            const suppressedIds = categoryData.suppressed_category_ids || []
                            const allCategoryIds = categories.map(cat => cat.id)
                            const enabledCategoryIds = allCategoryIds.filter(id => !suppressedIds.includes(id))
                            
                            // Store original enabled categories for comparison when saving
                            setOriginalEnabledCategories(enabledCategoryIds)
                            
                            const categoryOverrides = categoryData.category_overrides || {}
                            const isPrimary = enabledCategoryIds.some(catId => categoryOverrides[catId]?.is_primary === true)
                            const isRequired = enabledCategoryIds.some(catId => categoryOverrides[catId]?.is_required === true)
                            setFormData({
                                key: fieldData.field.key || '',
                                system_label: fieldData.field.system_label || fieldData.field.label || '',
                                type: fieldData.field.type || 'text',
                                selectedCategories: enabledCategoryIds, // Use enabled categories, not category_ids
                                options: normalizeOptions(fieldData.field.options || []),
                                ai_eligible: fieldData.field.ai_eligible || false,
                                is_filterable: fieldData.field.is_filterable !== false,
                                show_on_upload: fieldData.field.show_on_upload !== false,
                                show_on_edit: fieldData.field.show_on_edit !== false,
                                show_in_filters: fieldData.field.show_in_filters !== false,
                                is_primary: isPrimary,
                                is_required: isRequired,
                                group_key: fieldData.field.group_key || '',
                            })
                        }
                        setLoadingField(false)
                    })
                    .catch(err => {
                        console.error('Failed to load field:', err)
                        setLoadingField(false)
                    })
            } else {
                // For system fields, fetch category data and field options
                if (field.id) {
                    // Fetch both category data and field details (including options and ai_eligible)
                    Promise.all([
                        fetch(`/app/api/tenant/metadata/fields/${field.id}/categories`).then(res => res.json()),
                        fetch(`/app/tenant/metadata/fields/${field.id}`).then(res => res.ok ? res.json() : { field: null }).catch(() => ({ field: null }))
                    ])
                        .then(([categoryData, fieldData]) => {
                            // Calculate enabled categories: all categories minus suppressed ones
                            const suppressedIds = categoryData.suppressed_category_ids || []
                            const allCategoryIds = categories.map(cat => cat.id)
                            const enabledCategoryIds = allCategoryIds.filter(id => !suppressedIds.includes(id))
                            
                            // Store original enabled categories for comparison when saving
                            setOriginalEnabledCategories(enabledCategoryIds)
                            
                            // Get options and ai_eligible from fieldData if available, otherwise from field prop
                            const fullFieldData = fieldData?.field || field
                            const fieldOptions = fullFieldData.options || fullFieldData.allowed_values || field.options || field.allowed_values || []
                            
                            // Prioritize ai_eligible from API response, then field prop, then default to false
                            const aiEligibleValue = fullFieldData.ai_eligible !== undefined 
                                ? fullFieldData.ai_eligible 
                                : (field.ai_eligible !== undefined ? field.ai_eligible : false)
                            
                            const categoryOverrides = categoryData.category_overrides || {}
                            const isPrimary = enabledCategoryIds.some(catId => categoryOverrides[catId]?.is_primary === true)
                            const isRequired = enabledCategoryIds.some(catId => categoryOverrides[catId]?.is_required === true)
                            setFormData({
                                key: field.key || '',
                                system_label: field.label || field.system_label || '',
                                type: field.field_type || field.type || 'text',
                                selectedCategories: enabledCategoryIds,
                                options: normalizeOptions(fieldOptions),
                                ai_eligible: aiEligibleValue,
                                is_filterable: field.is_filterable !== false,
                                show_on_upload: field.show_on_upload !== false,
                                show_on_edit: field.show_on_edit !== false,
                                show_in_filters: field.show_in_filters !== false,
                                is_primary: isPrimary,
                                is_required: isRequired,
                                group_key: field.group_key || '',
                            })
                            setLoadingField(false)
                        })
                        .catch(err => {
                            console.error('Failed to load field data:', err)
                            // Fallback to basic field data
                            setFormData({
                                key: field.key || '',
                                system_label: field.label || field.system_label || '',
                                type: field.field_type || field.type || 'text',
                                selectedCategories: [],
                                options: normalizeOptions(field.options || field.allowed_values || []),
                                ai_eligible: field.ai_eligible !== undefined ? field.ai_eligible : false,
                                is_filterable: field.is_filterable !== false,
                                show_on_upload: field.show_on_upload !== false,
                                show_on_edit: field.show_on_edit !== false,
                                show_in_filters: field.show_in_filters !== false,
                                is_primary: false,
                                is_required: false,
                                group_key: field.group_key || '',
                            })
                            setLoadingField(false)
                        })
                } else {
                    // No field ID, use empty categories
                    setFormData({
                        key: field.key || '',
                        system_label: field.label || field.system_label || '',
                        type: field.field_type || field.type || 'text',
                        selectedCategories: [],
                        options: normalizeOptions(field.options || field.allowed_values || []),
                        ai_eligible: field.ai_eligible !== undefined ? field.ai_eligible : false,
                        is_filterable: field.is_filterable !== false,
                        show_on_upload: field.show_on_upload !== false,
                        show_on_edit: field.show_on_edit !== false,
                        show_in_filters: field.show_in_filters !== false,
                        is_primary: false,
                        is_required: false,
                        group_key: field.group_key || '',
                    })
                    setLoadingField(false)
                }
            }
        } else if (isOpen && !field) {
            // Reset form for new field
            // Auto-select preselected category if provided
            const initialCategories = preselectedCategoryId ? [preselectedCategoryId] : []
            setOriginalEnabledCategories([]) // Reset for new field
            setFormData({
                key: '',
                system_label: '',
                type: 'text',
                selectedCategories: initialCategories,
                options: [],
                ai_eligible: false,
                is_filterable: true,
                show_on_upload: true,
                show_on_edit: true,
                show_in_filters: true,
                is_primary: false,
                is_required: false,
                group_key: '',
            })
            setErrors({})
            setLoadingField(false)
        }
    }, [isOpen, field, categories])

    if (!isOpen) return null

    const handleSubmit = async (e) => {
        e.preventDefault()
        setSubmitting(true)
        setErrors({})

        // Check plan limit before submitting (for new fields only)
        if (!isEditing && customFieldsLimit) {
            if (!customFieldsLimit.can_create) {
                setErrors({ 
                    error: `Plan limit reached. You have ${customFieldsLimit.current} of ${customFieldsLimit.max} custom metadata fields. Please upgrade your plan to create more fields.` 
                })
                setSubmitting(false)
                return
            }
        }

        // Validate category selection
        if (!formData.selectedCategories || formData.selectedCategories.length === 0) {
            setErrors({ 
                selectedCategories: 'At least one category must be selected.',
                error: 'Please select at least one category where this field should be enabled.'
            })
            setSubmitting(false)
            return
        }

        // Validate options for select/multiselect
        if (requiresOptions) {
            const prepared = prepareOptionsForSubmit(formData.options)
            if (prepared.length === 0) {
                setErrors({ options: 'At least one option is required.', error: 'Please add at least one option.' })
                setSubmitting(false)
                return
            }
            const values = prepared.map((o) => o.value.toLowerCase())
            const dupes = values.filter((v, i) => values.indexOf(v) !== i)
            if (dupes.length > 0) {
                setErrors({ options: 'Duplicate values are not allowed.', error: 'Remove duplicate option values.' })
                setSubmitting(false)
                return
            }
        }

        // Ensure key starts with custom__ prefix for new fields
        const fieldKey = isEditing 
            ? formData.key 
            : (formData.key.startsWith('custom__') ? formData.key : `custom__${formData.key}`)

        const submitData = {
            ...formData,
            key: fieldKey,
            options: prepareOptionsForSubmit(formData.options),
        }

        let skipFinally = false
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
            
            // For system fields, only allow updating ai_eligible and category visibility
            // Other fields cannot be edited directly
            if (isSystemField && isEditing) {
                // For system fields, update ai_eligible separately if it changed
                const originalAiEligible = field.ai_eligible || false
                if (formData.ai_eligible !== originalAiEligible) {
                    const aiEligibleResponse = await fetch(`/app/tenant/metadata/fields/${field.id}/ai-eligible`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken || '',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ ai_eligible: formData.ai_eligible }),
                    })
                    
                    if (!aiEligibleResponse.ok) {
                        const aiData = await aiEligibleResponse.json()
                        setErrors({ error: aiData.error || 'Failed to update AI eligibility' })
                        setSubmitting(false)
                        return
                    }
                }
                
                // Update category suppressions for system fields
                if (field.id) {
                    const allCategoryIds = categories.map(cat => cat.id)
                    const newEnabledCategories = formData.selectedCategories || []
                    
                    // Find categories that were enabled but are now unchecked (need to suppress)
                    const categoriesToSuppress = originalEnabledCategories.filter(
                        catId => !newEnabledCategories.includes(catId)
                    )
                    
                    // Find categories that were suppressed but are now checked (need to unsuppress)
                    const categoriesToUnsuppress = newEnabledCategories.filter(
                        catId => !originalEnabledCategories.includes(catId)
                    )
                    
                    // Update suppressions in parallel
                    const suppressionPromises = [
                        ...categoriesToSuppress.map(categoryId =>
                            fetch(`/app/api/tenant/metadata/fields/${field.id}/categories/${categoryId}/suppress`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': csrfToken || '',
                                },
                                credentials: 'same-origin',
                            })
                        ),
                        ...categoriesToUnsuppress.map(categoryId =>
                            fetch(`/app/api/tenant/metadata/fields/${field.id}/categories/${categoryId}/suppress`, {
                                method: 'DELETE',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': csrfToken || '',
                                },
                                credentials: 'same-origin',
                            })
                        )
                    ]
                    
                    // Wait for all suppression updates to complete
                    await Promise.all(suppressionPromises)
                    
                    // Apply is_primary and is_required to each selected category
                    const visibilityPromises = newEnabledCategories.map(categoryId =>
                        fetch(`/app/api/tenant/metadata/fields/${field.id}/visibility`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken || '' },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                is_primary: formData.is_primary,
                                is_required: formData.is_required,
                                category_id: categoryId,
                            }),
                        })
                    )
                    await Promise.all(visibilityPromises)
                }
                
                onSuccess?.()
                onClose()
            } else if (isEditing) {
                // For editing custom fields, use fetch (update + category suppressions)
                const response = await fetch(`/app/tenant/metadata/fields/${field.id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken || '',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(submitData),
                })
                const data = await response.json()
                if (response.ok) {
                    if (field.id) {
                        const newEnabledCategories = formData.selectedCategories || []
                        const categoriesToSuppress = originalEnabledCategories.filter(catId => !newEnabledCategories.includes(catId))
                        const categoriesToUnsuppress = newEnabledCategories.filter(catId => !originalEnabledCategories.includes(catId))
                        await Promise.all([
                            ...categoriesToSuppress.map(catId => fetch(`/app/api/tenant/metadata/fields/${field.id}/categories/${catId}/suppress`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken || '' }, credentials: 'same-origin' })),
                            ...categoriesToUnsuppress.map(catId => fetch(`/app/api/tenant/metadata/fields/${field.id}/categories/${catId}/suppress`, { method: 'DELETE', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken || '' }, credentials: 'same-origin' })),
                        ])
                        // Apply is_primary and is_required to each selected category
                        const visibilityPromises = newEnabledCategories.map(categoryId =>
                            fetch(`/app/api/tenant/metadata/fields/${field.id}/visibility`, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken || '' },
                                credentials: 'same-origin',
                                body: JSON.stringify({
                                    is_primary: formData.is_primary,
                                    is_required: formData.is_required,
                                    category_id: categoryId,
                                }),
                            })
                        )
                        await Promise.all(visibilityPromises)
                    }
                    onSuccess?.()
                    onClose()
                } else {
                    setErrors(data.errors || { error: data.error || 'Failed to save field' })
                }
            } else {
                // Create: use Inertia router.post for proper response handling
                const storeUrl = typeof route === 'function' ? route('tenant.metadata.fields.store') : '/app/tenant/metadata/fields'
                router.post(storeUrl, submitData, {
                    preserveScroll: true,
                    preserveState: true,
                    forceFormData: false,
                    onSuccess: () => {
                        onSuccess?.()
                        onClose()
                    },
                    onError: (errors) => {
                        setErrors(errors || {})
                    },
                    onFinish: () => {
                        setSubmitting(false)
                    },
                })
                skipFinally = true
                return
            }
        } catch (error) {
            setErrors({ error: 'An error occurred while saving the field' })
        } finally {
            if (!skipFinally) setSubmitting(false)
        }
    }

    const addOption = () => {
        setOptionError(null)
        const value = toSnakeCase(newOption.value) || newOption.value.trim().toLowerCase()
        const system_label = newOption.system_label.trim()

        if (!system_label) {
            setOptionError('Label is required')
            return
        }

        if (!value) {
            setOptionError('Value is required (lowercase snake_case)')
            return
        }

        const snakeCheck = validateSnakeCase(value)
        if (!snakeCheck.valid) {
            setOptionError(snakeCheck.message)
            return
        }

        if (isDuplicateValue(formData.options, value)) {
            setOptionError('This value already exists')
            return
        }

        const opt = {
            value,
            system_label,
            color: newOption.color && /^#[0-9A-Fa-f]{6}$/.test(newOption.color) ? newOption.color : null,
            icon: newOption.icon || null,
        }
        setFormData({
            ...formData,
            options: [...formData.options, opt],
        })
        setNewOption({ value: '', system_label: '', color: '', icon: '' })
    }

    const removeOption = (index) => {
        setFormData({
            ...formData,
            options: formData.options.filter((_, i) => i !== index),
        })
    }

    const isTagsField = formData.key === 'tags' || field?.key === 'tags'
    const requiresOptions = (formData.type === 'select' || formData.type === 'multiselect') && !isTagsField

    // Early return if not open
    if (!isOpen) {
        return null
    }

    // Ensure we're in the browser
    if (typeof window === 'undefined' || !document.body) {
        return null
    }

    const modalContent = (
        <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                {/* Backdrop */}
                <div 
                    className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    onClick={onClose}
                />

                {/* Modal */}
                <div className="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                    {/* Header */}
                    <div className="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-semibold leading-6 text-gray-900">
                                {isEditing ? 'Edit Metadata Field' : 'Create Custom Metadata Field'}
                            </h3>
                            <button
                                type="button"
                                onClick={onClose}
                                className="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                <span className="sr-only">Close</span>
                                <XMarkIcon className="h-6 w-6" />
                            </button>
                        </div>
                        {isSystemField && isEditing && (
                            <p className="mt-2 text-sm text-amber-600">
                                System fields: Only AI eligibility and category visibility can be modified.
                            </p>
                        )}
                    </div>

                    {/* Form */}
                    <form onSubmit={handleSubmit} className="px-4 pb-4 sm:px-6 sm:pb-6">
                        {/* Plan Limit Warning */}
                        {!isEditing && customFieldsLimit && customFieldsLimit.max > 0 && (
                            <div className={`mb-4 rounded-md p-4 ${
                                customFieldsLimit.can_create 
                                    ? 'bg-blue-50 border border-blue-200' 
                                    : 'bg-red-50 border border-red-200'
                            }`}>
                                <p className={`text-sm ${
                                    customFieldsLimit.can_create ? 'text-blue-800' : 'text-red-800'
                                }`}>
                                    {customFieldsLimit.can_create ? (
                                        <>You have {customFieldsLimit.current} of {customFieldsLimit.max} custom metadata fields available.</>
                                    ) : (
                                        <>Plan limit reached ({customFieldsLimit.current}/{customFieldsLimit.max}). Upgrade your plan to create more fields.</>
                                    )}
                                </p>
                            </div>
                        )}

                        {errors.error && (
                            <div className="mb-4 rounded-md bg-red-50 p-4">
                                <p className="text-sm text-red-800">{errors.error}</p>
                            </div>
                        )}

                        <div className="space-y-6">
                            {/* Category Selection Info */}
                            {!isEditing && (
                                <>
                                    {preselectedCategoryId ? (
                                        <div className="rounded-md bg-blue-50 p-4">
                                            <div className="flex">
                                                <div className="ml-3">
                                                    <h3 className="text-sm font-medium text-blue-800">Category Pre-selected</h3>
                                                    <div className="mt-2 text-sm text-blue-700">
                                                        <p>
                                                            The category you were viewing has been pre-selected below. You can add more categories or remove it if needed.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="rounded-md bg-amber-50 p-4 border border-amber-200">
                                            <div className="flex">
                                                <div className="flex-shrink-0">
                                                    <svg className="h-5 w-5 text-amber-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                                    </svg>
                                                </div>
                                                <div className="ml-3">
                                                    <h3 className="text-sm font-medium text-amber-800">Select Categories</h3>
                                                    <div className="mt-2 text-sm text-amber-700">
                                                        <p>
                                                            Please select at least one category where this field should be enabled. The field will be available for assets in the selected categories.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </>
                            )}

                            {/* Field Key */}
                            <div>
                                <label htmlFor="key" className="block text-sm font-medium leading-6 text-gray-900">
                                    Field Key {!isEditing && <span className="text-red-500">*</span>}
                                </label>
                                <div className="mt-2">
                                    <input
                                        type="text"
                                        id="key"
                                        required={!isEditing}
                                        disabled={isEditing}
                                        value={formData.key}
                                        onChange={(e) => setFormData({ ...formData, key: e.target.value })}
                                        className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 disabled:bg-gray-50 disabled:text-gray-500"
                                        placeholder={isEditing ? "Field key cannot be changed" : "my_field"}
                                    />
                                </div>
                                {!isEditing && (
                                    <p className="mt-2 text-sm text-gray-500">
                                        Will be prefixed with "custom__" automatically. Use lowercase with underscores.
                                    </p>
                                )}
                                {errors.key && <p className="mt-2 text-sm text-red-600">{errors.key}</p>}
                            </div>

                            {/* System Label */}
                            <div>
                                <label htmlFor="system_label" className="block text-sm font-medium leading-6 text-gray-900">
                                    Display Name <span className="text-red-500">*</span>
                                </label>
                                <div className="mt-2">
                                    <input
                                        type="text"
                                        id="system_label"
                                        required
                                        value={formData.system_label}
                                        onChange={(e) => setFormData({ ...formData, system_label: e.target.value })}
                                        className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        placeholder="My Custom Field"
                                    />
                                </div>
                                {errors.system_label && <p className="mt-2 text-sm text-red-600">{errors.system_label}</p>}
                            </div>

                            {/* Field Type */}
                            <div>
                                <label htmlFor="type" className="block text-sm font-medium leading-6 text-gray-900">
                                    Field Type <span className="text-red-500">*</span>
                                </label>
                                <div className="mt-2">
                                    <select
                                        id="type"
                                        required
                                        disabled={isEditing}
                                        value={formData.type}
                                        onChange={(e) => setFormData({ ...formData, type: e.target.value, options: requiresOptions ? formData.options : [] })}
                                        className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 disabled:bg-gray-50 disabled:text-gray-500"
                                    >
                                        <option value="text">Text</option>
                                        <option value="textarea">Textarea</option>
                                        <option value="select">Select (Single)</option>
                                        <option value="multiselect">Select (Multiple)</option>
                                        <option value="number">Number</option>
                                        <option value="boolean">Boolean (Yes/No)</option>
                                        <option value="date">Date</option>
                                    </select>
                                </div>
                                {errors.type && <p className="mt-2 text-sm text-red-600">{errors.type}</p>}
                            </div>

                            {/* Categories Multi-Select */}
                            {(
                                <div>
                                    <label className="block text-sm font-medium leading-6 text-gray-900">
                                        Categories <span className="text-red-500">*</span>
                                    </label>
                                    <div className="mt-2 relative" ref={categoryDropdownRef}>
                                        {/* Multi-Select Button */}
                                        <button
                                            type="button"
                                            onClick={() => setIsCategoryDropdownOpen(!isCategoryDropdownOpen)}
                                            className="relative w-full cursor-default rounded-md bg-white py-2 pl-3 pr-10 text-left text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:text-sm sm:leading-6"
                                        >
                                            <span className="block truncate">
                                                {formData.selectedCategories.length > 0 
                                                    ? `${formData.selectedCategories.length} categor${formData.selectedCategories.length === 1 ? 'y' : 'ies'} selected`
                                                    : 'Select categories...'}
                                            </span>
                                            <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                                <ChevronDownIcon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                                            </span>
                                        </button>

                                        {/* Dropdown Menu */}
                                        {isCategoryDropdownOpen && (
                                            <div className="absolute z-10 mt-1 max-h-96 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm">
                                                {/* Asset Categories */}
                                                {groupedCategories.asset.length > 0 && (
                                                    <div className="px-2 py-1">
                                                        <div className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1 px-2">
                                                            Asset Categories
                                                        </div>
                                                        {groupedCategories.asset.map(category => {
                                                            const isSelected = formData.selectedCategories.includes(category.id)
                                                            return (
                                                                <label
                                                                    key={category.id}
                                                                    className="relative flex cursor-pointer select-none items-center rounded-md px-2 py-2 hover:bg-gray-50"
                                                                >
                                                                    <div className="flex h-6 items-center">
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={isSelected}
                                                                            onChange={() => toggleCategorySelection(category.id)}
                                                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                                        />
                                                                    </div>
                                                                    <div className="ml-3 flex flex-col">
                                                                        <span className={`block truncate text-sm ${isSelected ? 'font-medium text-gray-900' : 'text-gray-700'}`}>
                                                                            {category.name}
                                                                        </span>
                                                                        {category.brand_name && (
                                                                            <span className="text-xs text-gray-500">
                                                                                {category.brand_name}
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                    {isSelected && (
                                                                        <span className="absolute inset-y-0 right-0 flex items-center pr-4 text-indigo-600">
                                                                            <CheckIcon className="h-5 w-5" aria-hidden="true" />
                                                                        </span>
                                                                    )}
                                                                </label>
                                                            )
                                                        })}
                                                    </div>
                                                )}

                                                {/* Execution Categories (internal: deliverable) */}
                                                {groupedCategories.deliverable.length > 0 && (
                                                    <div className="px-2 py-1 border-t border-gray-200 mt-1">
                                                        <div className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1 px-2 mt-2">
                                                            Execution Categories
                                                        </div>
                                                        {groupedCategories.deliverable.map(category => {
                                                            const isSelected = formData.selectedCategories.includes(category.id)
                                                            return (
                                                                <label
                                                                    key={category.id}
                                                                    className="relative flex cursor-pointer select-none items-center rounded-md px-2 py-2 hover:bg-gray-50"
                                                                >
                                                                    <div className="flex h-6 items-center">
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={isSelected}
                                                                            onChange={() => toggleCategorySelection(category.id)}
                                                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                                        />
                                                                    </div>
                                                                    <div className="ml-3 flex flex-col">
                                                                        <span className={`block truncate text-sm ${isSelected ? 'font-medium text-gray-900' : 'text-gray-700'}`}>
                                                                            {category.name}
                                                                        </span>
                                                                        {category.brand_name && (
                                                                            <span className="text-xs text-gray-500">
                                                                                {category.brand_name}
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                    {isSelected && (
                                                                        <span className="absolute inset-y-0 right-0 flex items-center pr-4 text-indigo-600">
                                                                            <CheckIcon className="h-5 w-5" aria-hidden="true" />
                                                                        </span>
                                                                    )}
                                                                </label>
                                                            )
                                                        })}
                                                    </div>
                                                )}

                                                {categories.length === 0 && (
                                                    <div className="px-2 py-2 text-sm text-gray-500 italic">
                                                        No categories available
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>

                                    {/* Selected Categories Display */}
                                    {formData.selectedCategories.length > 0 && (
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {categories
                                                .filter(cat => formData.selectedCategories.includes(cat.id))
                                                .map(category => (
                                                    <span
                                                        key={category.id}
                                                        className="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700"
                                                    >
                                                        {category.name}
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleCategorySelection(category.id)}
                                                            className="text-indigo-600 hover:text-indigo-800"
                                                        >
                                                            <span className="sr-only">Remove</span>
                                                            <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                                <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                                                            </svg>
                                                        </button>
                                                    </span>
                                                ))}
                                        </div>
                                    )}

                                    <p className="mt-2 text-sm text-gray-500">
                                        Select one or more categories where this field should be enabled.
                                    </p>
                                    {formData.selectedCategories.length === 0 && (
                                        <p className="mt-2 text-sm text-amber-600">
                                            At least one category must be selected.
                                        </p>
                                    )}
                                    {errors.selectedCategories && <p className="mt-2 text-sm text-red-600">{errors.selectedCategories}</p>}
                                </div>
                            )}

                            {/* Options (for select/multiselect) */}
                            {requiresOptions && (
                                <div>
                                    <label className="block text-sm font-medium leading-6 text-gray-900">
                                        Options <span className="text-red-500">*</span>
                                    </label>
                                    <p className="mt-1 text-sm text-gray-500">
                                        Add values that users can select. Value must be lowercase snake_case (e.g. high_quality).
                                    </p>
                                    <div className="mt-3 space-y-3">
                                        {formData.options.length > 0 && (
                                            <div className="space-y-2">
                                                {formData.options.map((option, index) => (
                                                    <div key={index} className="flex items-center gap-2 rounded-md border border-gray-200 p-2">
                                                        {/* Preview chip */}
                                                        <div className="flex-shrink-0">
                                                            <span
                                                                className="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-medium border border-gray-200"
                                                                style={
                                                                    option.color
                                                                        ? { backgroundColor: `${option.color}20`, color: option.color, borderColor: option.color }
                                                                        : { backgroundColor: '#f3f4f6', color: '#374151' }
                                                                }
                                                            >
                                                                {option.color && (
                                                                    <span
                                                                        className="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                                                        style={{ backgroundColor: option.color }}
                                                                    />
                                                                )}
                                                                {option.icon && <OptionIcon icon={option.icon} className="h-3.5 w-3.5" />}
                                                                {option.system_label || option.value}
                                                            </span>
                                                        </div>
                                                        <div className="flex-1 min-w-0 grid grid-cols-2 gap-2">
                                                            <div>
                                                                <span className="text-xs text-gray-500">Value</span>
                                                                <input
                                                                    type="text"
                                                                    value={option.value}
                                                                    readOnly
                                                                    className="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 sm:text-sm sm:leading-6 bg-gray-50"
                                                                />
                                                            </div>
                                                            <div>
                                                                <span className="text-xs text-gray-500">Label</span>
                                                                <input
                                                                    type="text"
                                                                    value={option.system_label || option.label}
                                                                    readOnly
                                                                    className="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 sm:text-sm sm:leading-6 bg-gray-50"
                                                                />
                                                            </div>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => removeOption(index)}
                                                            className="mt-5 text-red-600 hover:text-red-800 text-sm font-medium"
                                                        >
                                                            Remove
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                        {/* Add new option */}
                                        <div className="rounded-md border border-gray-200 p-3 bg-gray-50 space-y-3">
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div>
                                                    <label className="text-xs text-gray-500">Value (snake_case)</label>
                                                    <input
                                                        type="text"
                                                        value={newOption.value}
                                                        onChange={(e) => {
                                                            const v = e.target.value
                                                            setNewOption((prev) => ({ ...prev, value: v }))
                                                            setOptionError(null)
                                                        }}
                                                        onBlur={(e) => {
                                                            const v = e.target.value
                                                            if (v) setNewOption((prev) => ({ ...prev, value: toSnakeCase(v) }))
                                                        }}
                                                        placeholder="e.g., high_quality"
                                                        className="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="text-xs text-gray-500">Label <span className="text-red-500">*</span></label>
                                                    <input
                                                        type="text"
                                                        value={newOption.system_label}
                                                        onChange={(e) => {
                                                            setNewOption((prev) => ({ ...prev, system_label: e.target.value }))
                                                            setOptionError(null)
                                                        }}
                                                        placeholder="e.g., High Quality"
                                                        className="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                    />
                                                </div>
                                            </div>
                                            <div className="flex flex-wrap items-end gap-3">
                                                <div>
                                                    <label className="text-xs text-gray-500 block mb-1">Color (optional)</label>
                                                    <div className="flex items-center gap-2">
                                                        <input
                                                            type="color"
                                                            value={newOption.color || '#6366f1'}
                                                            onChange={(e) => setNewOption((prev) => ({ ...prev, color: e.target.value }))}
                                                            className="h-8 w-8 rounded border border-gray-300 cursor-pointer p-0"
                                                        />
                                                        <input
                                                            type="text"
                                                            value={newOption.color || ''}
                                                            onChange={(e) => setNewOption((prev) => ({ ...prev, color: e.target.value }))}
                                                            placeholder="#6366f1"
                                                            className="w-24 rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 text-sm"
                                                        />
                                                    </div>
                                                </div>
                                                <div>
                                                    <label className="text-xs text-gray-500 block mb-1">Icon (optional)</label>
                                                    <OptionIconSelector
                                                        value={newOption.icon}
                                                        onChange={(icon) => setNewOption((prev) => ({ ...prev, icon }))}
                                                        className="py-1.5"
                                                    />
                                                </div>
                                                <div className="flex-1" />
                                                <div className="flex items-center gap-2">
                                                    {optionError && <span className="text-sm text-red-600">{optionError}</span>}
                                                    <button
                                                        type="button"
                                                        onClick={addOption}
                                                        disabled={!newOption.system_label.trim()}
                                                        className="px-3 py-1.5 text-sm font-semibold text-white bg-indigo-600 rounded-md hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        Add
                                                    </button>
                                                </div>
                                            </div>
                                            {/* Preview chip for new option */}
                                            {(newOption.system_label || newOption.value) && (
                                                <div className="pt-1 border-t border-gray-200">
                                                    <span className="text-xs text-gray-500">Preview: </span>
                                                    <span
                                                        className="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-medium border border-gray-200"
                                                        style={
                                                            newOption.color && /^#[0-9A-Fa-f]{6}$/.test(newOption.color)
                                                                ? { backgroundColor: `${newOption.color}20`, color: newOption.color, borderColor: newOption.color }
                                                                : { backgroundColor: '#f3f4f6', color: '#374151' }
                                                        }
                                                    >
                                                        {newOption.color && /^#[0-9A-Fa-f]{6}$/.test(newOption.color) && (
                                                            <span
                                                                className="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                                                style={{ backgroundColor: newOption.color }}
                                                            />
                                                        )}
                                                        {newOption.icon && <OptionIcon icon={newOption.icon} className="h-3.5 w-3.5" />}
                                                        {newOption.system_label || toSnakeCase(newOption.value) || newOption.value || '…'}
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                    {errors.options && <p className="mt-2 text-sm text-red-600">{errors.options}</p>}
                                </div>
                            )}

                            {/* Visibility Options - Using Tailwind checkbox pattern */}
                            <div>
                                <label className="text-base font-semibold leading-6 text-gray-900">
                                    Visibility
                                </label>
                                <p className="text-sm leading-6 text-gray-600">
                                    Control where this field appears in your workflow
                                </p>
                                <fieldset className="mt-4">
                                    <div className="space-y-4">
                                        <div className="relative flex items-start">
                                            <div className="flex h-6 items-center">
                                                <input
                                                    id="show_on_upload"
                                                    name="show_on_upload"
                                                    type="checkbox"
                                                    checked={formData.show_on_upload}
                                                    onChange={(e) => setFormData({ ...formData, show_on_upload: e.target.checked })}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                            </div>
                                            <div className="ml-3 text-sm leading-6">
                                                <label htmlFor="show_on_upload" className="font-medium text-gray-900">
                                                    Show on Upload
                                                </label>
                                                <p className="text-gray-500">Display this field in the upload interface</p>
                                            </div>
                                        </div>
                                        <div className="relative flex items-start">
                                            <div className="flex h-6 items-center">
                                                <input
                                                    id="show_on_edit"
                                                    name="show_on_edit"
                                                    type="checkbox"
                                                    checked={formData.show_on_edit}
                                                    onChange={(e) => setFormData({ ...formData, show_on_edit: e.target.checked })}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                            </div>
                                            <div className="ml-3 text-sm leading-6">
                                                <label htmlFor="show_on_edit" className="font-medium text-gray-900">
                                                    Quick View
                                                </label>
                                                <p className="text-gray-500">Display this field in the asset drawer and details modal</p>
                                            </div>
                                        </div>
                                        <div className="relative flex items-start">
                                            <div className="flex h-6 items-center">
                                                <input
                                                    id="show_in_filters"
                                                    name="show_in_filters"
                                                    type="checkbox"
                                                    checked={formData.show_in_filters}
                                                    onChange={(e) => setFormData({ ...formData, show_in_filters: e.target.checked })}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                            </div>
                                            <div className="ml-3 text-sm leading-6">
                                                <label htmlFor="show_in_filters" className="font-medium text-gray-900">
                                                    Show in Filters
                                                </label>
                                                <p className="text-gray-500">Allow filtering assets by this field</p>
                                            </div>
                                        </div>
                                        {formData.show_in_filters && (
                                            <div className="relative flex items-start pl-7">
                                                <div className="flex h-6 items-center">
                                                    <input
                                                        id="is_primary"
                                                        name="is_primary"
                                                        type="checkbox"
                                                        checked={formData.is_primary}
                                                        onChange={(e) => setFormData({ ...formData, is_primary: e.target.checked })}
                                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                    />
                                                </div>
                                                <div className="ml-3 text-sm leading-6">
                                                    <label htmlFor="is_primary" className="font-medium text-gray-900">
                                                        Primary Filter
                                                    </label>
                                                    <p className="text-gray-500">Show this field inline in the asset grid filter bar (otherwise under &quot;More filters&quot;)</p>
                                                </div>
                                            </div>
                                        )}
                                        {formData.show_on_upload && (
                                            <div className="relative flex items-start pl-7">
                                                <div className="flex h-6 items-center">
                                                    <input
                                                        id="is_required"
                                                        name="is_required"
                                                        type="checkbox"
                                                        checked={formData.is_required}
                                                        onChange={(e) => setFormData({ ...formData, is_required: e.target.checked })}
                                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                    />
                                                </div>
                                                <div className="ml-3 text-sm leading-6">
                                                    <label htmlFor="is_required" className="font-medium text-gray-900">
                                                        Required
                                                    </label>
                                                    <p className="text-gray-500">Must be filled when adding assets to selected categories</p>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </fieldset>
                            </div>

                            {/* AI Eligible (for select/multiselect fields, or tags field) */}
                            {(requiresOptions || isTagsField) && (
                                <div className="relative flex items-start">
                                    <div className="flex h-6 items-center">
                                        <input
                                            id="ai_eligible"
                                            name="ai_eligible"
                                            type="checkbox"
                                            checked={formData.ai_eligible}
                                            onChange={(e) => setFormData({ ...formData, ai_eligible: e.target.checked })}
                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                        />
                                    </div>
                                    <div className="ml-3 text-sm leading-6">
                                        <label htmlFor="ai_eligible" className="font-medium text-gray-900">
                                            Enable AI Suggestions
                                        </label>
                                        <p className="text-gray-500">
                                            Allow AI to suggest values for this field based on asset content.
                                            {!isTagsField && formData.options.length === 0 && (
                                                <span className="text-amber-600 font-medium"> Note: Options must be defined for AI suggestions to work.</span>
                                            )}
                                        </p>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Actions */}
                        <div className="mt-6 flex items-center justify-end gap-3 border-t border-gray-200 pt-4">
                            <button
                                type="button"
                                onClick={onClose}
                                className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={submitting || (!isEditing && customFieldsLimit && !customFieldsLimit.can_create)}
                                className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {submitting && (
                                    <ArrowPathIcon className="h-4 w-4 animate-spin" aria-hidden />
                                )}
                                {submitting ? (isEditing ? 'Saving...' : 'Creating...') : (isEditing ? 'Save Changes' : 'Create Field')}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    )

    // Render modal using portal to ensure it's at the root level
    return createPortal(modalContent, document.body)
}
