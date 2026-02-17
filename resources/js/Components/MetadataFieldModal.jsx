import { useState, useEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import { router } from '@inertiajs/react'
import { arrayMove } from '@dnd-kit/sortable'
import { ArrowPathIcon, Bars3Icon, CheckIcon, ChevronDownIcon, CloudArrowUpIcon, FunnelIcon, Squares2X2Icon, SparklesIcon, TrashIcon, XMarkIcon } from '@heroicons/react/24/outline'

/** Custom checkbox: hidden native input + styled box with checkmark. variant="ai" uses purple for AI branding. */
function StyledCheckbox({ id, name, checked, onChange, disabled, variant = 'default' }) {
    const isAi = variant === 'ai'
    const checkedClasses = isAi ? 'peer-checked:border-purple-600 peer-checked:bg-purple-600' : 'peer-checked:border-indigo-600 peer-checked:bg-indigo-600'
    const focusClasses = isAi ? 'peer-focus:ring-purple-500/40' : 'peer-focus:ring-indigo-500/40'
    return (
        <span className="relative flex h-4 w-4 shrink-0">
            <input
                type="checkbox"
                id={id}
                name={name}
                checked={checked}
                onChange={onChange}
                disabled={disabled}
                className="peer sr-only"
            />
            <span className={`absolute inset-0 flex items-center justify-center rounded border-2 border-gray-300 bg-white transition-colors ${checkedClasses} peer-focus:ring-2 ${focusClasses} peer-focus:ring-offset-0 peer-disabled:border-gray-200 peer-disabled:bg-gray-50 peer-disabled:opacity-60`} />
            <CheckIcon className="pointer-events-none absolute inset-0 m-auto h-2.5 w-2.5 text-white opacity-0 transition-opacity peer-checked:opacity-100" strokeWidth={2.5} />
        </span>
    )
}
import {
    toSnakeCase,
    validateSnakeCase,
    isDuplicateValue,
    normalizeOptions,
    prepareOptionsForSubmit,
    snakeToTitleCase,
} from '../utils/optionEditorUtils'
import { getCustomDisplayLabel } from '../utils/widgetResolver'

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
        option_editing_restricted: false,
        group_key: '',
    })
    const [newOption, setNewOption] = useState({ value: '', system_label: '' })
    const [bulkAddText, setBulkAddText] = useState('')
    const [optionError, setOptionError] = useState(null)
    const [errors, setErrors] = useState({})
    const [submitting, setSubmitting] = useState(false)
    const [loadingField, setLoadingField] = useState(false)
    const [isCategoryDropdownOpen, setIsCategoryDropdownOpen] = useState(false)
    const categoryDropdownRef = useRef(null)
    const [originalEnabledCategories, setOriginalEnabledCategories] = useState([]) // Track original enabled categories when editing
    const [keyManuallyEdited, setKeyManuallyEdited] = useState(false) // When false, key syncs from display name

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
                                option_editing_restricted: fieldData.field.option_editing_restricted ?? false,
                                group_key: fieldData.field.group_key || '',
                            })
                            setBulkAddText('')
                            setNewOption({ value: '', system_label: '' })
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
                                option_editing_restricted: fullFieldData.option_editing_restricted ?? field.option_editing_restricted ?? false,
                                group_key: field.group_key || '',
                            })
                            setBulkAddText('')
                            setNewOption({ value: '', system_label: '' })
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
                                option_editing_restricted: field.option_editing_restricted ?? false,
                                group_key: field.group_key || '',
                            })
                            setBulkAddText('')
                            setNewOption({ value: '', system_label: '' })
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
                        option_editing_restricted: field.option_editing_restricted ?? false,
                        group_key: field.group_key || '',
                    })
                    setBulkAddText('')
                    setNewOption({ value: '', system_label: '' })
                    setLoadingField(false)
                }
            }
        } else if (isOpen && !field) {
            // Reset form for new field
            const initialCategories = preselectedCategoryId ? [preselectedCategoryId] : []
            setOriginalEnabledCategories([])
            setKeyManuallyEdited(false)
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
                option_editing_restricted: false,
                group_key: '',
            })
            setBulkAddText('')
            setNewOption({ value: '', system_label: '' })
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

        const opt = { value, system_label }
        setFormData({
            ...formData,
            options: [...formData.options, opt],
        })
        setNewOption({ value: '', system_label: '' })
    }

    const processBulkAdd = () => {
        setOptionError(null)
        const lines = bulkAddText
            .split('\n')
            .map((l) => l.trim())
            .filter(Boolean)
        if (lines.length === 0) {
            setOptionError('Enter at least one value (one per line)')
            return
        }
        const existingValues = new Set(formData.options.map((o) => o.value.toLowerCase()))
        const toAdd = []
        for (const line of lines) {
            const value = toSnakeCase(line) || line.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '')
            if (!value) continue
            const snakeCheck = validateSnakeCase(value)
            if (!snakeCheck.valid) {
                setOptionError(snakeCheck.message)
                return
            }
            if (existingValues.has(value)) continue
            existingValues.add(value)
            toAdd.push({ value, system_label: snakeToTitleCase(value) })
        }
        if (toAdd.length === 0) {
            setOptionError('All values already exist or are invalid')
            return
        }
        setFormData({
            ...formData,
            options: [...formData.options, ...toAdd],
        })
        setBulkAddText('')
    }

    const moveOption = (fromIndex, toIndex) => {
        if (fromIndex === toIndex || toIndex < 0 || toIndex >= formData.options.length) return
        const next = arrayMove(formData.options, fromIndex, toIndex)
        setFormData({ ...formData, options: next })
    }

    const removeOption = (index) => {
        setFormData({
            ...formData,
            options: formData.options.filter((_, i) => i !== index),
        })
    }

    const isTagsField = formData.key === 'tags' || field?.key === 'tags'
    const requiresOptions = (formData.type === 'select' || formData.type === 'multiselect') && !isTagsField
    // dominant_hue_group: filter-only system field — user may only control is_filter_hidden
    const isFilterOnlyField = (field?.key ?? formData?.key) === 'dominant_hue_group'

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
                <div className="relative transform overflow-hidden rounded-xl bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                    {/* Compact Header */}
                    <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3 sm:px-5">
                        <div className="flex items-center gap-3 min-w-0">
                            <h3 className="text-base font-semibold text-gray-900 truncate">
                                {isEditing ? 'Edit Metadata Field' : 'Create Custom Metadata Field'}
                            </h3>
                            {!isEditing && customFieldsLimit && customFieldsLimit.max > 0 && (
                                <span className={`flex-shrink-0 text-xs px-2 py-0.5 rounded-full ${
                                    customFieldsLimit.can_create ? 'bg-indigo-50 text-indigo-700' : 'bg-red-50 text-red-700'
                                }`}>
                                    {customFieldsLimit.current}/{customFieldsLimit.max}
                                </span>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="flex-shrink-0 rounded-md p-1 text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                        >
                            <span className="sr-only">Close</span>
                            <XMarkIcon className="h-5 w-5" />
                        </button>
                    </div>
                    {isSystemField && isEditing && (
                        <p className="px-4 py-2 text-xs text-amber-600 bg-amber-50 border-b border-amber-100">
                            System fields: Only AI eligibility and category visibility can be modified.
                        </p>
                    )}

                    {/* Form */}
                    <form onSubmit={handleSubmit} className="px-4 py-4 sm:px-5 sm:py-5 max-h-[calc(100vh-12rem)] overflow-y-auto">
                        {errors.error && (
                            <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">
                                {errors.error}
                            </div>
                        )}

                        <div className="space-y-4">
                            {/* Display Name first (primary input); Field Key auto-syncs from it until manually edited */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label htmlFor="system_label" className="block text-xs font-medium text-gray-700 mb-1">
                                        Display Name <span className="text-red-500">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="system_label"
                                        required
                                        value={formData.system_label}
                                        onChange={(e) => {
                                            const label = e.target.value
                                            setFormData(prev => ({
                                                ...prev,
                                                system_label: label,
                                                ...(!isEditing && !keyManuallyEdited && { key: toSnakeCase(label) }),
                                            }))
                                        }}
                                        className="block w-full rounded-md border border-gray-300 py-1.5 px-2.5 text-sm placeholder-gray-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        placeholder="My Custom Field"
                                    />
                                    {errors.system_label && <p className="mt-1 text-xs text-red-600">{errors.system_label}</p>}
                                </div>
                                <div>
                                    <label htmlFor="key" className="block text-xs font-medium text-gray-700 mb-1">
                                        Field Key {!isEditing && <span className="text-red-500">*</span>}
                                    </label>
                                    <input
                                        type="text"
                                        id="key"
                                        required={!isEditing}
                                        disabled={isEditing}
                                        value={formData.key}
                                        onChange={(e) => {
                                            setKeyManuallyEdited(true)
                                            setFormData({ ...formData, key: e.target.value })
                                        }}
                                        className="block w-full rounded-md border border-gray-300 py-1.5 px-2.5 text-sm placeholder-gray-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-50"
                                        placeholder={isEditing ? "—" : "my_field"}
                                    />
                                    {!isEditing && <p className="mt-1 text-xs text-gray-500">Prefixed with custom__</p>}
                                    {errors.key && <p className="mt-1 text-xs text-red-600">{errors.key}</p>}
                                </div>
                            </div>

                            {/* Field Type as horizontal chips */}
                            <div>
                                <label className="block text-xs font-medium text-gray-700 mb-2">
                                    Field Type <span className="text-red-500">*</span>
                                </label>
                                <div className="flex flex-wrap items-center gap-2">
                                    {[
                                        { value: 'text', label: 'Text' },
                                        { value: 'textarea', label: 'Textarea' },
                                        { value: 'select', label: 'Select' },
                                        { value: 'multiselect', label: 'Multi-Select' },
                                        { value: 'number', label: 'Number' },
                                        { value: 'boolean', label: 'Boolean' },
                                        { value: 'date', label: 'Date' },
                                    ].map(({ value, label }) => (
                                        <button
                                            key={value}
                                            type="button"
                                            disabled={isEditing}
                                            onClick={() => setFormData({ ...formData, type: value, options: ['select', 'multiselect'].includes(value) ? [] : formData.options })}
                                            className={`px-3 py-1.5 text-xs font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed ${
                                                formData.type === value
                                                    ? 'bg-indigo-600 text-white'
                                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                            }`}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                    {isEditing && (() => {
                                        const fieldForDisplay = { ...field, type: formData.type, display_widget: field?.display_widget }
                                        const customLabel = getCustomDisplayLabel(fieldForDisplay)
                                        return customLabel ? (
                                            <span className="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md bg-amber-50 text-amber-800 border border-amber-200">
                                                Custom display: {customLabel}
                                            </span>
                                        ) : null
                                    })()}
                                </div>
                                {errors.type && <p className="mt-1 text-xs text-red-600">{errors.type}</p>}
                            </div>

                            {/* Categories — compact */}
                            <div>
                                <label className="block text-xs font-medium text-gray-700 mb-1">
                                    Categories <span className="text-red-500">*</span>
                                </label>
                                {!isEditing && preselectedCategoryId && (
                                    <p className="text-xs text-indigo-600 mb-1">Pre-selected from current view</p>
                                )}
                                <div className="relative" ref={categoryDropdownRef}>
                                        <button
                                            type="button"
                                            onClick={() => setIsCategoryDropdownOpen(!isCategoryDropdownOpen)}
                                            className="relative w-full cursor-default rounded-md border border-gray-300 bg-white py-1.5 pl-2.5 pr-8 text-left text-sm text-gray-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
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

                                {formData.selectedCategories.length === 0 && (
                                    <p className="mt-1 text-xs text-amber-600">Select at least one category</p>
                                )}
                                {errors.selectedCategories && <p className="mt-1 text-xs text-red-600">{errors.selectedCategories}</p>}
                            </div>

                            {/* Options (for select/multiselect) — hidden for system fields with custom rendering */}
                            {requiresOptions && formData.option_editing_restricted && (
                                <div className="rounded-md bg-amber-50 border border-amber-200 p-4">
                                    <p className="text-sm text-amber-800">
                                        This field uses a system-managed display and does not support manual options.
                                    </p>
                                </div>
                            )}
                            {requiresOptions && !formData.option_editing_restricted && (
                                <div className="rounded-md border border-gray-200 bg-gray-50/50 p-3">
                                    <p className="text-xs font-medium text-gray-700 mb-1">
                                        {formData.type === 'multiselect' ? 'Multi-Select' : 'Single Select'} — add options (one per line)
                                    </p>
                                    <div className="flex gap-2 flex-wrap items-end">
                                        <textarea
                                            value={bulkAddText}
                                            onChange={(e) => {
                                                setBulkAddText(e.target.value)
                                                setOptionError(null)
                                            }}
                                            placeholder={'high_quality\nmedium_quality\nlow_quality'}
                                            rows={2}
                                            className="flex-1 min-w-[120px] block rounded-md border border-gray-300 py-1.5 px-2 text-sm placeholder-gray-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                        <button
                                            type="button"
                                            onClick={processBulkAdd}
                                            disabled={!bulkAddText.trim()}
                                            className="rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Add options
                                        </button>
                                        {optionError && <span className="text-xs text-red-600 w-full">{optionError}</span>}
                                    </div>
                                    <div className="mt-3">
                                        <div className="rounded border border-gray-200 overflow-hidden bg-white">
                                            {formData.options.length === 0 ? (
                                                <div className="px-3 py-4 text-center text-xs text-gray-500">
                                                    No options yet
                                                </div>
                                            ) : (
                                                <div className={formData.options.length > 8 ? 'max-h-64 overflow-y-auto' : ''}>
                                                    <table className="min-w-full divide-y divide-gray-200">
                                                        <thead className="bg-gray-50">
                                                            <tr>
                                                                <th scope="col" className="w-9 px-2 py-2" />
                                                                <th scope="col" className="w-40 px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                                    Value
                                                                </th>
                                                                <th scope="col" className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                                    Label
                                                                </th>
                                                                <th scope="col" className="w-10 px-2 py-2" />
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y divide-gray-200 bg-white">
                                                            {formData.options.map((option, index) => (
                                                                <tr
                                                                    key={`${option.value}-${index}`}
                                                                    className="hover:bg-gray-50 transition-colors"
                                                                >
                                                                    <td className="px-2 py-1.5">
                                                                        <div className="flex items-center text-gray-400 cursor-grab active:cursor-grabbing">
                                                                            <Bars3Icon className="w-4 h-4" aria-hidden />
                                                                        </div>
                                                                    </td>
                                                                    <td className="px-3 py-1.5">
                                                                        <span className="text-sm font-mono text-gray-700">
                                                                            {option.value}
                                                                        </span>
                                                                    </td>
                                                                    <td className="px-3 py-1.5">
                                                                        <span className="text-sm text-gray-900">
                                                                            {option.system_label || option.label || option.value}
                                                                        </span>
                                                                    </td>
                                                                    <td className="px-2 py-1.5">
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => removeOption(index)}
                                                                            className="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 transition-colors"
                                                                            title="Remove"
                                                                        >
                                                                            <TrashIcon className="w-4 h-4" aria-hidden />
                                                                        </button>
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="mt-2 flex items-center gap-2 flex-wrap">
                                        <input
                                            type="text"
                                            value={newOption.value}
                                            onChange={(e) => {
                                                setNewOption((prev) => ({ ...prev, value: e.target.value }))
                                                setOptionError(null)
                                            }}
                                            onBlur={(e) => {
                                                const v = e.target.value
                                                if (v) setNewOption((prev) => ({ ...prev, value: toSnakeCase(v) }))
                                            }}
                                            placeholder="Value (snake_case)"
                                            className="block w-32 rounded-md border border-gray-300 py-1 px-2 text-xs placeholder-gray-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                        <input
                                            type="text"
                                            value={newOption.system_label}
                                            onChange={(e) => {
                                                setNewOption((prev) => ({ ...prev, system_label: e.target.value }))
                                                setOptionError(null)
                                            }}
                                            placeholder="Label"
                                            className="block flex-1 min-w-[80px] rounded-md border border-gray-300 py-1 px-2 text-xs placeholder-gray-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                        <button
                                            type="button"
                                            onClick={addOption}
                                            disabled={!newOption.value.trim() || !newOption.system_label.trim()}
                                            className="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Add one
                                        </button>
                                    </div>
                                    {errors.options && <p className="mt-2 text-sm text-red-600">{errors.options}</p>}
                                </div>
                            )}

                            {/* Visibility — compact 2-column grid */}
                            <div>
                                <label className="text-xs font-medium text-gray-700 mb-2 block">Visibility</label>
                                <fieldset>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
                                        {!isFilterOnlyField && (
                                            <label className="flex items-center gap-2 cursor-pointer py-1">
                                                <StyledCheckbox
                                                    id="show_on_upload"
                                                    name="show_on_upload"
                                                    checked={formData.show_on_upload}
                                                    onChange={(e) => setFormData({ ...formData, show_on_upload: e.target.checked })}
                                                />
                                                <CloudArrowUpIcon className="h-4 w-4 text-gray-400" />
                                                <span className="text-sm text-gray-900">Show on Upload</span>
                                            </label>
                                        )}
                                        {!isFilterOnlyField && (
                                            <label className="flex items-center gap-2 cursor-pointer py-1">
                                                <StyledCheckbox
                                                    id="show_on_edit"
                                                    name="show_on_edit"
                                                    checked={formData.show_on_edit}
                                                    onChange={(e) => setFormData({ ...formData, show_on_edit: e.target.checked })}
                                                />
                                                <Squares2X2Icon className="h-4 w-4 text-gray-400" />
                                                <span className="text-sm text-gray-900">Quick View</span>
                                            </label>
                                        )}
                                        <label className="flex items-center gap-2 cursor-pointer py-1">
                                            <StyledCheckbox
                                                id="show_in_filters"
                                                name="show_in_filters"
                                                checked={formData.show_in_filters}
                                                onChange={(e) => setFormData({ ...formData, show_in_filters: e.target.checked })}
                                            />
                                            <FunnelIcon className="h-4 w-4 text-gray-400" />
                                            <span className="text-sm text-gray-900">Show in Filters</span>
                                        </label>
                                        {!isFilterOnlyField && formData.show_in_filters && (
                                            <label className="flex items-center gap-2 cursor-pointer py-1 sm:col-start-2">
                                                <StyledCheckbox
                                                    id="is_primary"
                                                    name="is_primary"
                                                    checked={formData.is_primary}
                                                    onChange={(e) => setFormData({ ...formData, is_primary: e.target.checked })}
                                                />
                                                <span className="text-sm text-gray-900">Primary Filter</span>
                                            </label>
                                        )}
                                        {!isFilterOnlyField && formData.show_on_upload && (
                                            <label className="flex items-center gap-2 cursor-pointer py-1">
                                                <StyledCheckbox
                                                    id="is_required"
                                                    name="is_required"
                                                    checked={formData.is_required}
                                                    onChange={(e) => setFormData({ ...formData, is_required: e.target.checked })}
                                                />
                                                <span className="text-sm text-gray-900">Required</span>
                                            </label>
                                        )}
                                    </div>
                                </fieldset>
                            </div>

                            {/* AI Suggestions — always show; explain when unavailable. Uses purple for AI branding. */}
                            {(() => {
                                const aiAvailable = requiresOptions && formData.options.length > 0 || isTagsField
                                const aiChecked = formData.ai_eligible
                                return (
                                    <div className="rounded-md border border-gray-200 bg-gray-50/50 px-3 py-2">
                                        <label className={`flex items-center gap-2 py-1 ${aiAvailable ? 'cursor-pointer' : 'cursor-default'}`}>
                                            <StyledCheckbox
                                                id="ai_eligible"
                                                name="ai_eligible"
                                                checked={aiChecked}
                                                onChange={(e) => setFormData({ ...formData, ai_eligible: e.target.checked })}
                                                disabled={!aiAvailable}
                                                variant="ai"
                                            />
                                            <SparklesIcon className={`h-4 w-4 flex-shrink-0 ${aiChecked ? 'text-purple-600' : aiAvailable ? 'text-purple-400' : 'text-gray-300'}`} />
                                            <span className={`text-sm ${aiAvailable ? 'text-gray-900' : 'text-gray-500'}`}>
                                                Enable AI Suggestions
                                            </span>
                                        </label>
                                        {!aiAvailable && (
                                            <p className="mt-0.5 text-xs text-gray-500 pl-6">
                                                {requiresOptions && formData.options.length === 0
                                                    ? 'Add options above to enable AI suggestions.'
                                                    : 'AI suggestions only work with Select or Multi-Select fields that have predefined options.'}
                                            </p>
                                        )}
                                    </div>
                                )
                            })()}
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
