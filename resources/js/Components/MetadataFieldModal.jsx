import { useState, useEffect, useRef, useMemo } from 'react'
import { createPortal } from 'react-dom'
import { usePage } from '@inertiajs/react'
import { arrayMove } from '@dnd-kit/sortable'
import {
    ArrowPathIcon,
    Bars3Icon,
    CheckIcon,
    ChevronDownIcon,
    CloudArrowUpIcon,
    ExclamationCircleIcon,
    EyeIcon,
    FunnelIcon,
    LockClosedIcon,
    SparklesIcon,
    StarIcon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline'

/** Custom checkbox: hidden native input + styled box with checkmark (brand accent in workbench). */
function StyledCheckbox({ id, name, checked, onChange, disabled, variant = 'default' }) {
    void variant
    const checkedClasses =
        'peer-checked:border-[color:var(--wb-accent)] peer-checked:bg-[color:var(--wb-accent)]'
    const focusClasses = 'peer-focus:ring-[color:color-mix(in_srgb,var(--wb-ring)_45%,transparent)]'
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

function LockAffix({ children }) {
    const hint = typeof children === 'string' ? children : ''
    return (
        <span className="inline-flex items-center gap-1 text-slate-400" title={hint || undefined}>
            <LockClosedIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
            {hint ? <span className="sr-only">{hint}</span> : null}
        </span>
    )
}

/** Decorative placeholder for “Where this appears” (illustration only). */
function WhereAppearMiniPreview({ variant }) {
    const wrap =
        'hidden h-[3.25rem] w-[4.5rem] shrink-0 rounded-md border border-slate-200/90 bg-gradient-to-b from-slate-50 to-white p-1 shadow-inner sm:block'
    if (variant === 'upload') {
        return (
            <div className={wrap} aria-hidden>
                <div className="space-y-1">
                    <div className="h-1 w-10 rounded bg-slate-200" />
                    <div className="flex items-center rounded border border-dashed border-slate-300 bg-white px-1 py-1">
                        <span className="h-1.5 flex-1 rounded bg-[color:color-mix(in_srgb,var(--wb-accent)_32%,#e2e8f0)]" />
                    </div>
                </div>
            </div>
        )
    }
    if (variant === 'details') {
        return (
            <div className={wrap} aria-hidden>
                <div className="space-y-1">
                    <div className="h-1 w-full rounded bg-slate-200" />
                    <div className="h-1 w-2/3 rounded bg-slate-200" />
                    <div className="mt-0.5 h-5 rounded border border-slate-200 bg-white" />
                </div>
            </div>
        )
    }
    if (variant === 'filters') {
        return (
            <div className={wrap} aria-hidden>
                <div className="flex h-full flex-col gap-0.5">
                    <div className="h-1 w-8 rounded bg-slate-300" />
                    <div className="flex flex-1 gap-0.5">
                        <div className="w-1 rounded bg-slate-200" />
                        <div className="flex flex-1 flex-col justify-center gap-0.5 pr-0.5">
                            <div className="h-1.5 rounded bg-[color:color-mix(in_srgb,var(--wb-accent)_28%,#e2e8f0)]" />
                            <div className="h-1.5 rounded bg-slate-100" />
                        </div>
                    </div>
                </div>
            </div>
        )
    }
    return null
}
import {
    toSnakeCase,
    validateSnakeCase,
    isDuplicateValue,
    mergeBulkAddTextIntoOptions,
    normalizeOptions,
    prepareOptionsForSubmit,
    snakeToTitleCase,
} from '../utils/optionEditorUtils'
import { getCustomDisplayLabel } from '../utils/widgetResolver'
import {
    canUseSidebarFilter,
    ineligibleFilterRowNote,
    sanitizeFilterFlagsForForm,
    whereAppearsFilterHint,
} from '../utils/metadataFilterEligibility'
import { useBrandWorkbenchChrome } from '../contexts/BrandWorkbenchChromeContext'
import { buildBrandWorkbenchChromePackage } from '../utils/brandWorkbenchTheme'
import { productButtonPrimary, productFocusInput } from '../components/brand-workspace/brandWorkspaceTokens'

const FIELD_TYPE_ONE_LINER = {
    text: 'Free text.',
    textarea: 'Longer free text.',
    select: 'One choice from a list.',
    multiselect: 'Multiple choices from a list.',
    number: 'Number value.',
    boolean: 'Yes / No.',
    date: 'Date value.',
}

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
    const pkgFromContext = useBrandWorkbenchChrome()
    const { auth, company } = usePage().props
    const workbenchChromeVars = useMemo(() => {
        if (pkgFromContext?.vars) return pkgFromContext.vars
        return buildBrandWorkbenchChromePackage(auth?.activeBrand, company).vars
    }, [pkgFromContext, auth?.activeBrand, company?.id, company?.primary_color])
    const [formData, setFormData] = useState({
        key: '',
        system_label: '',
        description: '',
        type: 'text',
        selectedCategories: [], // Array of category IDs
        options: [],
        ai_eligible: false,
        is_filterable: true,
        show_on_upload: true,
        show_on_edit: true,
        show_in_filters: false,
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
    const [filterTransitionNotice, setFilterTransitionNotice] = useState(null)
    /** Avoid wiping the "new field" form when parent re-renders (e.g. Inertia) while the modal stays open. */
    const wasModalOpenRef = useRef(false)

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
        .join(', ') || 'Select folders…'

    // Load field data when editing and initialize category selection
    useEffect(() => {
        if (!isOpen) {
            wasModalOpenRef.current = false
            setFilterTransitionNotice(null)
            return
        }
        const modalJustOpened = !wasModalOpenRef.current
        wasModalOpenRef.current = true

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
                            const loadedType = fieldData.field.type || 'text'
                            const rawShow = fieldData.field.show_in_filters !== false
                            const filterFlags = sanitizeFilterFlagsForForm(loadedType, rawShow, isPrimary)
                            setFormData({
                                key: fieldData.field.key || '',
                                system_label: fieldData.field.system_label || fieldData.field.label || '',
                                description: fieldData.field.description || '',
                                type: loadedType,
                                selectedCategories: enabledCategoryIds, // Use enabled categories, not category_ids
                                options: normalizeOptions(fieldData.field.options || []),
                                ai_eligible: fieldData.field.ai_eligible || false,
                                is_filterable: fieldData.field.is_filterable !== false,
                                show_on_upload: fieldData.field.show_on_upload !== false,
                                show_on_edit: fieldData.field.show_on_edit !== false,
                                show_in_filters: filterFlags.show_in_filters,
                                is_primary: filterFlags.is_primary,
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
                            const loadedType = fullFieldData.field_type || fullFieldData.type || field.field_type || field.type || 'text'
                            const rawShow = fullFieldData.show_in_filters !== false && field.show_in_filters !== false
                            const filterFlags = sanitizeFilterFlagsForForm(loadedType, rawShow, isPrimary)
                            setFormData({
                                key: field.key || '',
                                system_label: field.label || field.system_label || '',
                                description: fullFieldData.description || '',
                                type: loadedType,
                                selectedCategories: enabledCategoryIds,
                                options: normalizeOptions(fieldOptions),
                                ai_eligible: aiEligibleValue,
                                is_filterable: field.is_filterable !== false,
                                show_on_upload: field.show_on_upload !== false,
                                show_on_edit: field.show_on_edit !== false,
                                show_in_filters: filterFlags.show_in_filters,
                                is_primary: filterFlags.is_primary,
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
                            const fbType = field.field_type || field.type || 'text'
                            const fbFlags = sanitizeFilterFlagsForForm(fbType, field.show_in_filters !== false, false)
                            setFormData({
                                key: field.key || '',
                                system_label: field.label || field.system_label || '',
                                description: field.description || '',
                                type: fbType,
                                selectedCategories: [],
                                options: normalizeOptions(field.options || field.allowed_values || []),
                                ai_eligible: field.ai_eligible !== undefined ? field.ai_eligible : false,
                                is_filterable: field.is_filterable !== false,
                                show_on_upload: field.show_on_upload !== false,
                                show_on_edit: field.show_on_edit !== false,
                                show_in_filters: fbFlags.show_in_filters,
                                is_primary: fbFlags.is_primary,
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
                    const fbType = field.field_type || field.type || 'text'
                    const fbFlags = sanitizeFilterFlagsForForm(fbType, field.show_in_filters !== false, false)
                    setFormData({
                        key: field.key || '',
                        system_label: field.label || field.system_label || '',
                        description: field.description || '',
                        type: fbType,
                        selectedCategories: [],
                        options: normalizeOptions(field.options || field.allowed_values || []),
                        ai_eligible: field.ai_eligible !== undefined ? field.ai_eligible : false,
                        is_filterable: field.is_filterable !== false,
                        show_on_upload: field.show_on_upload !== false,
                        show_on_edit: field.show_on_edit !== false,
                        show_in_filters: fbFlags.show_in_filters,
                        is_primary: fbFlags.is_primary,
                        is_required: false,
                        option_editing_restricted: field.option_editing_restricted ?? false,
                        group_key: field.group_key || '',
                    })
                    setBulkAddText('')
                    setNewOption({ value: '', system_label: '' })
                    setLoadingField(false)
                }
            }
        } else if (isOpen && !field && modalJustOpened) {
            // Reset form for new field (only when opening — not on every categories/parent re-render)
            const initialCategories = preselectedCategoryId ? [preselectedCategoryId] : []
            setOriginalEnabledCategories([])
            setKeyManuallyEdited(false)
            setFormData({
                key: '',
                system_label: '',
                description: '',
                type: 'text',
                selectedCategories: initialCategories,
                options: [],
                ai_eligible: false,
                is_filterable: true,
                show_on_upload: true,
                show_on_edit: true,
                show_in_filters: false,
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
    }, [isOpen, field, categories, preselectedCategoryId])

    if (!isOpen) return null

    const isTagsField = formData.key === 'tags' || field?.key === 'tags'
    const requiresOptions = (formData.type === 'select' || formData.type === 'multiselect') && !isTagsField
    const filterEligible = canUseSidebarFilter(formData.type)
    const filterAppearsHint = whereAppearsFilterHint(formData.type)
    const ineligibleFilterNote = ineligibleFilterRowNote(formData.type)

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
                selectedCategories: 'Select at least one folder.',
                error: 'Please select at least one folder where this field should be enabled.'
            })
            setSubmitting(false)
            return
        }

        // Select/multiselect: merge any uncommitted bulk-add lines before validate/submit (same rules as "Add options")
        let effectiveOptions = formData.options
        if (requiresOptions && bulkAddText.trim()) {
            const merged = mergeBulkAddTextIntoOptions(formData.options, bulkAddText)
            if (merged.error) {
                setErrors({
                    options: merged.error,
                    error: merged.error,
                })
                setSubmitting(false)
                return
            }
            effectiveOptions = merged.options
        }

        // Validate options for select/multiselect
        if (requiresOptions) {
            const prepared = prepareOptionsForSubmit(effectiveOptions)
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

        const filterSubmitFlags = sanitizeFilterFlagsForForm(
            formData.type,
            formData.show_in_filters,
            formData.is_primary
        )
        const submitData = {
            ...formData,
            ...filterSubmitFlags,
            key: fieldKey,
            options: prepareOptionsForSubmit(requiresOptions ? effectiveOptions : formData.options),
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
                                show_in_filters: submitData.show_in_filters,
                                is_primary: submitData.is_primary,
                                is_required: formData.is_required,
                                category_id: categoryId,
                            }),
                        })
                    )
                    await Promise.all(visibilityPromises)
                }

                // System fields: persist select/multiselect options (global metadata_options rows)
                if (requiresOptions && !formData.option_editing_restricted) {
                    const opts = prepareOptionsForSubmit(effectiveOptions)
                    const optionsResponse = await fetch(`/app/tenant/metadata/fields/${field.id}`, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken || '',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ options: opts }),
                    })
                    if (!optionsResponse.ok) {
                        const errData = await optionsResponse.json().catch(() => ({}))
                        setErrors(errData.errors || { error: errData.error || 'Failed to save options' })
                        setSubmitting(false)
                        return
                    }
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
                                    show_in_filters: submitData.show_in_filters,
                                    is_primary: submitData.is_primary,
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
                // Create: JSON fetch (not Inertia) so validation/server errors keep the modal open and preserve form state
                const storeUrl = typeof route === 'function' ? route('tenant.metadata.fields.store') : '/app/tenant/metadata/fields'
                const response = await fetch(storeUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken || '',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(submitData),
                })
                let data = {}
                try {
                    data = await response.json()
                } catch {
                    data = {}
                }
                if (response.ok) {
                    onSuccess?.()
                    onClose()
                } else {
                    const rawErrors = data.errors && typeof data.errors === 'object' ? data.errors : {}
                    const bag = Object.fromEntries(
                        Object.entries(rawErrors).map(([k, v]) => [
                            k,
                            Array.isArray(v) ? (v[0] ?? '') : typeof v === 'string' ? v : String(v ?? ''),
                        ])
                    )
                    const firstFieldMessage =
                        (bag.key && String(bag.key)) ||
                        Object.entries(bag)
                            .filter(([k]) => k !== 'error')
                            .map(([, v]) => v)
                            .find(Boolean)
                    const summary =
                        firstFieldMessage ||
                        (typeof data.error === 'string' && data.error) ||
                        (typeof data.message === 'string' && data.message) ||
                        'Failed to create field'
                    setErrors({ ...bag, error: summary })
                }
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
        if (!bulkAddText.trim()) {
            setOptionError('Enter at least one value (one per line)')
            return
        }
        const { options: next, error } = mergeBulkAddTextIntoOptions(formData.options, bulkAddText)
        if (error) {
            setOptionError(error)
            return
        }
        setFormData({
            ...formData,
            options: next,
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
        <div
            className="brand-workbench-theme fixed inset-0 z-50 overflow-y-auto"
            style={workbenchChromeVars}
            aria-labelledby="modal-title"
            role="dialog"
            aria-modal="true"
        >
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                {/* Backdrop */}
                <div 
                    className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    onClick={onClose}
                />

                {/* Modal */}
                <div className="relative flex min-h-0 max-h-[min(90vh,100dvh-2rem)] w-full transform flex-col overflow-hidden rounded-xl bg-white text-left shadow-xl transition-all sm:my-8 sm:max-w-4xl">
                    <div className="flex flex-shrink-0 flex-col gap-1 border-b border-slate-200 px-4 py-3 sm:px-5">
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2 sm:gap-3">
                                <h2 id="modal-title" className="truncate text-base font-semibold text-slate-900">
                                    {isEditing ? 'Edit field' : 'Create field'}
                                </h2>
                                {!isEditing && customFieldsLimit && customFieldsLimit.max > 0 && (
                                    <span
                                        className={`flex-shrink-0 rounded-full px-2 py-0.5 text-xs ${
                                            customFieldsLimit.can_create
                                                ? 'bg-[color:color-mix(in_srgb,var(--wb-accent)_12%,white)] text-slate-800'
                                                : 'bg-red-50 text-red-700'
                                        }`}
                                    >
                                        {customFieldsLimit.current}/{customFieldsLimit.max}
                                    </span>
                                )}
                            </div>
                            <button
                                type="button"
                                onClick={onClose}
                                className="flex-shrink-0 rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                            >
                                <span className="sr-only">Close</span>
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <p className="text-left text-xs leading-snug text-slate-600">
                            Visibility controls where this field appears for assets.
                        </p>
                    </div>

                    <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
                        <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5 sm:py-5">
                        {errors.error && (
                            <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">
                                {errors.error}
                            </div>
                        )}

                        <div className="space-y-6">
                            <section aria-labelledby="modal-field-basics-heading">
                                <h3
                                    id="modal-field-basics-heading"
                                    className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500"
                                >
                                    Field basics
                                </h3>
                                <div className="grid grid-cols-1 gap-5">
                                    <div className="min-w-0 space-y-4">
                                        <div>
                                            <label
                                                htmlFor="system_label"
                                                className="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-700"
                                            >
                                                <span>
                                                    Display Name <span className="text-red-500">*</span>
                                                </span>
                                                {isEditing && isSystemField ? (
                                                    <LockAffix>Preset field — display name is not edited here.</LockAffix>
                                                ) : null}
                                            </label>
                                            <input
                                                type="text"
                                                id="system_label"
                                                required
                                                value={formData.system_label}
                                                disabled={isEditing && isSystemField}
                                                onChange={(e) => {
                                                    const label = e.target.value
                                                    setFormData((prev) => ({
                                                        ...prev,
                                                        system_label: label,
                                                        ...(!isEditing && !keyManuallyEdited && { key: toSnakeCase(label) }),
                                                    }))
                                                }}
                                                className={`block w-full rounded-md border border-slate-300 py-1.5 px-2.5 text-sm placeholder-slate-400 focus:ring-1 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-50 disabled:text-slate-600 ${productFocusInput}`}
                                                placeholder="e.g. Video Type"
                                            />
                                            {!(isEditing && isSystemField) ? (
                                                <p className="mt-1 text-xs text-slate-500">Shown to users in the library.</p>
                                            ) : null}
                                            {errors.system_label && (
                                                <p className="mt-1 text-xs text-red-600">{errors.system_label}</p>
                                            )}
                                        </div>
                                        <div>
                                            <label
                                                htmlFor="field_description"
                                                className="mb-1 block text-xs font-medium text-slate-700"
                                            >
                                                Helper text <span className="font-normal text-slate-500">(optional)</span>
                                            </label>
                                            <textarea
                                                id="field_description"
                                                rows={2}
                                                maxLength={2000}
                                                value={formData.description}
                                                onChange={(e) =>
                                                    setFormData((prev) => ({ ...prev, description: e.target.value }))
                                                }
                                                disabled={isSystemField}
                                                className={`block w-full rounded-md border border-slate-300 py-1.5 px-2.5 text-sm placeholder-slate-400 focus:ring-1 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-50 disabled:text-slate-600 ${productFocusInput}`}
                                                placeholder="Short line shown under the field in upload and asset details"
                                            />
                                            <p className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                                                {isSystemField ? (
                                                    <>
                                                        <LockAffix>Helper text is fixed for this preset field.</LockAffix>
                                                        <span>Contact support to change this text.</span>
                                                    </>
                                                ) : (
                                                    <span>One short sentence is enough.</span>
                                                )}
                                            </p>
                                            {errors.description && (
                                                <p className="mt-1 text-xs text-red-600">{errors.description}</p>
                                            )}
                                        </div>
                                        <div>
                                            <label
                                                htmlFor="key"
                                                className="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-700"
                                            >
                                                <span>
                                                    Field Key {!isEditing && <span className="text-red-500">*</span>}
                                                </span>
                                                {isEditing ? <LockAffix>Field key cannot be changed after creation.</LockAffix> : null}
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
                                                className={`block w-full rounded-md border border-slate-300 py-1.5 px-2.5 text-sm placeholder-slate-400 focus:ring-1 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-50 disabled:text-slate-600 ${productFocusInput}`}
                                                placeholder={isEditing ? '—' : 'my_field'}
                                            />
                                            {!isEditing && (
                                                <p className="mt-1 text-xs text-slate-500">
                                                    Used internally for imports, automation, and APIs. Saved internally as{' '}
                                                    <span className="font-mono text-slate-600">custom__</span>
                                                    {' '}
                                                    plus your key (e.g.{' '}
                                                    <span className="font-mono text-slate-600">custom__video_type</span>).
                                                </p>
                                            )}
                                            {errors.key && <p className="mt-1 text-xs text-red-600">{errors.key}</p>}
                                        </div>
                                        <div>
                                            <span className="mb-2 flex flex-wrap items-center gap-1.5 text-xs font-medium text-slate-700">
                                                <span>
                                                    Field Type <span className="text-red-500">*</span>
                                                </span>
                                                {isEditing ? <LockAffix>Field type cannot be changed after creation.</LockAffix> : null}
                                            </span>
                                            {!isEditing ? (
                                                <p className="mb-2 text-xs text-slate-500">Pick how values are stored.</p>
                                            ) : null}
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
                                                        onClick={() => {
                                                            let lostFilterEligibility = false
                                                            setFormData((prev) => {
                                                                const wasEligible = canUseSidebarFilter(prev.type)
                                                                const nowEligible = canUseSidebarFilter(value)
                                                                lostFilterEligibility = wasEligible && !nowEligible
                                                                const next = {
                                                                    ...prev,
                                                                    type: value,
                                                                    options: ['select', 'multiselect'].includes(value)
                                                                        ? []
                                                                        : prev.options,
                                                                }
                                                                if (lostFilterEligibility) {
                                                                    next.show_in_filters = false
                                                                    next.is_primary = false
                                                                }
                                                                return next
                                                            })
                                                            setTimeout(() => {
                                                                setFilterTransitionNotice(
                                                                    lostFilterEligibility
                                                                        ? (() => {
                                                                              const nt = String(value || '').toLowerCase()
                                                                              if (nt === 'number') {
                                                                                  return 'Show in Filters and Primary Filter were turned off because number fields are not sidebar filters yet.'
                                                                              }
                                                                              if (nt === 'text' || nt === 'textarea') {
                                                                                  return 'Show in Filters and Primary Filter were turned off because text fields use search instead of sidebar filters.'
                                                                              }
                                                                              return 'Show in Filters and Primary Filter were turned off for this field type.'
                                                                          })()
                                                                        : null
                                                                )
                                                            }, 0)
                                                        }}
                                                        className={`rounded-md px-3 py-1.5 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-[color:var(--wb-ring)] focus:ring-offset-1 disabled:cursor-not-allowed ${
                                                            formData.type === value
                                                                ? `bg-[var(--wb-accent)] text-[var(--wb-on-accent)] ${
                                                                      isEditing ? 'opacity-100' : ''
                                                                  }`
                                                                : `bg-slate-100 text-slate-700 hover:bg-slate-200 ${
                                                                      isEditing ? 'opacity-55' : ''
                                                                  }`
                                                        }`}
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                                {isEditing &&
                                                    (() => {
                                                        const fieldForDisplay = {
                                                            ...field,
                                                            type: formData.type,
                                                            display_widget: field?.display_widget,
                                                        }
                                                        const customLabel = getCustomDisplayLabel(fieldForDisplay)
                                                        return customLabel ? (
                                                            <span className="inline-flex items-center gap-1 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800">
                                                                Custom display: {customLabel}
                                                            </span>
                                                        ) : null
                                                    })()}
                                            </div>
                                            <p className="mt-2 text-xs text-slate-600">
                                                {FIELD_TYPE_ONE_LINER[formData.type] || FIELD_TYPE_ONE_LINER.text}
                                            </p>
                                            {errors.type && <p className="mt-1 text-xs text-red-600">{errors.type}</p>}
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section
                                className="rounded-lg border border-slate-100 bg-slate-50/40 px-3 py-4 sm:px-4"
                                aria-labelledby="modal-values-heading"
                            >
                                <h3
                                    id="modal-values-heading"
                                    className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500"
                                >
                                    {requiresOptions ? 'Values' : 'Field behavior by type'}
                                </h3>
                                {requiresOptions ? (
                                    <>
                                        <p className="text-xs text-slate-600">Choices users can pick from.</p>
                                        <div className="mt-3">
                                            {formData.option_editing_restricted && (
                                                <p className="mb-2 flex flex-wrap items-center gap-1.5 text-xs text-slate-600">
                                                    <LockAffix>Preset display — options are fixed.</LockAffix>
                                                    <span>Options are not edited here.</span>
                                                </p>
                                            )}
                                            {!formData.option_editing_restricted && (
                                                <div className="rounded-md border border-slate-200 bg-white p-3 shadow-sm">
                                                    <p className="mb-2 text-xs text-slate-600">
                                                        {formData.type === 'multiselect' ? 'Multi-select' : 'Single select'}{' '}
                                                        — one value per line, or add rows below.
                                                    </p>
                                                    <div className="flex flex-wrap items-end gap-2">
                                                        <textarea
                                                            value={bulkAddText}
                                                            onChange={(e) => {
                                                                setBulkAddText(e.target.value)
                                                                setOptionError(null)
                                                            }}
                                                            placeholder={'high_quality\nmedium_quality\nlow_quality'}
                                                            rows={2}
                                                            className={`min-w-[120px] flex-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm placeholder-slate-400 focus:ring-1 ${productFocusInput}`}
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={processBulkAdd}
                                                            disabled={!bulkAddText.trim()}
                                                            className={`shrink-0 rounded-md px-2.5 py-1.5 text-xs font-medium ${productButtonPrimary} disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500 disabled:shadow-none disabled:hover:bg-slate-200 disabled:hover:text-slate-500`}
                                                        >
                                                            Add options
                                                        </button>
                                                        {optionError && (
                                                            <span className="w-full text-xs text-red-600">{optionError}</span>
                                                        )}
                                                    </div>
                                                    <p className="mt-1.5 text-xs text-slate-500">
                                                        Lines in the box are also applied when you save.
                                                    </p>
                                                    <div className="mt-3">
                                                        <div className="overflow-hidden rounded border border-slate-200 bg-white">
                                                            {formData.options.length === 0 ? (
                                                                <div className="px-3 py-4 text-center text-xs text-slate-500">
                                                                    No options yet
                                                                </div>
                                                            ) : (
                                                                <div
                                                                    className={
                                                                        formData.options.length > 8
                                                                            ? 'max-h-64 overflow-y-auto overflow-x-auto'
                                                                            : 'overflow-x-auto'
                                                                    }
                                                                >
                                                                    <table className="min-w-full divide-y divide-slate-200">
                                                                        <thead className="bg-slate-50">
                                                                            <tr>
                                                                                <th scope="col" className="w-9 px-2 py-2" />
                                                                                <th
                                                                                    scope="col"
                                                                                    className="w-40 px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500"
                                                                                >
                                                                                    Value
                                                                                </th>
                                                                                <th
                                                                                    scope="col"
                                                                                    className="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500"
                                                                                >
                                                                                    Label
                                                                                </th>
                                                                                <th scope="col" className="w-10 px-2 py-2" />
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody className="divide-y divide-slate-200 bg-white">
                                                                            {formData.options.map((option, index) => (
                                                                                <tr
                                                                                    key={`${option.value}-${index}`}
                                                                                    className="transition-colors hover:bg-slate-50"
                                                                                >
                                                                                    <td className="px-2 py-1.5">
                                                                                        <div className="flex cursor-grab items-center text-slate-400 active:cursor-grabbing">
                                                                                            <Bars3Icon
                                                                                                className="h-4 w-4"
                                                                                                aria-hidden
                                                                                            />
                                                                                        </div>
                                                                                    </td>
                                                                                    <td className="px-3 py-1.5">
                                                                                        <span className="font-mono text-sm text-slate-700">
                                                                                            {option.value}
                                                                                        </span>
                                                                                    </td>
                                                                                    <td className="px-3 py-1.5">
                                                                                        <span className="text-sm text-slate-900">
                                                                                            {option.system_label ||
                                                                                                option.label ||
                                                                                                option.value}
                                                                                        </span>
                                                                                    </td>
                                                                                    <td className="px-2 py-1.5">
                                                                                        <button
                                                                                            type="button"
                                                                                            onClick={() => removeOption(index)}
                                                                                            className="rounded p-1 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                                                                                            title="Remove"
                                                                                        >
                                                                                            <TrashIcon className="h-4 w-4" />
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
                                                    <div className="mt-2 flex flex-wrap items-center gap-2">
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
                                                            className={`block w-32 rounded-md border border-slate-300 px-2 py-1 text-xs placeholder-slate-400 focus:ring-1 ${productFocusInput}`}
                                                        />
                                                        <input
                                                            type="text"
                                                            value={newOption.system_label}
                                                            onChange={(e) => {
                                                                setNewOption((prev) => ({
                                                                    ...prev,
                                                                    system_label: e.target.value,
                                                                }))
                                                                setOptionError(null)
                                                            }}
                                                            placeholder="Label"
                                                            className={`block min-w-[80px] flex-1 rounded-md border border-slate-300 px-2 py-1 text-xs placeholder-slate-400 focus:ring-1 ${productFocusInput}`}
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={addOption}
                                                            disabled={
                                                                !newOption.value.trim() || !newOption.system_label.trim()
                                                            }
                                                            className="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            Add one
                                                        </button>
                                                    </div>
                                                    {errors.options && (
                                                        <p className="mt-2 text-sm text-red-600">{errors.options}</p>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </>
                                ) : (
                                    <div className="space-y-2 text-xs leading-relaxed text-slate-600">
                                        {formData.type === 'boolean' && (
                                            <p>Boolean fields create a Yes / No style choice.</p>
                                        )}
                                        {(formData.type === 'text' || formData.type === 'textarea') && (
                                            <>
                                                <p>
                                                    Text fields are best for notes, descriptions, or searchable details.
                                                </p>
                                                <p className="text-slate-500">
                                                    Text fields are usually found through search instead of sidebar
                                                    filters.
                                                </p>
                                            </>
                                        )}
                                        {formData.type === 'date' && (
                                            <p>
                                                Date fields are useful for expiration dates, publish dates, or review
                                                dates.
                                            </p>
                                        )}
                                        {formData.type === 'number' && (
                                            <p>Number fields are useful for ratings, quantities, or scores.</p>
                                        )}
                                    </div>
                                )}
                            </section>

                            <section aria-labelledby="modal-folders-heading">
                                <h3
                                    id="modal-folders-heading"
                                    className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500"
                                >
                                    Used in folders{' '}
                                    <span className="text-red-500" aria-hidden>
                                        *
                                    </span>
                                </h3>
                                <p className="mb-2 text-xs text-slate-600">Choose which folders should use this field.</p>
                                {!isEditing && preselectedCategoryId && (
                                    <p className="mb-2 text-xs font-medium text-slate-700">
                                        Pre-selected from the folder you are editing.
                                    </p>
                                )}
                                <div className="relative" ref={categoryDropdownRef}>
                                        <button
                                            type="button"
                                            id="modal-folder-multiselect-trigger"
                                            aria-haspopup="true"
                                            aria-expanded={isCategoryDropdownOpen}
                                            aria-controls="modal-folder-multiselect-listbox"
                                            onClick={() => setIsCategoryDropdownOpen(!isCategoryDropdownOpen)}
                                            className={`relative w-full cursor-default rounded-md border border-gray-300 bg-white py-1.5 pl-2.5 pr-8 text-left text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-[color:var(--wb-ring)] focus:ring-offset-0 ${productFocusInput}`}
                                        >
                                            <span className="block truncate">
                                                {formData.selectedCategories.length > 0 
                                                    ? `${formData.selectedCategories.length} folder${formData.selectedCategories.length === 1 ? '' : 's'} selected`
                                                    : 'Select folders…'}
                                            </span>
                                            <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                                <ChevronDownIcon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                                            </span>
                                        </button>

                                        {/* Dropdown Menu */}
                                        {isCategoryDropdownOpen && (
                                            <div
                                                id="modal-folder-multiselect-listbox"
                                                className="absolute z-10 mt-1 max-h-96 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm"
                                            >
                                                {/* Asset Categories */}
                                                {groupedCategories.asset.length > 0 && (
                                                    <div className="px-2 py-1">
                                                        <div className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1 px-2">
                                                            Asset folders
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
                                                                            className="h-4 w-4 rounded border-gray-300 text-[color:var(--wb-accent)] focus:ring-[color:var(--wb-ring)]"
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
                                                                        <span className="absolute inset-y-0 right-0 flex items-center pr-4 text-[color:var(--wb-accent)]">
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
                                                            Execution folders
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
                                                                            className="h-4 w-4 rounded border-gray-300 text-[color:var(--wb-accent)] focus:ring-[color:var(--wb-ring)]"
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
                                                                        <span className="absolute inset-y-0 right-0 flex items-center pr-4 text-[color:var(--wb-accent)]">
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
                                                        No folders available
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
                                                        className="inline-flex items-center gap-1 rounded-md bg-[color:color-mix(in_srgb,var(--wb-accent)_10%,white)] px-2 py-1 text-xs font-medium text-slate-800"
                                                    >
                                                        {category.name}
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleCategorySelection(category.id)}
                                                            className="text-[color:var(--wb-accent)] hover:opacity-80"
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
                                    <p className="mt-1 text-xs text-amber-600">Select at least one folder</p>
                                )}
                                {errors.selectedCategories && <p className="mt-1 text-xs text-red-600">{errors.selectedCategories}</p>}
                            </section>


                            <section aria-labelledby="modal-where-heading">
                                <h3
                                    id="modal-where-heading"
                                    className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500"
                                >
                                    Where this appears
                                </h3>
                                <p className="mb-3 text-xs text-slate-600">Toggle where this field shows up.</p>
                                <fieldset className="space-y-2">
                                    <legend className="sr-only">Visibility and behavior</legend>
                                    {!isFilterOnlyField && (
                                        <label className="flex cursor-pointer gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition hover:border-[color:color-mix(in_srgb,var(--wb-accent)_22%,#e2e8f0)]">
                                            <div className="pt-0.5">
                                                <StyledCheckbox
                                                    id="show_on_upload"
                                                    name="show_on_upload"
                                                    checked={formData.show_on_upload}
                                                    onChange={(e) =>
                                                        setFormData({ ...formData, show_on_upload: e.target.checked })
                                                    }
                                                />
                                            </div>
                                            <div className="flex min-w-0 flex-1 items-center gap-3">
                                                <div className="min-w-0 flex-1">
                                                    <span className="flex items-center gap-2 text-sm font-medium text-slate-900">
                                                        <CloudArrowUpIcon
                                                            className="h-4 w-4 shrink-0 text-[color:var(--wb-accent)]"
                                                            aria-hidden
                                                        />
                                                        Show on Upload
                                                    </span>
                                                    <p className="mt-0.5 text-xs text-slate-600">Shown on upload.</p>
                                                </div>
                                                <WhereAppearMiniPreview variant="upload" />
                                            </div>
                                        </label>
                                    )}
                                    {!isFilterOnlyField && (
                                        <label className="flex cursor-pointer gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition hover:border-[color:color-mix(in_srgb,var(--wb-accent)_22%,#e2e8f0)]">
                                            <div className="pt-0.5">
                                                <StyledCheckbox
                                                    id="show_on_edit"
                                                    name="show_on_edit"
                                                    checked={formData.show_on_edit}
                                                    onChange={(e) =>
                                                        setFormData({ ...formData, show_on_edit: e.target.checked })
                                                    }
                                                />
                                            </div>
                                            <div className="flex min-w-0 flex-1 items-center gap-3">
                                                <div className="min-w-0 flex-1">
                                                    <span className="flex items-center gap-2 text-sm font-medium text-slate-900">
                                                        <EyeIcon
                                                            className="h-4 w-4 shrink-0 text-[color:var(--wb-accent)]"
                                                            aria-hidden
                                                        />
                                                        Quick View / Asset Details
                                                    </span>
                                                    <p className="mt-0.5 text-xs text-slate-600">Shown in asset details.</p>
                                                </div>
                                                <WhereAppearMiniPreview variant="details" />
                                            </div>
                                        </label>
                                    )}
                                    <label
                                        className={`flex gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition ${
                                            filterEligible
                                                ? 'cursor-pointer hover:border-[color:color-mix(in_srgb,var(--wb-accent)_22%,#e2e8f0)]'
                                                : 'cursor-not-allowed opacity-80'
                                        }`}
                                    >
                                        <div className="pt-0.5">
                                            <StyledCheckbox
                                                id="show_in_filters"
                                                name="show_in_filters"
                                                checked={formData.show_in_filters}
                                                disabled={!filterEligible}
                                                aria-describedby={
                                                    !filterEligible && ineligibleFilterNote
                                                        ? 'filter-ineligible-reason'
                                                        : undefined
                                                }
                                                onChange={(e) => {
                                                    const checked = e.target.checked
                                                    setFormData({
                                                        ...formData,
                                                        show_in_filters: checked,
                                                        is_primary: checked ? formData.is_primary : false,
                                                    })
                                                }}
                                            />
                                        </div>
                                        <div className="flex min-w-0 flex-1 items-center gap-3">
                                            <div className="min-w-0 flex-1">
                                                <span className="flex items-center gap-2 text-sm font-medium text-slate-900">
                                                    <FunnelIcon
                                                        className="h-4 w-4 shrink-0 text-[color:var(--wb-accent)]"
                                                        aria-hidden
                                                    />
                                                    Show in Filters
                                                </span>
                                                <p className="mt-0.5 text-xs text-slate-600">Shown in library filters.</p>
                                                {!filterEligible && ineligibleFilterNote ? (
                                                    <p id="filter-ineligible-reason" className="mt-1.5 text-xs text-slate-500">
                                                        {ineligibleFilterNote}
                                                    </p>
                                                ) : null}
                                            </div>
                                            <WhereAppearMiniPreview variant="filters" />
                                        </div>
                                    </label>
                                    {!isFilterOnlyField && formData.show_in_filters && filterEligible && (
                                        <label className="flex cursor-pointer gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition hover:border-[color:color-mix(in_srgb,var(--wb-accent)_22%,#e2e8f0)]">
                                            <div className="pt-0.5">
                                                <StyledCheckbox
                                                    id="is_primary"
                                                    name="is_primary"
                                                    checked={formData.is_primary}
                                                    onChange={(e) => {
                                                        const checked = e.target.checked
                                                        setFormData({
                                                            ...formData,
                                                            is_primary: checked,
                                                            show_in_filters: checked ? true : formData.show_in_filters,
                                                        })
                                                    }}
                                                />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <span className="flex items-center gap-2 text-sm font-medium text-slate-900">
                                                    <StarIcon
                                                        className="h-4 w-4 shrink-0 text-[color:var(--wb-accent)]"
                                                        aria-hidden
                                                    />
                                                    Primary Filter
                                                </span>
                                                <p className="mt-0.5 text-xs text-slate-600">
                                                    Top-level filter when filters are on.
                                                </p>
                                            </div>
                                        </label>
                                    )}
                                    {!isFilterOnlyField && formData.show_on_upload && (
                                        <label className="flex cursor-pointer gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition hover:border-[color:color-mix(in_srgb,var(--wb-accent)_22%,#e2e8f0)]">
                                            <div className="pt-0.5">
                                                <StyledCheckbox
                                                    id="is_required"
                                                    name="is_required"
                                                    checked={formData.is_required}
                                                    onChange={(e) =>
                                                        setFormData({ ...formData, is_required: e.target.checked })
                                                    }
                                                />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <span className="flex items-center gap-2 text-sm font-medium text-slate-900">
                                                    <ExclamationCircleIcon
                                                        className="h-4 w-4 shrink-0 text-[color:var(--wb-accent)]"
                                                        aria-hidden
                                                    />
                                                    Required
                                                </span>
                                                <p className="mt-0.5 text-xs text-slate-600">Required when shown on upload.</p>
                                            </div>
                                        </label>
                                    )}
                                </fieldset>
                                {filterTransitionNotice ? (
                                    <p
                                        className="mt-3 rounded-md border border-[color:color-mix(in_srgb,var(--wb-accent)_20%,#e2e8f0)] bg-[color:color-mix(in_srgb,var(--wb-accent)_8%,white)] px-3 py-2 text-xs text-slate-800"
                                        role="status"
                                    >
                                        {filterTransitionNotice}
                                    </p>
                                ) : null}
                                {filterAppearsHint ? (
                                    <p className="mt-3 text-xs text-slate-500">{filterAppearsHint}</p>
                                ) : null}
                                <div className="mt-5 border-t border-slate-100 pt-4">
                                    <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        AI suggestions
                                    </h4>
                                    {(() => {
                                        const aiAvailable =
                                            (requiresOptions && formData.options.length > 0) || isTagsField
                                        const aiChecked = formData.ai_eligible
                                        return (
                                            <div className="rounded-lg border border-[color:color-mix(in_srgb,var(--wb-accent)_18%,#e2e8f0)] bg-[color:color-mix(in_srgb,var(--wb-accent)_5%,white)] px-3 py-3">
                                                <label
                                                    className={`flex items-start gap-3 ${aiAvailable ? 'cursor-pointer' : 'cursor-default'}`}
                                                >
                                                    <div className="pt-0.5">
                                                        <StyledCheckbox
                                                            id="ai_eligible"
                                                            name="ai_eligible"
                                                            checked={aiChecked}
                                                            onChange={(e) =>
                                                                setFormData({
                                                                    ...formData,
                                                                    ai_eligible: e.target.checked,
                                                                })
                                                            }
                                                            disabled={!aiAvailable}
                                                            variant="ai"
                                                        />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <span className="flex items-center gap-2 text-sm font-medium text-slate-900">
                                                            <SparklesIcon
                                                                className={`h-4 w-4 shrink-0 ${aiChecked || aiAvailable ? 'text-[color:var(--wb-accent)]' : 'text-slate-300'}`}
                                                                aria-hidden
                                                            />
                                                            Allow AI suggestions for this field
                                                        </span>
                                                        {aiAvailable ? (
                                                            <p className="mt-1 text-xs text-slate-600">
                                                                Suggested values after assets are analyzed.
                                                            </p>
                                                        ) : (
                                                            <p className="mt-1 text-xs text-slate-600">
                                                                For select-style fields with values.
                                                                {requiresOptions && formData.options.length === 0
                                                                    ? ' Add values above to enable.'
                                                                    : ''}
                                                            </p>
                                                        )}
                                                    </div>
                                                </label>
                                            </div>
                                        )
                                    })()}
                                </div>
                            </section>
                        </div>
                        </div>

                        <div className="flex flex-shrink-0 items-center justify-end gap-3 border-t border-slate-200 bg-white px-4 py-3 sm:px-5">
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
                                title={
                                    !submitting &&
                                    !isEditing &&
                                    customFieldsLimit &&
                                    !customFieldsLimit.can_create
                                        ? `Plan limit reached (${customFieldsLimit.current}/${customFieldsLimit.max} custom fields).`
                                        : undefined
                                }
                                className={`inline-flex items-center gap-2 font-semibold ${productButtonPrimary} disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500 disabled:shadow-none disabled:hover:bg-slate-200 disabled:hover:text-slate-500`}
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
