import { useState, useEffect, useMemo, useCallback, useRef, Fragment } from 'react'
import { router, Link } from '@inertiajs/react'
import { PlusIcon } from '@heroicons/react/24/outline'
import ConfirmDialog from '../ConfirmDialog'
import MetadataFieldModal from '../MetadataFieldModal'

const CORE_FIELD_KEYS = ['collection', 'tags']

const MANAGE_CATEGORIES_URL =
    typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'

function getCsrfToken() {
    if (typeof document === 'undefined') return ''
    return document.querySelector('meta[name="csrf-token"]')?.content || ''
}

/**
 * Table title for Manage → Fields. Only the category's canonical primary type field
 * (type_field.field_key, e.g. photo_type) should read as "Type". Other keys ending in
 * _type (environment_type, subject_type, execution_video_type, …) must use their
 * system labels so they do not all collapse to duplicate "Type" rows.
 */
function fieldTitle(field, primaryTypeFieldKey = null) {
    const k = field.key ? String(field.key) : ''
    if (k.endsWith('_type')) {
        if (primaryTypeFieldKey && k === primaryTypeFieldKey) {
            return 'Type'
        }
        return field.label || field.system_label || k.replace(/_type$/, '').replace(/_/g, ' ') || 'Field'
    }
    return field.label || field.system_label || k || 'Unnamed Field'
}

function isAutomatedField(field) {
    return field.population_mode === 'automatic' && field.readonly === true
}

function isFilterOnlyField(field) {
    return (field.key ?? '') === 'dominant_hue_group'
}

function resolveEffective(field, categoryId, data) {
    const override = categoryId && data?.overrides?.[categoryId]
    const upload =
        override?.show_on_upload !== undefined
            ? override.show_on_upload
            : (field.effective_show_on_upload ?? field.show_on_upload ?? true)
    const edit =
        override?.show_on_edit !== undefined
            ? override.show_on_edit
            : (field.effective_show_on_edit ?? field.show_on_edit ?? true)
    const filter =
        override?.show_in_filters !== undefined
            ? override.show_in_filters
            : (field.effective_show_in_filters ?? field.show_in_filters ?? true)
    let primary = field.is_primary ?? false
    if (categoryId && data?.overrides?.[categoryId]?.is_primary !== undefined && data.overrides[categoryId].is_primary !== null) {
        primary = data.overrides[categoryId].is_primary
    }
    let required = field.is_required ?? false
    if (categoryId && data?.overrides?.[categoryId]?.is_required !== undefined && data.overrides[categoryId].is_required !== null) {
        required = data.overrides[categoryId].is_required
    }
    return { upload, edit, filter, primary, required, aiEligible: field.ai_eligible ?? false }
}

function coveragePercent(field, categoryId, data, automated) {
    const { upload, edit, filter } = resolveEffective(field, categoryId, data)
    if (isFilterOnlyField(field)) {
        return filter ? 100 : 0
    }
    if (automated) {
        const on = (edit ? 1 : 0) + (filter ? 1 : 0)
        return Math.round((on / 2) * 100)
    }
    const on = (upload ? 1 : 0) + (edit ? 1 : 0) + (filter ? 1 : 0)
    return Math.round((on / 3) * 100)
}

export default function ManageFieldsWorkspace({
    brand,
    categories = [],
    registry,
    fieldFilter = null,
    lowCoverageFieldKeys = [],
    canManageVisibility = true,
    canManageBrandCategories = false,
    canManageFields = false,
    customFieldsLimit = null,
    metadataFieldFamilies = {},
    selectedCategoryId = null,
    onSaveNotice,
}) {
    const { system_fields: systemFields = [], tenant_fields: tenantFields = [] } = registry || {}
    const allFields = useMemo(() => [...systemFields, ...tenantFields], [systemFields, tenantFields])

    const { manageableFields, automatedFields } = useMemo(() => {
        const manageable = []
        const automated = []
        allFields.forEach((field) => {
            if (isAutomatedField(field)) automated.push(field)
            else manageable.push(field)
        })
        return { manageableFields: manageable, automatedFields: automated }
    }, [allFields])

    const typeFamilyFieldKeys = useMemo(() => {
        const tf = metadataFieldFamilies?.type
        if (!tf || !Array.isArray(tf.fields)) return []
        return tf.fields
    }, [metadataFieldFamilies])

    const [fieldCategoryData, setFieldCategoryData] = useState({})
    const [expandedDetailKey, setExpandedDetailKey] = useState(null)
    const [showAutomatedFields, setShowAutomatedFields] = useState(false)
    const [modalOpen, setModalOpen] = useState(false)
    const [editingField, setEditingField] = useState(null)
    const [successMessage, setSuccessMessage] = useState(null)
    const [confirmDisableCoreOpen, setConfirmDisableCoreOpen] = useState(false)
    const [pendingDisable, setPendingDisable] = useState(null)
    const [ebiToggleLoading, setEbiToggleLoading] = useState(false)

    const noticeTimerRef = useRef(null)
    useEffect(() => {
        return () => {
            if (noticeTimerRef.current) {
                clearTimeout(noticeTimerRef.current)
                noticeTimerRef.current = null
            }
        }
    }, [])

    const postNotice = useCallback(
        (text, variant = 'success', durationMs) => {
            const dur = durationMs ?? (variant === 'error' ? 6000 : 4000)
            if (typeof onSaveNotice === 'function') {
                onSaveNotice(text, { variant, durationMs: dur })
                return
            }
            setSuccessMessage(text)
            if (noticeTimerRef.current) clearTimeout(noticeTimerRef.current)
            noticeTimerRef.current = setTimeout(() => {
                setSuccessMessage(null)
                noticeTimerRef.current = null
            }, dur)
        },
        [onSaveNotice]
    )

    const fieldCategoryDataRef = useRef(fieldCategoryData)
    useEffect(() => {
        fieldCategoryDataRef.current = fieldCategoryData
    }, [fieldCategoryData])

    const selectedCategory = useMemo(
        () => categories.find((c) => c.id === selectedCategoryId) ?? null,
        [categories, selectedCategoryId]
    )

    const canToggleEbi =
        (canManageVisibility || canManageBrandCategories) && !!brand?.id && !!selectedCategory

    const toggleEbiEnabled = useCallback(async () => {
        if (!canToggleEbi || !selectedCategory) return
        const brandId = brand.id
        const current = selectedCategory.ebi_enabled === true
        const newValue = !current
        setEbiToggleLoading(true)
        try {
            const url =
                typeof route === 'function'
                    ? route('brands.categories.ebi-enabled', { brand: brandId, category: selectedCategory.id })
                    : `/app/api/brands/${brandId}/categories/${selectedCategory.id}/ebi-enabled`
            const response = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ ebi_enabled: newValue }),
            })
            if (response.ok) {
                postNotice(`Brand Intelligence ${newValue ? 'enabled' : 'disabled'} for this folder.`)
                router.reload({ only: ['categories'] })
            } else {
                const errorData = await response.json().catch(() => ({}))
                postNotice(
                    errorData.message || errorData.error || 'Failed to update Brand Intelligence setting.',
                    'error',
                    4000
                )
            }
        } catch (error) {
            console.error('Failed to toggle ebi_enabled:', error)
            postNotice('Failed to update Brand Intelligence setting.', 'error')
        } finally {
            setEbiToggleLoading(false)
        }
    }, [brand?.id, canToggleEbi, selectedCategory, postNotice])

    useEffect(() => {
        setExpandedDetailKey(null)
    }, [selectedCategoryId])

    const loadFieldCategoryData = useCallback(async (field, forceRefetch = false) => {
        if (!forceRefetch && fieldCategoryDataRef.current[field.id]) {
            return fieldCategoryDataRef.current[field.id]
        }
        try {
            const response = await fetch(`/app/api/tenant/metadata/fields/${field.id}/categories`)
            const data = await response.json()
            const suppressedIds = data.suppressed_category_ids || []
            const categoryOverrides = data.category_overrides || {}
            const categoryData = {
                suppressed: suppressedIds,
                visible: categories.filter((cat) => !suppressedIds.includes(cat.id)).map((cat) => cat.id),
                overrides: categoryOverrides,
            }
            setFieldCategoryData((prev) => ({
                ...prev,
                [field.id]: categoryData,
            }))
            return categoryData
        } catch (e) {
            console.error('Failed to load field category data:', e)
            const fallback = {
                suppressed: [],
                visible: categories.map((c) => c.id),
                overrides: {},
                __loadError: true,
            }
            setFieldCategoryData((prev) => ({
                ...prev,
                [field.id]: fallback,
            }))
            return fallback
        }
    }, [categories])

    useEffect(() => {
        if (!selectedCategoryId) return
        ;[...manageableFields, ...automatedFields].forEach((field) => {
            loadFieldCategoryData(field)
        })
        // Lengths (not array identity) avoid refetch loops when Inertia passes new array references.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- use current field lists when lengths or category change
    }, [selectedCategoryId, manageableFields.length, automatedFields.length, loadFieldCategoryData])

    const eq = (a, b) => String(a) === String(b)

    const getFieldsForCategory = useMemo(() => {
        if (!selectedCategoryId) {
            return {
                enabled: [],
                available: [],
                pending: [],
                enabledAutomated: [],
                availableAutomated: [],
                pendingAutomated: [],
            }
        }
        const enabled = []
        const available = []
        const pending = []
        const enabledAutomated = []
        const availableAutomated = []
        const pendingAutomated = []
        manageableFields.forEach((field) => {
            const categoryData = fieldCategoryData[field.id]
            if (categoryData === undefined) {
                pending.push(field)
                return
            }
            if (categoryData.__loadError) {
                available.push(field)
                return
            }
            const isEnabled = !(categoryData.suppressed || []).some((sid) => eq(sid, selectedCategoryId))
            if (isEnabled) enabled.push(field)
            else available.push(field)
        })
        automatedFields.forEach((field) => {
            const categoryData = fieldCategoryData[field.id]
            if (categoryData === undefined) {
                pendingAutomated.push(field)
                return
            }
            if (categoryData.__loadError) {
                availableAutomated.push(field)
                return
            }
            const isEnabled = !(categoryData.suppressed || []).some((sid) => eq(sid, selectedCategoryId))
            if (isEnabled) enabledAutomated.push(field)
            else availableAutomated.push(field)
        })
        return {
            enabled,
            available,
            pending,
            enabledAutomated,
            availableAutomated,
            pendingAutomated,
        }
    }, [selectedCategoryId, manageableFields, automatedFields, fieldCategoryData])

    const tableRows = useMemo(() => {
        if (!selectedCategoryId || !selectedCategory) return []
        const resolvedTypeKey = selectedCategory.type_field?.field_key ?? null
        const hideTypeFamilyMember = (field) => {
            if (!typeFamilyFieldKeys.length || !typeFamilyFieldKeys.includes(field.key)) return false
            if (!resolvedTypeKey) return true
            return field.key !== resolvedTypeKey
        }
        const {
            enabled,
            available,
            pending,
            enabledAutomated,
            availableAutomated,
            pendingAutomated,
        } = getFieldsForCategory

        const findFieldByKey = (key) =>
            enabled.find((f) => f.key === key) ||
            available.find((f) => f.key === key) ||
            pending.find((f) => f.key === key) ||
            null

        const isPending = (field, automated) => {
            if (!field) return false
            return automated
                ? pendingAutomated.some((f) => f.id === field.id)
                : pending.some((f) => f.id === field.id)
        }

        const primaryTypeField = resolvedTypeKey ? findFieldByKey(resolvedTypeKey) : null
        const descriptorKeys = ['environment_type', 'subject_type']
        const primaryDescriptorFields = descriptorKeys
            .map((key) => findFieldByKey(key))
            .filter(Boolean)

        const otherFilter = (f) =>
            !hideTypeFamilyMember(f) && !descriptorKeys.includes(f.key) && !(resolvedTypeKey && f.key === resolvedTypeKey)
        const otherEnabled = enabled.filter(otherFilter)
        const otherAvailable = available.filter(otherFilter)
        const otherPending = pending.filter(otherFilter)

        const rows = []
        const pushRow = (field, { automated, enabled: en, visibilityLoading = false }) => {
            rows.push({
                field,
                isAutomated: automated,
                isEnabled: en,
                visibilityLoading,
                key: `${field.id}-${automated ? 'a' : 'm'}`,
            })
        }

        if (primaryTypeField) {
            const loading = isPending(primaryTypeField, false)
            pushRow(primaryTypeField, {
                automated: false,
                enabled: !loading && enabled.some((f) => f.id === primaryTypeField.id),
                visibilityLoading: loading,
            })
        }
        primaryDescriptorFields.forEach((field) => {
            const loading = isPending(field, false)
            pushRow(field, {
                automated: false,
                enabled: !loading && enabled.some((f) => f.id === field.id),
                visibilityLoading: loading,
            })
        })
        otherEnabled.forEach((f) => pushRow(f, { automated: false, enabled: true, visibilityLoading: false }))
        otherAvailable.forEach((f) => pushRow(f, { automated: false, enabled: false, visibilityLoading: false }))
        otherPending.forEach((f) => pushRow(f, { automated: false, enabled: false, visibilityLoading: true }))
        enabledAutomated.forEach((f) => pushRow(f, { automated: true, enabled: true, visibilityLoading: false }))
        availableAutomated.forEach((f) => pushRow(f, { automated: true, enabled: false, visibilityLoading: false }))
        pendingAutomated.forEach((f) => pushRow(f, { automated: true, enabled: false, visibilityLoading: true }))

        return rows
    }, [selectedCategoryId, selectedCategory, getFieldsForCategory, typeFamilyFieldKeys])

    const displayTableRows = useMemo(() => {
        let rows = tableRows
        if (fieldFilter === 'low_coverage' && lowCoverageFieldKeys?.length) {
            const set = new Set(lowCoverageFieldKeys.map(String))
            rows = rows.filter((r) => set.has(r.field.key))
        }
        if (!showAutomatedFields) {
            rows = rows.filter((r) => !r.isAutomated)
        }
        return rows
    }, [tableRows, fieldFilter, lowCoverageFieldKeys, showAutomatedFields])

    const clearLowCoverageHref = useMemo(() => {
        if (typeof route !== 'function') return MANAGE_CATEGORIES_URL
        const slug = selectedCategory?.slug
        return slug ? route('manage.categories', { category: slug }) : route('manage.categories')
    }, [selectedCategory])

    const refreshRegistry = useCallback(() => {
        router.reload({ only: ['registry', 'customFieldsLimit'] })
    }, [])

    const toggleCategoryField = useCallback(
        async (fieldId, categoryId, isSuppressed, meta = {}) => {
            const willBeEnabled = !!isSuppressed
            setFieldCategoryData((prev) => {
                const current = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                const newSuppressed = willBeEnabled
                    ? (current.suppressed || []).filter((id) => !eq(id, categoryId))
                    : [...(current.suppressed || []), categoryId]
                const visibleCategories = categories.filter((cat) => !newSuppressed.some((sid) => eq(sid, cat.id)))
                return {
                    ...prev,
                    [fieldId]: {
                        ...current,
                        suppressed: newSuppressed,
                        visible: visibleCategories.map((cat) => cat.id),
                    },
                }
            })

            let succeeded = false
            try {
                const response = await fetch(
                    `/app/api/tenant/metadata/fields/${fieldId}/categories/${categoryId}/visibility`,
                    {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ is_hidden: !willBeEnabled }),
                    }
                )
                if (!response.ok) {
                    setFieldCategoryData((prev) => {
                        const current = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                        const reverted = willBeEnabled
                            ? [...(current.suppressed || []), categoryId]
                            : (current.suppressed || []).filter((id) => !eq(id, categoryId))
                        const visibleCategories = categories.filter((cat) => !reverted.some((sid) => eq(sid, cat.id)))
                        return {
                            ...prev,
                            [fieldId]: {
                                ...current,
                                suppressed: reverted,
                                visible: visibleCategories.map((cat) => cat.id),
                            },
                        }
                    })
                } else {
                    succeeded = true
                }
            } catch (e) {
                console.error(e)
                setFieldCategoryData((prev) => {
                    const current = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                    const reverted = willBeEnabled
                        ? [...(current.suppressed || []), categoryId]
                        : (current.suppressed || []).filter((id) => !eq(id, categoryId))
                    const visibleCategories = categories.filter((cat) => !reverted.some((sid) => eq(sid, cat.id)))
                    return {
                        ...prev,
                        [fieldId]: {
                            ...current,
                            suppressed: reverted,
                            visible: visibleCategories.map((cat) => cat.id),
                        },
                    }
                })
            }
            if (succeeded && meta.fieldLabel && meta.categoryName) {
                postNotice(`${meta.fieldLabel} ${willBeEnabled ? 'enabled' : 'disabled'} for ${meta.categoryName}.`)
            }
        },
        [categories, postNotice]
    )

    const handleToggleWithConfirm = useCallback(
        (fieldId, categoryId, isSuppressed, field, categoryName) => {
            const fieldLabel = field?.label || field?.system_label || field?.key || 'Field'
            const name = categoryName ?? categories.find((c) => String(c.id) === String(categoryId))?.name ?? 'Category'
            if (!isSuppressed && field && CORE_FIELD_KEYS.includes(field.key)) {
                setPendingDisable({ fieldId, categoryId, fieldLabel, categoryName: name })
                setConfirmDisableCoreOpen(true)
            } else {
                toggleCategoryField(fieldId, categoryId, isSuppressed, { fieldLabel, categoryName: name })
            }
        },
        [toggleCategoryField, categories]
    )

    const wrapToggle = useCallback(
        (field, categoryId) => (fieldId, categoryIdArg, isSuppressed) => {
            const catName =
                categories.find((c) => String(c.id) === String(categoryIdArg ?? categoryId))?.name ?? 'Category'
            handleToggleWithConfirm(fieldId, categoryIdArg ?? categoryId, isSuppressed, field, catName)
        },
        [handleToggleWithConfirm, categories]
    )

    const toggleVisibility = async (fieldId, context, currentValue) => {
        if (!canManageVisibility) return
        const newValue = !currentValue
        const visibilityKey =
            context === 'upload' ? 'show_on_upload' : context === 'edit' ? 'show_on_edit' : 'show_in_filters'
        const contextLabel = context === 'upload' ? 'Upload' : context === 'edit' ? 'Quick view' : 'Filter'
        const currentCategoryId = selectedCategoryId
        if (currentCategoryId) {
            setFieldCategoryData((prev) => {
                const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                return {
                    ...prev,
                    [fieldId]: {
                        ...fieldData,
                        overrides: {
                            ...fieldData.overrides,
                            [currentCategoryId]: {
                                ...fieldData.overrides?.[currentCategoryId],
                                [visibilityKey]: newValue,
                            },
                        },
                    },
                }
            })
        }
        try {
            const requestBody = { [visibilityKey]: newValue }
            if (currentCategoryId) requestBody.category_id = currentCategoryId
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
            if (response.ok) {
                postNotice(`${contextLabel} ${newValue ? 'on' : 'off'} for this folder.`)
            } else {
                const errorMsg =
                    responseData?.error || responseData?.message || `Failed to update visibility (${response.status})`
                postNotice(`Error: ${errorMsg}`, 'error')
                if (currentCategoryId) {
                    setFieldCategoryData((prev) => {
                        const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                        return {
                            ...prev,
                            [fieldId]: {
                                ...fieldData,
                                overrides: {
                                    ...fieldData.overrides,
                                    [currentCategoryId]: {
                                        ...fieldData.overrides?.[currentCategoryId],
                                        [visibilityKey]: currentValue,
                                    },
                                },
                            },
                        }
                    })
                }
            }
        } catch (error) {
            postNotice(error.message || 'Network error', 'error')
        }
    }

    const toggleRequired = async (fieldId, categoryId, currentValue) => {
        if (!canManageVisibility || !categoryId) return
        const newValue = !currentValue
        setFieldCategoryData((prev) => {
            const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
            return {
                ...prev,
                [fieldId]: {
                    ...fieldData,
                    overrides: {
                        ...fieldData.overrides,
                        [categoryId]: {
                            ...fieldData.overrides?.[categoryId],
                            is_required: newValue,
                        },
                    },
                },
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
                body: JSON.stringify({ is_required: newValue, category_id: categoryId }),
            })
            if (response.ok) {
                postNotice(`Required ${newValue ? 'on' : 'off'} for this folder.`)
            } else {
                postNotice('Could not update required setting.', 'error')
                setFieldCategoryData((prev) => {
                    const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                    return {
                        ...prev,
                        [fieldId]: {
                            ...fieldData,
                            overrides: {
                                ...fieldData.overrides,
                                [categoryId]: {
                                    ...fieldData.overrides?.[categoryId],
                                    is_required: currentValue,
                                },
                            },
                        },
                    }
                })
            }
        } catch (e) {
            console.error(e)
        }
    }

    const togglePrimary = async (fieldId, categoryId, currentValue) => {
        if (!canManageVisibility || !categoryId) return
        const newValue = !currentValue
        setFieldCategoryData((prev) => {
            const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
            return {
                ...prev,
                [fieldId]: {
                    ...fieldData,
                    overrides: {
                        ...fieldData.overrides,
                        [categoryId]: {
                            ...fieldData.overrides?.[categoryId],
                            is_primary: newValue,
                        },
                    },
                },
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
                body: JSON.stringify({ is_primary: newValue, category_id: categoryId }),
            })
            if (!response.ok) {
                setFieldCategoryData((prev) => {
                    const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                    return {
                        ...prev,
                        [fieldId]: {
                            ...fieldData,
                            overrides: {
                                ...fieldData.overrides,
                                [categoryId]: {
                                    ...fieldData.overrides?.[categoryId],
                                    is_primary: currentValue,
                                },
                            },
                        },
                    }
                })
                postNotice('Could not update primary filter placement.', 'error')
            } else {
                postNotice(`Primary filter placement ${newValue ? 'on' : 'off'} for this folder.`)
            }
        } catch (e) {
            console.error(e)
        }
    }

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
                body: JSON.stringify({ ai_eligible: newValue }),
            })
            if (response.ok) {
                postNotice(`AI suggestions ${newValue ? 'on' : 'off'}.`)
                refreshRegistry()
            } else {
                postNotice('Could not update AI suggestions.', 'error')
            }
        } catch (e) {
            console.error(e)
            postNotice('Could not update AI suggestions.', 'error')
        }
    }

    const openDefinitionModal = async (field) => {
        try {
            const isCustom = !systemFields.some((sf) => sf.id === field.id)
            const response = await fetch(`/app/tenant/metadata/fields/${field.id}`)
            const data = await response.json()
            if (data.field) {
                setEditingField(
                    isCustom
                        ? data.field
                        : {
                              ...field,
                              ...data.field,
                              scope: 'system',
                              is_system: true,
                              ai_eligible:
                                  data.field?.ai_eligible !== undefined
                                      ? data.field.ai_eligible
                                      : (field.ai_eligible ?? false),
                          }
                )
                setModalOpen(true)
            }
        } catch (e) {
            console.error(e)
        }
    }

    const handleModalSuccess = () => {
        setModalOpen(false)
        setEditingField(null)
        setFieldCategoryData({})
        router.reload({ only: ['registry', 'customFieldsLimit'] })
    }

    const switchClass = (on) =>
        `relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors ${
            on ? 'bg-indigo-600' : 'bg-gray-200'
        }`

    return (
        <div className="space-y-6">
            {successMessage && !onSaveNotice && (
                <div className="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-2 text-sm text-indigo-900">
                    {successMessage}
                </div>
            )}

            {fieldFilter === 'low_coverage' && (
                <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p>
                            Showing fields flagged as <span className="font-medium">low coverage</span> in Insights
                            {lowCoverageFieldKeys?.length
                                ? ` (${lowCoverageFieldKeys.length} field${lowCoverageFieldKeys.length === 1 ? '' : 's'})`
                                : ''}
                            . Switch category if a field is not listed here.
                        </p>
                        <Link
                            href={clearLowCoverageHref}
                            className="shrink-0 font-medium text-indigo-700 hover:text-indigo-600"
                        >
                            Clear filter
                        </Link>
                    </div>
                </div>
            )}

            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div className="min-w-0 flex-1">
                    <h3 className="text-base font-semibold text-gray-900">
                        {selectedCategory?.name ?? 'Select a folder'}
                    </h3>
                    <p className="mt-1 text-sm text-gray-500">
                        Turn fields on or off for this folder. Expand a row to tune upload, quick view, filters, and
                        more.{' '}
                        <span className="text-gray-700">
                            Each switch saves immediately—a confirmation appears at the top of the page when it succeeds.
                        </span>{' '}
                        Use <span className="font-medium text-gray-800">Field definition</span> or{' '}
                        <span className="font-medium text-gray-800">Edit</span> for label and option changes; those
                        screens have a Save button.
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
                        className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                    >
                        <PlusIcon className="h-4 w-4" />
                        Add field
                    </button>
                )}
            </div>

            {canToggleEbi && selectedCategory && (
                <div className="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-gray-900">Enable Brand Intelligence</p>
                        <p className="mt-0.5 text-xs text-gray-500">
                            When on, assets in this category are scored after analysis (same idea as AI field toggles).
                        </p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        aria-checked={selectedCategory.ebi_enabled === true}
                        disabled={ebiToggleLoading}
                        onClick={toggleEbiEnabled}
                        className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${
                            selectedCategory.ebi_enabled === true ? 'bg-indigo-600' : 'bg-gray-200'
                        }`}
                    >
                        <span
                            className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                selectedCategory.ebi_enabled === true ? 'translate-x-5' : 'translate-x-0'
                            }`}
                        />
                    </button>
                </div>
            )}

            <label className="inline-flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
                <input
                    type="checkbox"
                    className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    checked={showAutomatedFields}
                    onChange={(e) => {
                        setShowAutomatedFields(e.target.checked)
                        setExpandedDetailKey(null)
                    }}
                />
                Show automated fields
            </label>

            <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                Field
                            </th>
                            <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                Coverage
                            </th>
                            <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                Status
                            </th>
                            <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 bg-white">
                        {displayTableRows.length === 0 ? (
                            <tr>
                                <td colSpan={4} className="px-4 py-8 text-center text-sm text-gray-500">
                                    {fieldFilter === 'low_coverage' && tableRows.length > 0 ? (
                                        <>
                                            No low-coverage fields from Insights appear in this category. Try another
                                            folder or{' '}
                                            <Link
                                                href={clearLowCoverageHref}
                                                className="font-medium text-indigo-600 hover:text-indigo-500"
                                            >
                                                clear the filter
                                            </Link>
                                            .
                                        </>
                                    ) : categories.length === 0 ? (
                                        'No categories for this brand.'
                                    ) : !selectedCategoryId ? (
                                        'Select a folder on the left to see its fields.'
                                    ) : (
                                        'Loading fields…'
                                    )}
                                </td>
                            </tr>
                        ) : null}
                        {displayTableRows.map(
                            ({ field, isAutomated, isEnabled, visibilityLoading, key: rowKey }) => {
                            const data = fieldCategoryData[field.id]
                            const pct = visibilityLoading
                                ? null
                                : coveragePercent(field, selectedCategoryId, data, isAutomated)
                            const onToggle = wrapToggle(field, selectedCategoryId)
                            const detailData = fieldCategoryData[field.id]
                            const detailEffective =
                                selectedCategoryId != null
                                    ? resolveEffective(field, selectedCategoryId, detailData)
                                    : null
                            const isExpanded = expandedDetailKey === rowKey
                            const primaryTypeKey = selectedCategory?.type_field?.field_key ?? null
                            return (
                                <Fragment key={rowKey}>
                                    <tr
                                        className={
                                            visibilityLoading
                                                ? 'bg-slate-50/80'
                                                : isEnabled
                                                  ? ''
                                                  : 'bg-gray-50/60'
                                        }
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-medium text-gray-900">
                                                    {fieldTitle(field, primaryTypeKey)}
                                                </span>
                                                {isAutomated && (
                                                    <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-600">
                                                        Auto
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-500">
                                            {visibilityLoading ? '—' : `${pct}%`}
                                        </td>
                                        <td className="px-4 py-3">
                                            <button
                                                type="button"
                                                role="switch"
                                                aria-checked={!visibilityLoading && isEnabled}
                                                aria-busy={visibilityLoading}
                                                disabled={
                                                    !canManageVisibility ||
                                                    !selectedCategoryId ||
                                                    visibilityLoading
                                                }
                                                onClick={() => onToggle(field.id, selectedCategoryId, !isEnabled)}
                                                className={`${switchClass(!visibilityLoading && isEnabled)} ${
                                                    visibilityLoading ? 'cursor-wait opacity-60' : ''
                                                }`}
                                            >
                                                <span
                                                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${
                                                        isEnabled && !visibilityLoading
                                                            ? 'translate-x-5'
                                                            : 'translate-x-0'
                                                    }`}
                                                />
                                            </button>
                                            <span className="ml-2 text-xs text-gray-500">
                                                {visibilityLoading
                                                    ? 'Loading…'
                                                    : isEnabled
                                                      ? 'Enabled'
                                                      : 'Off'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <button
                                                type="button"
                                                disabled={visibilityLoading}
                                                onClick={() =>
                                                    setExpandedDetailKey((k) => (k === rowKey ? null : rowKey))
                                                }
                                                className="rounded-md border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-indigo-600 shadow-sm hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {isExpanded ? 'Collapse' : 'Edit'}
                                            </button>
                                        </td>
                                    </tr>
                                    {isExpanded && detailEffective && !visibilityLoading && (
                                        <tr className="bg-gray-50/80">
                                            <td colSpan={4} className="px-4 py-4">
                                                <div className="space-y-4 max-w-xl">
                                                    <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                                                        {selectedCategory?.name ?? 'Category'} — visibility and behavior
                                                    </p>
                                                    {!isFilterOnlyField(field) && (
                                                        <label className="flex items-center justify-between gap-4">
                                                            <span className="text-sm text-gray-700">Show on upload</span>
                                                            <button
                                                                type="button"
                                                                role="switch"
                                                                aria-checked={detailEffective.upload}
                                                                disabled={
                                                                    !canManageVisibility || isAutomated || !isEnabled
                                                                }
                                                                onClick={() =>
                                                                    toggleVisibility(
                                                                        field.id,
                                                                        'upload',
                                                                        detailEffective.upload
                                                                    )
                                                                }
                                                                className={switchClass(detailEffective.upload)}
                                                            >
                                                                <span
                                                                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${
                                                                        detailEffective.upload ? 'translate-x-5' : 'translate-x-0'
                                                                    }`}
                                                                />
                                                            </button>
                                                        </label>
                                                    )}
                                                    {isAutomated && !isFilterOnlyField(field) && (
                                                        <p className="text-xs text-gray-500 -mt-2">
                                                            Automated fields are filled by the system; upload placement stays
                                                            off.
                                                        </p>
                                                    )}
                                                    {!isFilterOnlyField(field) && (
                                                        <label className="flex items-center justify-between gap-4">
                                                            <span className="text-sm text-gray-700">Quick view (asset details)</span>
                                                            <button
                                                                type="button"
                                                                role="switch"
                                                                aria-checked={detailEffective.edit}
                                                                disabled={!canManageVisibility || !isEnabled}
                                                                onClick={() =>
                                                                    toggleVisibility(field.id, 'edit', detailEffective.edit)
                                                                }
                                                                className={switchClass(detailEffective.edit)}
                                                            >
                                                                <span
                                                                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${
                                                                        detailEffective.edit ? 'translate-x-5' : 'translate-x-0'
                                                                    }`}
                                                                />
                                                            </button>
                                                        </label>
                                                    )}
                                                    <label className="flex items-center justify-between gap-4">
                                                        <span className="text-sm text-gray-700">Show in filters</span>
                                                        <button
                                                            type="button"
                                                            role="switch"
                                                            aria-checked={detailEffective.filter}
                                                            disabled={!canManageVisibility || !isEnabled}
                                                            onClick={() =>
                                                                toggleVisibility(field.id, 'filter', detailEffective.filter)
                                                            }
                                                            className={switchClass(detailEffective.filter)}
                                                        >
                                                            <span
                                                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${
                                                                    detailEffective.filter ? 'translate-x-5' : 'translate-x-0'
                                                                }`}
                                                            />
                                                        </button>
                                                    </label>
                                                    {!isFilterOnlyField(field) && (
                                                        <label className="flex items-center justify-between gap-4">
                                                            <span className="text-sm text-gray-700">Primary filter placement</span>
                                                            <button
                                                                type="button"
                                                                role="switch"
                                                                aria-checked={detailEffective.primary}
                                                                disabled={!canManageVisibility || !isEnabled}
                                                                onClick={() =>
                                                                    togglePrimary(
                                                                        field.id,
                                                                        selectedCategoryId,
                                                                        detailEffective.primary
                                                                    )
                                                                }
                                                                className={switchClass(detailEffective.primary)}
                                                            >
                                                                <span
                                                                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${
                                                                        detailEffective.primary ? 'translate-x-5' : 'translate-x-0'
                                                                    }`}
                                                                />
                                                            </button>
                                                        </label>
                                                    )}
                                                    {!isFilterOnlyField(field) && (
                                                        <label className="flex items-center justify-between gap-4">
                                                            <span className="text-sm text-gray-700">Required when uploading</span>
                                                            <button
                                                                type="button"
                                                                role="switch"
                                                                aria-checked={detailEffective.required}
                                                                disabled={!canManageVisibility || !isEnabled}
                                                                onClick={() =>
                                                                    toggleRequired(
                                                                        field.id,
                                                                        selectedCategoryId,
                                                                        detailEffective.required
                                                                    )
                                                                }
                                                                className={switchClass(detailEffective.required)}
                                                            >
                                                                <span
                                                                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${
                                                                        detailEffective.required ? 'translate-x-5' : 'translate-x-0'
                                                                    }`}
                                                                />
                                                            </button>
                                                        </label>
                                                    )}
                                                    {!isAutomated && (
                                                        <label className="flex items-center justify-between gap-4">
                                                            <span className="text-sm text-gray-700">AI suggestions</span>
                                                            <button
                                                                type="button"
                                                                role="switch"
                                                                aria-checked={detailEffective.aiEligible}
                                                                disabled={!canManageVisibility || !isEnabled}
                                                                onClick={() =>
                                                                    toggleAiEligible(field.id, detailEffective.aiEligible)
                                                                }
                                                                className={switchClass(detailEffective.aiEligible)}
                                                            >
                                                                <span
                                                                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${
                                                                        detailEffective.aiEligible ? 'translate-x-5' : 'translate-x-0'
                                                                    }`}
                                                                />
                                                            </button>
                                                        </label>
                                                    )}
                                                    {!isEnabled && (
                                                        <p className="text-xs text-gray-500">
                                                            Turn the field on for this category to change visibility options.
                                                        </p>
                                                    )}
                                                    {canManageFields && (
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setExpandedDetailKey(null)
                                                                openDefinitionModal(field)
                                                            }}
                                                            className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                                                        >
                                                            Field definition (labels, options, type…)
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            )
                        })}
                    </tbody>
                </table>
            </div>

            <ConfirmDialog
                open={confirmDisableCoreOpen}
                onClose={() => {
                    setConfirmDisableCoreOpen(false)
                    setPendingDisable(null)
                }}
                onConfirm={() => {
                    if (!pendingDisable) return
                    const { fieldId, categoryId, fieldLabel, categoryName } = pendingDisable
                    toggleCategoryField(fieldId, categoryId, false, { fieldLabel, categoryName })
                    setPendingDisable(null)
                    setConfirmDisableCoreOpen(false)
                }}
                title="Disable field?"
                message={
                    pendingDisable
                        ? `Disable "${pendingDisable.fieldLabel}" for ${pendingDisable.categoryName}? This may affect how assets are organized.`
                        : ''
                }
                confirmText="Disable"
                cancelText="Cancel"
                variant="warning"
            />

            <MetadataFieldModal
                isOpen={modalOpen}
                onClose={() => {
                    setModalOpen(false)
                    setEditingField(null)
                }}
                field={editingField}
                preselectedCategoryId={selectedCategoryId}
                categories={categories}
                canManageFields={canManageFields}
                customFieldsLimit={customFieldsLimit}
                onSuccess={handleModalSuccess}
            />
        </div>
    )
}
