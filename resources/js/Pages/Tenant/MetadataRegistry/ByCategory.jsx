import { useState, useEffect, useMemo, useRef, useCallback } from 'react'
import { router } from '@inertiajs/react'
import {
    DndContext,
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core'
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import {
    ArrowPathIcon,
    ArrowUpCircleIcon,
    Bars3Icon,
    CheckCircleIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    CloudArrowUpIcon,
    EllipsisVerticalIcon,
    ExclamationCircleIcon,
    EyeIcon,
    FolderIcon,
    FunnelIcon,
    InformationCircleIcon,
    PencilIcon,
    PlusIcon,
    RectangleStackIcon,
    Squares2X2Icon,
    SparklesIcon,
    StarIcon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline'
import { StarIcon as StarIconSolid } from '@heroicons/react/24/solid'
import ConfirmDialog from '../../../Components/ConfirmDialog'
import ArchiveFieldModal from '../../../Components/ArchiveFieldModal'
import MetadataFieldModal from '../../../Components/MetadataFieldModal'
import AddCategorySlideOver from '../../../Components/Metadata/AddCategorySlideOver'
import AdvancedSettingsSlideOver from '../../../Components/Metadata/AdvancedSettingsSlideOver'
import CategorySettingsSlideOver from '../../../Components/Metadata/CategorySettingsSlideOver'
import UpgradeCategoryModal from '../../../Components/Metadata/UpgradeCategoryModal'
import CategoryList from '../../../Components/Metadata/CategoryList'

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
 * can be configured.
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
const METADATA_REGISTRY_URL = typeof route === 'function' ? route('tenant.metadata.registry.index') : '/app/tenant/metadata/registry'
const CORE_FIELD_KEYS = ['collection', 'tags']

export default function ByCategoryView({ 
    registry, 
    brands = [],
    selectedBrandId,
    onBrandChange,
    categories, 
    canManageVisibility = true, // Default true so toggles are clickable; API enforces permission
    canManageFields = false,
    customFieldsLimit = null,
    metadataFieldFamilies = {},
    initialCategorySlug = null,
}) {
    const [selectedCategoryId, setSelectedCategoryId] = useState(null)
    const [expandedFamilies, setExpandedFamilies] = useState({})
    const selectedCategoryIdRef = useRef(null)
    const [fieldCategoryData, setFieldCategoryData] = useState({}) // Cache category data per field
    const [loadingFields, setLoadingFields] = useState(new Set())
    const [fieldOrder, setFieldOrder] = useState({}) // Store order per category: { categoryId: [fieldId, ...] }
    const [successMessage, setSuccessMessage] = useState(null) // Success message state
    const [modalOpen, setModalOpen] = useState(false)
    const [editingField, setEditingField] = useState(null)
    const [loadingFieldData, setLoadingFieldData] = useState(false)
    const [copyFromSourceId, setCopyFromSourceId] = useState(null) // Source category for Copy from
    const [copyOrResetLoading, setCopyOrResetLoading] = useState(false)
    const [confirmCopyOpen, setConfirmCopyOpen] = useState(false)
    const [confirmResetOpen, setConfirmResetOpen] = useState(false)
    const [confirmApplyOtherBrandsOpen, setConfirmApplyOtherBrandsOpen] = useState(false)
    const [applyOtherBrandsTargets, setApplyOtherBrandsTargets] = useState([]) // { brand_name, category_name }[]
    const [applyOtherBrandsLoading, setApplyOtherBrandsLoading] = useState(false)
    const [confirmDisableCoreOpen, setConfirmDisableCoreOpen] = useState(false)
    const [pendingDisable, setPendingDisable] = useState(null) // { fieldId, categoryId, fieldLabel, categoryName }
    // Phase 3a: Named profiles
    const [saveProfileName, setSaveProfileName] = useState('')
    const [profileAvailableToAllBrands, setProfileAvailableToAllBrands] = useState(false)
    const [saveProfileLoading, setSaveProfileLoading] = useState(false)
    const [profiles, setProfiles] = useState([]) // { id, name, category_slug }[]
    const [profilesLoading, setProfilesLoading] = useState(false)
    const [applyProfileId, setApplyProfileId] = useState(null)
    const [confirmApplyProfileOpen, setConfirmApplyProfileOpen] = useState(false)
    const [previewSnapshot, setPreviewSnapshot] = useState(null) // profile snapshot for preview (not saved)
    const [previewProfileName, setPreviewProfileName] = useState(null)
    const [localCategories, setLocalCategories] = useState(categories)
    const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false)
    const [categoryToDelete, setCategoryToDelete] = useState(null)
    const [confirmArchiveOpen, setConfirmArchiveOpen] = useState(false)
    const [pendingArchiveField, setPendingArchiveField] = useState(null) // { field, onConfirm }
    const [archiveRemoveFromAssets, setArchiveRemoveFromAssets] = useState(false)
    const [archiveLoading, setArchiveLoading] = useState(false)
    const [editingCategoryId, setEditingCategoryId] = useState(null)
    const [editingCategoryName, setEditingCategoryName] = useState('')
    const [expandedAvailable, setExpandedAvailable] = useState(false)
    const [expandedAutomated, setExpandedAutomated] = useState(false)
    const [advancedSettingsOpen, setAdvancedSettingsOpen] = useState(false)
    const [categorySettingsOpen, setCategorySettingsOpen] = useState(false)
    const [categorySettingsCategory, setCategorySettingsCategory] = useState(null)
    const [categoryMenuOpen, setCategoryMenuOpen] = useState(false)
    const [categoryFormData, setCategoryFormData] = useState({ brand_roles: [], brand_users: [] })
    const [upgradeModalOpen, setUpgradeModalOpen] = useState(false)
    const [upgradeLoading, setUpgradeLoading] = useState(false)
    const [confirmRevertOpen, setConfirmRevertOpen] = useState(false)
    const [categoryToRevert, setCategoryToRevert] = useState(null)
    const [highlightedFieldId, setHighlightedFieldId] = useState(null)
    const [fieldReorderLoading, setFieldReorderLoading] = useState(false)
    const [showArchived, setShowArchived] = useState(false)
    const [archivedFields, setArchivedFields] = useState([])
    const [archivedLoading, setArchivedLoading] = useState(false)
    const [restoreLoading, setRestoreLoading] = useState(false)

    useEffect(() => {
        setLocalCategories(categories)
    }, [categories])

    // Scope to one brand at a time so the list has no duplicate category names
    const categoriesForBrand = useMemo(() => {
        if (!selectedBrandId) return localCategories
        return localCategories.filter(c => c.brand_id === selectedBrandId)
    }, [localCategories, selectedBrandId])

    // Sync selectedCategoryId from URL param (initialCategorySlug) on mount and when slug/brand changes
    useEffect(() => {
        if (categoriesForBrand.length === 0) return
        if (initialCategorySlug) {
            const match = categoriesForBrand.find(c => (c.slug || '').toLowerCase() === initialCategorySlug.toLowerCase())
            setSelectedCategoryId(match ? match.id : categoriesForBrand[0]?.id ?? null)
        } else if (!selectedCategoryId) {
            setSelectedCategoryId(categoriesForBrand[0]?.id ?? null)
        }
    }, [initialCategorySlug, categoriesForBrand])

    // Sync ref with state
    useEffect(() => {
        selectedCategoryIdRef.current = selectedCategoryId
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

    const brandId = selectedBrandId ?? brands[0]?.id ?? categoriesForBrand[0]?.brand_id

    const onCategoriesChange = useCallback((newGrouped) => {
        const bid = selectedBrandId ?? brands[0]?.id ?? categoriesForBrand[0]?.brand_id
        if (!bid) return
        setLocalCategories((prev) => {
            const other = prev.filter((c) => c.brand_id !== bid)
            const brandName = brands.find((b) => b.id === bid)?.name
            const assetWithBrand = (newGrouped.asset || []).map((c) => ({ ...c, brand_id: bid, brand_name: brandName }))
            const deliverableWithBrand = (newGrouped.deliverable || []).map((c) => ({ ...c, brand_id: bid, brand_name: brandName }))
            return [...other, ...assetWithBrand, ...deliverableWithBrand]
        })
    }, [selectedBrandId, brands, categoriesForBrand])

    const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || ''

    const handleRenameCategory = useCallback(async (category, newName) => {
        if (!brandId || !newName?.trim()) return
        const trimmed = newName.trim()
        const slug = trimmed.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '')
        try {
            const res = await fetch(`/app/brands/${brandId}/categories/${category.id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ name: trimmed, slug: slug || category.slug || 'category' }),
            })
            if (res.ok) {
                setLocalCategories((prev) =>
                    prev.map((c) => (c.id === category.id ? { ...c, name: trimmed } : c))
                )
                setSuccessMessage('Category renamed.')
                setTimeout(() => setSuccessMessage(null), 3000)
            }
        } catch (e) {
            setSuccessMessage('Failed to rename.')
            setTimeout(() => setSuccessMessage(null), 3000)
        } finally {
            setEditingCategoryId(null)
        }
    }, [brandId])

    const handleDeleteCategory = useCallback(() => {
        if (!brandId || !categoryToDelete) return
        const url = typeof route === 'function' ? route('brands.categories.destroy', { brand: brandId, category: categoryToDelete.id }) : `/app/brands/${brandId}/categories/${categoryToDelete.id}`
        router.delete(url, {
            preserveScroll: false,
            onSuccess: () => {
                setSelectedCategoryId(null)
                setConfirmDeleteOpen(false)
                setCategoryToDelete(null)
            },
            onError: () => {
                setSuccessMessage('Failed to delete category.')
                setTimeout(() => setSuccessMessage(null), 4000)
                setConfirmDeleteOpen(false)
                setCategoryToDelete(null)
            },
        })
    }, [brandId, categoryToDelete])

    const [addCategoryOpen, setAddCategoryOpen] = useState(false)

    const handleAddCategorySuccess = useCallback((newCategory) => {
        setLocalCategories(prev => [...prev, newCategory])
        setSelectedCategoryId(newCategory.id)
        setFieldCategoryData({}) // Clear cache so UI refetches visibility (new category has minimal defaults)
        setAddCategoryOpen(false)
    }, [])

    const handleAddCategory = useCallback(() => {
        if (brandId) setAddCategoryOpen(true)
    }, [brandId])

    // Fetch brand roles/users when opening category settings
    useEffect(() => {
        if (categorySettingsOpen && brandId) {
            fetch(`/app/api/brands/${brandId}/category-form-data`, { credentials: 'same-origin' })
                .then((r) => r.json())
                .then((data) => {
                    setCategoryFormData({
                        brand_roles: data.brand_roles || [],
                        brand_users: data.brand_users || [],
                    })
                })
                .catch(() => setCategoryFormData({ brand_roles: [], brand_users: [] }))
        }
    }, [categorySettingsOpen, brandId])

    const handleCategorySettingsSuccess = useCallback((updatedCategory) => {
        setLocalCategories((prev) =>
            prev.map((c) => (c.id === updatedCategory.id ? { ...c, ...updatedCategory } : c))
        )
        setSuccessMessage('Category updated')
        setTimeout(() => setSuccessMessage(null), 3000)
    }, [])

    const handleCategorySettingsDelete = useCallback((cat) => {
        setCategoryToDelete(cat)
        setConfirmDeleteOpen(true)
    }, [])

    const handleCategorySettingsClick = useCallback((cat) => {
        setCategorySettingsCategory(cat)
        setCategorySettingsOpen(true)
    }, [])

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
                visible: categoriesForBrand.filter(cat => !suppressedIds.includes(cat.id)).map(cat => cat.id),
                overrides: categoryOverrides, // Category-specific overrides including is_primary
            }
            
            setFieldCategoryData(prev => ({
                ...prev,
                [field.id]: categoryData,
            }))

            return categoryData
        } catch (error) {
            console.error('Failed to load category data:', error)
            return { suppressed: [], visible: categoriesForBrand.map(cat => cat.id) }
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

    // Toggle field visibility for selected category (enable/disable per category)
    // API enforces metadata.tenant.visibility.manage; we allow the click so toggles are always interactive
    // meta: optional { fieldLabel, categoryName } for contextual success toast
    const toggleCategoryField = useCallback(async (fieldId, categoryId, isSuppressed, meta = {}) => {
        // isSuppressed=true means enabling (remove suppression), isSuppressed=false means disabling (add suppression)
        const willBeEnabled = !!isSuppressed

        // 1. Optimistically update local state immediately (use functional update to avoid stale closure)
        let newSuppressed
        setFieldCategoryData(prev => {
            const current = prev[fieldId] || { suppressed: [], visible: [] }
            const eq = (a, b) => String(a) === String(b)
            newSuppressed = willBeEnabled
                ? (current.suppressed || []).filter(id => !eq(id, categoryId))
                : [...(current.suppressed || []), categoryId]
            const visibleCategories = categoriesForBrand.filter(cat => !newSuppressed.some(sid => eq(sid, cat.id)))
            return {
                ...prev,
                [fieldId]: {
                    suppressed: newSuppressed,
                    visible: visibleCategories.map(cat => cat.id),
                }
            }
        })

        if (willBeEnabled && !fieldOrder[categoryId]) {
            setFieldOrder(prev => ({ ...prev, [categoryId]: [] }))
        }

        let succeeded = false
        try {
            const url = `/app/api/tenant/metadata/fields/${fieldId}/categories/${categoryId}/visibility`
            const response = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ is_hidden: !willBeEnabled }),
            })

            if (!response.ok) {
                // Revert optimistic update on error
                setFieldCategoryData(prev => {
                    const current = prev[fieldId] || { suppressed: [], visible: [] }
                    const eq = (a, b) => String(a) === String(b)
                    const reverted = willBeEnabled
                        ? [...(current.suppressed || []), categoryId]
                        : (current.suppressed || []).filter(id => !eq(id, categoryId))
                    const visibleCategories = categoriesForBrand.filter(cat => !reverted.some(sid => eq(sid, cat.id)))
                    return {
                        ...prev,
                        [fieldId]: { suppressed: reverted, visible: visibleCategories.map(cat => cat.id) },
                    }
                })
            } else {
                succeeded = true
            }
        } catch (error) {
            console.error('Failed to toggle category field:', error)
            setFieldCategoryData(prev => {
                const current = prev[fieldId] || { suppressed: [], visible: [] }
                const eq = (a, b) => String(a) === String(b)
                const reverted = willBeEnabled
                    ? [...(current.suppressed || []), categoryId]
                    : (current.suppressed || []).filter(id => !eq(id, categoryId))
                const visibleCategories = categoriesForBrand.filter(cat => !reverted.some(sid => eq(sid, cat.id)))
                return {
                    ...prev,
                    [fieldId]: { suppressed: reverted, visible: visibleCategories.map(cat => cat.id) },
                }
            })
        }

        if (succeeded && meta.fieldLabel && meta.categoryName) {
            setSuccessMessage(`${meta.fieldLabel} ${willBeEnabled ? 'enabled' : 'disabled'} for ${meta.categoryName}.`)
            setTimeout(() => setSuccessMessage(null), 3000)
        }
    }, [categoriesForBrand, fieldOrder])

    // Wrapper: intercept core field disable to show confirmation; otherwise call toggleCategoryField with contextual meta
    const handleToggleWithConfirm = useCallback((fieldId, categoryId, isSuppressed, field, categoryName) => {
        const fieldLabel = field?.label || field?.system_label || field?.key || 'Field'
        const name = categoryName ?? categoriesForBrand.find(c => String(c.id) === String(categoryId))?.name ?? 'Category'
        if (!isSuppressed && field && CORE_FIELD_KEYS.includes(field.key)) {
            setPendingDisable({ fieldId, categoryId, fieldLabel, categoryName: name })
            setConfirmDisableCoreOpen(true)
        } else {
            toggleCategoryField(fieldId, categoryId, isSuppressed, { fieldLabel, categoryName: name })
        }
    }, [toggleCategoryField, categoriesForBrand])

    const wrapToggle = useCallback((field, categoryId) => (fieldId, categoryIdArg, isSuppressed) => {
        const catName = categoriesForBrand.find(c => String(c.id) === String(categoryIdArg ?? categoryId))?.name ?? 'Category'
        handleToggleWithConfirm(fieldId, categoryIdArg ?? categoryId, isSuppressed, field, catName)
    }, [handleToggleWithConfirm, categoriesForBrand])

    const handleConfirmDisableCore = useCallback(() => {
        if (!pendingDisable) return
        const { fieldId, categoryId, fieldLabel, categoryName } = pendingDisable
        toggleCategoryField(fieldId, categoryId, false, { fieldLabel, categoryName })
        setPendingDisable(null)
        setConfirmDisableCoreOpen(false)
    }, [pendingDisable, toggleCategoryField])

    // Toggle visibility for Upload/Edit/Filter
    const toggleVisibility = async (fieldId, context, currentValue) => {
        if (!canManageVisibility) return

        const newValue = !currentValue
        const visibilityKey = context === 'upload' ? 'show_on_upload' : context === 'edit' ? 'show_on_edit' : 'show_in_filters'
        const contextLabel = context === 'upload' ? 'Upload' : context === 'edit' ? 'Quick View' : 'Filter'
        const currentCategoryId = selectedCategoryIdRef.current

        // Optimistic update
        if (currentCategoryId) {
            setFieldCategoryData((prev) => {
                const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                const updatedOverrides = {
                    ...fieldData.overrides,
                    [currentCategoryId]: {
                        ...fieldData.overrides[currentCategoryId],
                        [visibilityKey]: newValue,
                    },
                }
                return {
                    ...prev,
                    [fieldId]: { ...fieldData, overrides: updatedOverrides },
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
                setSuccessMessage(`${contextLabel} visibility ${newValue ? 'enabled' : 'disabled'}`)
                setTimeout(() => setSuccessMessage(null), 3000)
                if (!currentCategoryId) {
                    setFieldCategoryData((prev) => { const n = { ...prev }; delete n[fieldId]; return n })
                    const f = manageableFields.find(x => x.id === fieldId) || automatedFields.find(x => x.id === fieldId)
                    if (f) loadFieldCategoryData(f, true).catch(() => {})
                }
            } else {
                const errorMsg = responseData?.error || responseData?.message || `Failed to update visibility (${response.status})`
                setSuccessMessage(`Error: ${errorMsg}. Please try again.`)
                setTimeout(() => setSuccessMessage(null), 5000)
                if (currentCategoryId) {
                    setFieldCategoryData((prev) => {
                        const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                        const updatedOverrides = {
                            ...fieldData.overrides,
                            [currentCategoryId]: {
                                ...fieldData.overrides[currentCategoryId],
                                [visibilityKey]: currentValue,
                            },
                        }
                        return { ...prev, [fieldId]: { ...fieldData, overrides: updatedOverrides } }
                    })
                }
            }
        } catch (error) {
            setSuccessMessage(error.message || 'Network error. Please try again.')
            setTimeout(() => setSuccessMessage(null), 5000)
            if (currentCategoryId) {
                setFieldCategoryData((prev) => {
                    const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                    const updatedOverrides = {
                        ...fieldData.overrides,
                        [currentCategoryId]: {
                            ...fieldData.overrides[currentCategoryId],
                            [visibilityKey]: currentValue,
                        },
                    }
                    return { ...prev, [fieldId]: { ...fieldData, overrides: updatedOverrides } }
                })
            }
        }
    }

    // Toggle required field for category (must be filled when adding assets)
    // ARCHITECTURAL RULE: Required status MUST be category-scoped (like is_primary).
    const toggleRequired = async (fieldId, categoryId, currentValue) => {
        if (!canManageVisibility || !categoryId) return

        const newValue = !currentValue

        setFieldCategoryData(prev => {
            const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
            const updatedOverrides = {
                ...fieldData.overrides,
                [categoryId]: {
                    ...fieldData.overrides[categoryId],
                    is_required: newValue,
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
                    is_required: newValue,
                    category_id: categoryId,
                }),
            })

            if (response.ok) {
                setSuccessMessage(`Required field ${newValue ? 'enabled' : 'disabled'} for this category`)
                setTimeout(() => setSuccessMessage(null), 3000)
            } else {
                setFieldCategoryData(prev => {
                    const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                    const updatedOverrides = {
                        ...fieldData.overrides,
                        [categoryId]: {
                            ...fieldData.overrides[categoryId],
                            is_required: currentValue,
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
                setSuccessMessage('Failed to update required field setting')
                setTimeout(() => setSuccessMessage(null), 3000)
            }
        } catch (error) {
            console.error('Failed to update required field setting:', error)
            setFieldCategoryData(prev => {
                const fieldData = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                const updatedOverrides = {
                    ...fieldData.overrides,
                    [categoryId]: {
                        ...fieldData.overrides[categoryId],
                        is_required: currentValue,
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
            setSuccessMessage('Failed to update required field setting')
            setTimeout(() => setSuccessMessage(null), 3000)
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
                
                // Silently refresh registry preserving category + brand
                refreshMetadataRegistry()
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

    // When previewing a profile, overlay snapshot onto field data so UI shows profile without saving
    const previewOverlay = useMemo(() => {
        if (!previewSnapshot || !selectedCategoryId) return {}
        const overlay = {}
        previewSnapshot.forEach(entry => {
            const fid = entry.metadata_field_id
            overlay[fid] = {
                suppressed: entry.is_hidden ? [selectedCategoryId] : [],
                visible: entry.is_hidden ? [] : [selectedCategoryId],
                overrides: {
                    [selectedCategoryId]: {
                        show_on_upload: !entry.is_upload_hidden,
                        show_on_edit: !(entry.is_edit_hidden ?? false),
                        show_in_filters: !entry.is_filter_hidden,
                        is_primary: entry.is_primary ?? false,
                    },
                },
            }
        })
        return overlay
    }, [previewSnapshot, selectedCategoryId])

    // Get fields for selected category with ordering (uses previewOverlay when previewing a profile)
    const getFieldsForCategory = useMemo(() => {
        if (!selectedCategoryId) {
            return { enabled: [], available: [], enabledAutomated: [], availableAutomated: [] }
        }

        const enabled = []
        const available = []
        const enabledAutomated = []
        const availableAutomated = []

        const eq = (a, b) => String(a) === String(b)
        manageableFields.forEach(field => {
            const categoryData = (previewOverlay[field.id] ?? fieldCategoryData[field.id]) || { suppressed: [], visible: [] }
            const isEnabled = !(categoryData.suppressed || []).some(sid => eq(sid, selectedCategoryId))
            
            if (isEnabled) {
                enabled.push(field)
            } else {
                available.push(field)
            }
        })

        // Separate automated fields
        automatedFields.forEach(field => {
            const categoryData = (previewOverlay[field.id] ?? fieldCategoryData[field.id]) || { suppressed: [], visible: [] }
            const isEnabled = !(categoryData.suppressed || []).some(sid => eq(sid, selectedCategoryId))
            
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
    }, [selectedCategoryId, manageableFields, automatedFields, fieldCategoryData, fieldOrder, previewOverlay])

    // Phase K: Field families — split enabled/available by family (only "type" family for now)
    const typeFamilyConfig = (metadataFieldFamilies && typeof metadataFieldFamilies === 'object' && metadataFieldFamilies.type) ? metadataFieldFamilies.type : null
    const typeFamilyFieldKeys = useMemo(() => {
        if (!typeFamilyConfig || !Array.isArray(typeFamilyConfig.fields)) return []
        return typeFamilyConfig.fields
    }, [typeFamilyConfig])

    const tenantFieldIds = useMemo(() => new Set((registry?.tenant_fields || []).map(f => f.id)), [registry?.tenant_fields])

    const fieldsByFamily = useMemo(() => {
        const { enabled, available } = getFieldsForCategory
        const typeEnabled = typeFamilyFieldKeys.length > 0 ? enabled.filter(f => typeFamilyFieldKeys.includes(f.key)) : []
        const typeAvailable = typeFamilyFieldKeys.length > 0 ? available.filter(f => typeFamilyFieldKeys.includes(f.key)) : []
        const otherEnabled = typeFamilyFieldKeys.length > 0 ? enabled.filter(f => !typeFamilyFieldKeys.includes(f.key)) : enabled
        const otherAvailable = typeFamilyFieldKeys.length > 0 ? available.filter(f => !typeFamilyFieldKeys.includes(f.key)) : available
        const otherSystemEnabled = otherEnabled.filter(f => !tenantFieldIds.has(f.id))
        const customEnabledForCategory = otherEnabled.filter(f => tenantFieldIds.has(f.id))
        return {
            typeFamilyEnabled: typeEnabled,
            typeFamilyAvailable: typeAvailable,
            otherEnabled,
            otherAvailable,
            otherSystemEnabled,
            customEnabledForCategory,
        }
    }, [getFieldsForCategory, typeFamilyFieldKeys, tenantFieldIds])

    const toggleFamilyExpanded = useCallback((familyKey) => {
        setExpandedFamilies(prev => ({ ...prev, [familyKey]: !prev[familyKey] }))
    }, [])

    const buildFullFieldOrder = useCallback((typeFamilyIds, otherSystemIds, customIds) => {
        return [...typeFamilyIds, ...otherSystemIds, ...customIds]
    }, [])

    const handleEnabledFieldsDragEnd = useCallback((event) => {
        const { active, over } = event
        if (!over || active.id === over.id || !selectedCategoryId || !brandId) return

        const { otherEnabled, typeFamilyEnabled } = fieldsByFamily
        const oldIndex = otherEnabled.findIndex(f => String(f.id) === String(active.id))
        const newIndex = otherEnabled.findIndex(f => String(f.id) === String(over.id))
        if (oldIndex === -1 || newIndex === -1) return

        const reorderedOther = arrayMove(otherEnabled, oldIndex, newIndex)
        const newFieldIds = buildFullFieldOrder(
            typeFamilyEnabled.map(f => f.id),
            reorderedOther.map(f => f.id),
            [] // custom fields are now part of otherEnabled
        )

        setFieldOrder(prev => ({
            ...prev,
            [selectedCategoryId]: newFieldIds
        }))

        setFieldReorderLoading(true)
        fetch(`/app/brands/${brandId}/categories/${selectedCategoryId}/fields/reorder`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ field_order: newFieldIds }),
        })
            .then(res => { if (!res.ok) throw new Error('Failed to reorder') })
            .catch(e => console.error('[ByCategory] Field reorder failed:', e))
            .finally(() => setFieldReorderLoading(false))
    }, [selectedCategoryId, brandId, fieldsByFamily, buildFullFieldOrder])

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    )

    const selectedCategory = categoriesForBrand.find(cat => cat.id === selectedCategoryId)

    // Refresh metadata page via router.get preserving category + brand in URL (replaces router.reload)
    const refreshMetadataRegistry = useCallback((opts = {}) => {
        const params = {}
        if (selectedBrandId) params.brand = selectedBrandId
        if (selectedCategory?.slug) params.category = selectedCategory.slug
        router.get(METADATA_REGISTRY_URL, params, {
            preserveState: true,
            preserveScroll: true,
            ...opts,
        })
    }, [selectedBrandId, selectedCategory?.slug])

    // Select category: update URL and local state
    const handleSelectCategory = useCallback((categoryId) => {
        const cat = categoriesForBrand.find(c => c.id === categoryId)
        const params = {}
        if (selectedBrandId) params.brand = selectedBrandId
        if (cat?.slug) params.category = cat.slug
        router.get(METADATA_REGISTRY_URL, params, {
            preserveState: true,
            preserveScroll: true,
        })
        setSelectedCategoryId(categoryId)
    }, [categoriesForBrand, selectedBrandId])

    // Brand change: update URL (Index syncs from initial_brand_id)
    const handleBrandChange = useCallback((brandId) => {
        const params = {}
        if (brandId) params.brand = brandId
        if (selectedCategory?.slug) params.category = selectedCategory.slug
        router.get(METADATA_REGISTRY_URL, params, {
            preserveState: true,
            preserveScroll: true,
        })
        onBrandChange(brandId)
    }, [selectedCategory?.slug, onBrandChange])

    const handleArchiveField = useCallback((field) => {
        setPendingArchiveField(field)
        setArchiveRemoveFromAssets(false)
        setConfirmArchiveOpen(true)
    }, [])

    const handleConfirmArchive = useCallback(async () => {
        if (!pendingArchiveField) return
        setArchiveLoading(true)
        try {
            const res = await fetch(`/app/tenant/metadata/fields/${pendingArchiveField.id}/archive`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ remove_from_assets: archiveRemoveFromAssets }),
            })
            const data = await res.json().catch(() => ({}))
            if (res.ok && data.success) {
                setConfirmArchiveOpen(false)
                setPendingArchiveField(null)
                setArchiveRemoveFromAssets(false)
                refreshMetadataRegistry()
                setSuccessMessage('Metadata field archived successfully.')
                setTimeout(() => setSuccessMessage(null), 3000)
            } else {
                setSuccessMessage(data?.error || 'Failed to archive field')
                setTimeout(() => setSuccessMessage(null), 5000)
            }
        } catch (e) {
            setSuccessMessage(e?.message || 'Network error')
            setTimeout(() => setSuccessMessage(null), 5000)
        } finally {
            setArchiveLoading(false)
        }
    }, [pendingArchiveField, archiveRemoveFromAssets, refreshMetadataRegistry])

    // Fetch archived fields when View Archived is toggled on
    useEffect(() => {
        if (!showArchived) {
            setArchivedFields([])
            return
        }
        setArchivedLoading(true)
        fetch('/app/api/tenant/metadata/fields/archived', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((data) => setArchivedFields(data.archived_fields || []))
            .catch(() => setArchivedFields([]))
            .finally(() => setArchivedLoading(false))
    }, [showArchived])

    const handleRestoreField = useCallback(async (field) => {
        setRestoreLoading(true)
        try {
            const res = await fetch(`/app/tenant/metadata/fields/${field.id}/restore`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            })
            const data = await res.json().catch(() => ({}))
            if (res.ok && (data.success !== false)) {
                setArchivedFields((prev) => prev.filter((f) => f.id !== field.id))
                refreshMetadataRegistry()
                setSuccessMessage('Metadata field restored successfully.')
                setTimeout(() => setSuccessMessage(null), 3000)
            } else {
                setSuccessMessage(data?.error || 'Failed to restore field')
                setTimeout(() => setSuccessMessage(null), 5000)
            }
        } catch (e) {
            setSuccessMessage(e?.message || 'Network error')
            setTimeout(() => setSuccessMessage(null), 5000)
        } finally {
            setRestoreLoading(false)
        }
    }, [refreshMetadataRegistry])

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

    const handleModalSuccess = (newFieldId) => {
        if (newFieldId) setHighlightedFieldId(newFieldId)
        // Force full reload (preserveState: false) so registry gets fresh ai_eligible and other field data
        router.get(METADATA_REGISTRY_URL, {
            ...(selectedBrandId && { brand: selectedBrandId }),
            ...(selectedCategory?.slug && { category: selectedCategory.slug }),
        }, {
            preserveState: false,
            preserveScroll: true,
            onSuccess: () => {
                setFieldCategoryData({})
                if (newFieldId) setTimeout(() => setHighlightedFieldId(null), 2000)
            },
        })
    }

    // Copy visibility settings from another category to the selected category (called after confirm)
    const handleCopyFromConfirm = async () => {
        if (!canManageVisibility || !selectedCategoryId || !copyFromSourceId) return
        const sourceCat = categoriesForBrand.find(c => c.id === copyFromSourceId)
        const targetCat = selectedCategory
        if (!sourceCat || !targetCat) return
        setCopyOrResetLoading(true)
        try {
            const response = await fetch(`/app/api/tenant/metadata/categories/${selectedCategoryId}/copy-from/${copyFromSourceId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
            })
            const data = await response.json().catch(() => ({}))
            if (response.ok) {
                setSuccessMessage(data.message || 'Settings copied.')
                setTimeout(() => setSuccessMessage(null), 4000)
                setCopyFromSourceId(null)
                setConfirmCopyOpen(false)
                refreshMetadataRegistry({ onSuccess: () => setFieldCategoryData({}) })
            } else {
                setSuccessMessage(data.error || 'Failed to copy settings.')
                setTimeout(() => setSuccessMessage(null), 5000)
            }
        } catch (err) {
            setSuccessMessage('Network error. Please try again.')
            setTimeout(() => setSuccessMessage(null), 5000)
        } finally {
            setCopyOrResetLoading(false)
        }
    }

    // Reset selected category visibility to default (called after confirm)
    const handleResetConfirm = async () => {
        if (!canManageVisibility || !selectedCategoryId || !selectedCategory) return
        setCopyOrResetLoading(true)
        try {
            const response = await fetch(`/app/api/tenant/metadata/categories/${selectedCategoryId}/reset`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
            })
            const data = await response.json().catch(() => ({}))
            if (response.ok) {
                setSuccessMessage(data.message || 'Category reset to default.')
                setTimeout(() => setSuccessMessage(null), 4000)
                setConfirmResetOpen(false)
                refreshMetadataRegistry({ onSuccess: () => setFieldCategoryData({}) })
            } else {
                setSuccessMessage(data.error || 'Failed to reset.')
                setTimeout(() => setSuccessMessage(null), 5000)
            }
        } catch (err) {
            setSuccessMessage('Network error. Please try again.')
            setTimeout(() => setSuccessMessage(null), 5000)
        } finally {
            setCopyOrResetLoading(false)
        }
    }

    // Upgrade system category to latest version
    const handleUpgradeConfirm = async () => {
        if (!selectedCategory?.id || !brandId) return
        setUpgradeLoading(true)
        try {
            const response = await fetch(`/app/brands/${brandId}/categories/${selectedCategory.id}/upgrade`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ approved_fields: [] }),
            })
            const data = await response.json().catch(() => ({}))
            if (response.ok) {
                setSuccessMessage(data.message ?? 'Category upgraded.')
                setTimeout(() => setSuccessMessage(null), 4000)
                setUpgradeModalOpen(false)
                if (data.category) {
                    setLocalCategories((prev) =>
                        prev.map((c) => (c.id === data.category.id ? { ...c, ...data.category } : c))
                    )
                } else {
                    refreshMetadataRegistry()
                }
            } else {
                setSuccessMessage(data.error ?? 'Failed to upgrade.')
                setTimeout(() => setSuccessMessage(null), 5000)
            }
        } catch (err) {
            setSuccessMessage('Network error. Please try again.')
            setTimeout(() => setSuccessMessage(null), 5000)
        } finally {
            setUpgradeLoading(false)
        }
    }

    // Open upgrade modal for category (from list)
    const handleUpgradeClick = (category) => {
        setSelectedCategoryId(category.id)
        setUpgradeModalOpen(true)
    }

    // Revert system category to default (from list)
    const handleRevertClick = (category) => {
        setCategoryToRevert(category)
        setConfirmRevertOpen(true)
    }
    const handleRevertConfirm = async () => {
        if (!categoryToRevert?.id) return
        setCopyOrResetLoading(true)
        try {
            const response = await fetch(`/app/api/tenant/metadata/categories/${categoryToRevert.id}/reset`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
            })
            const data = await response.json().catch(() => ({}))
            if (response.ok) {
                setSuccessMessage(data.message ?? 'Reverted to system default.')
                setTimeout(() => setSuccessMessage(null), 4000)
                setCategoryToRevert(null)
                setConfirmRevertOpen(false)
                refreshMetadataRegistry({ onSuccess: () => setFieldCategoryData({}) })
            } else {
                setSuccessMessage(data.error ?? 'Failed to revert.')
                setTimeout(() => setSuccessMessage(null), 5000)
            }
        } catch (err) {
            setSuccessMessage('Network error. Please try again.')
            setTimeout(() => setSuccessMessage(null), 5000)
        } finally {
            setCopyOrResetLoading(false)
        }
    }

    // Phase 2: Open "Apply to other brands" confirm (fetch targets first)
    const handleApplyToOtherBrandsClick = async () => {
        if (!canManageVisibility || !selectedCategoryId || !selectedCategory) return
        setApplyOtherBrandsLoading(true)
        try {
            const response = await fetch(`/app/api/tenant/metadata/categories/${selectedCategoryId}/apply-to-other-brands`, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
            const data = await response.json().catch(() => ({}))
            const targets = data.targets ?? []
            if (targets.length === 0) {
                setSuccessMessage('No other brands have a category of this type.')
                setTimeout(() => setSuccessMessage(null), 4000)
                return
            }
            setApplyOtherBrandsTargets(targets)
            setConfirmApplyOtherBrandsOpen(true)
        } catch (err) {
            setSuccessMessage('Failed to load target categories.')
            setTimeout(() => setSuccessMessage(null), 5000)
        } finally {
            setApplyOtherBrandsLoading(false)
        }
    }

    // Phase 2: Apply current category settings to other brands (called after confirm)
    const handleApplyToOtherBrandsConfirm = async () => {
        if (!canManageVisibility || !selectedCategoryId) return
        setCopyOrResetLoading(true)
        try {
            const response = await fetch(`/app/api/tenant/metadata/categories/${selectedCategoryId}/apply-to-other-brands`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
            })
            const data = await response.json().catch(() => ({}))
            if (response.ok) {
                setSuccessMessage(data.message ?? 'Settings applied to other brands.')
                setTimeout(() => setSuccessMessage(null), 4000)
                setApplyOtherBrandsTargets([])
                setConfirmApplyOtherBrandsOpen(false)
                refreshMetadataRegistry({ onSuccess: () => setFieldCategoryData({}) })
            } else {
                setSuccessMessage(data.error ?? 'Failed to apply to other brands.')
                setTimeout(() => setSuccessMessage(null), 5000)
            }
        } catch (err) {
            setSuccessMessage('Network error. Please try again.')
            setTimeout(() => setSuccessMessage(null), 5000)
        } finally {
            setCopyOrResetLoading(false)
        }
    }

    // Phase 3a: Fetch profiles for Apply profile dropdown
    const fetchProfiles = useCallback(async () => {
        setProfilesLoading(true)
        try {
            const url = selectedBrandId
                ? `/app/api/tenant/metadata/profiles?brand_id=${selectedBrandId}`
                : '/app/api/tenant/metadata/profiles'
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
            const data = await response.json().catch(() => ({}))
            setProfiles(data.profiles ?? [])
        } catch (err) {
            setProfiles([])
        } finally {
            setProfilesLoading(false)
        }
    }, [selectedBrandId])

    // Phase 3a: Save current category as named profile
    const handleSaveProfileSubmit = async () => {
        if (!canManageVisibility || !selectedCategoryId || !selectedCategory || !saveProfileName.trim()) return
        setSaveProfileLoading(true)
        try {
            const response = await fetch('/app/api/tenant/metadata/profiles', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: saveProfileName.trim(),
                    category_id: selectedCategoryId,
                    brand_id: profileAvailableToAllBrands ? null : (selectedBrandId ?? undefined),
                }),
            })
            const data = await response.json().catch(() => ({}))
            if (response.ok) {
                setSuccessMessage(data.message || 'Profile saved.')
                setTimeout(() => setSuccessMessage(null), 4000)
                setAdvancedSettingsOpen(false)
                setSaveProfileName('')
                setProfileAvailableToAllBrands(false)
                setProfiles(prev => [...prev, data.profile].filter(Boolean))
            } else {
                setSuccessMessage(data.error || 'Failed to save profile.')
                setTimeout(() => setSuccessMessage(null), 5000)
            }
        } catch (err) {
            setSuccessMessage('Network error. Please try again.')
            setTimeout(() => setSuccessMessage(null), 5000)
        } finally {
            setSaveProfileLoading(false)
        }
    }

    // Phase 3a: Preview profile (see result without saving)
    const handlePreviewProfile = async () => {
        if (!applyProfileId || !selectedCategoryId) return
        try {
            const response = await fetch(`/app/api/tenant/metadata/profiles/${applyProfileId}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
            const data = await response.json().catch(() => ({}))
            if (response.ok && data.profile) {
                setPreviewSnapshot(data.profile.snapshot ?? [])
                setPreviewProfileName(data.profile.name ?? 'Profile')
            } else {
                setSuccessMessage(data.error || 'Failed to load profile.')
                setTimeout(() => setSuccessMessage(null), 4000)
            }
        } catch (err) {
            setSuccessMessage('Failed to load profile.')
            setTimeout(() => setSuccessMessage(null), 4000)
        }
    }

    const handleRevertPreview = () => {
        setPreviewSnapshot(null)
        setPreviewProfileName(null)
    }

    // Phase 3a: Open Apply profile confirm (need profile selected)
    const handleApplyProfileClick = async () => {
        if (!applyProfileId || !canManageVisibility || !selectedCategoryId || !selectedCategory) return
        setConfirmApplyProfileOpen(true)
    }

    // Phase 3a: Apply selected profile to current category (called after confirm)
    const handleApplyProfileConfirm = async () => {
        if (!applyProfileId || !canManageVisibility || !selectedCategoryId) return
        setCopyOrResetLoading(true)
        try {
            const response = await fetch(`/app/api/tenant/metadata/profiles/${applyProfileId}/apply`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ category_id: selectedCategoryId }),
            })
            const data = await response.json().catch(() => ({}))
            if (response.ok) {
                setSuccessMessage(data.message ?? 'Profile applied.')
                setTimeout(() => setSuccessMessage(null), 4000)
                setApplyProfileId(null)
                setConfirmApplyProfileOpen(false)
                setPreviewSnapshot(null)
                setPreviewProfileName(null)
                refreshMetadataRegistry({ onSuccess: () => setFieldCategoryData({}) })
            } else {
                setSuccessMessage(data.error ?? 'Failed to apply profile.')
                setTimeout(() => setSuccessMessage(null), 5000)
            }
        } catch (err) {
            setSuccessMessage('Network error. Please try again.')
            setTimeout(() => setSuccessMessage(null), 5000)
        } finally {
            setCopyOrResetLoading(false)
        }
    }

    return (
        <div className="flex flex-col lg:flex-row gap-6 min-h-0">
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

            {/* LEFT PANEL — Brand Content Structure (sticky) */}
            <div className="lg:w-80 flex-shrink-0 lg:sticky lg:top-6 lg:self-start overflow-y-auto scrollbar-thin max-h-[calc(100vh-8rem)]">
                <div className="rounded-lg bg-white shadow-sm border border-gray-100 p-5">
                    <h2 className="text-base font-semibold text-gray-900 mb-4">Brand Content Structure</h2>
                    {brands.length > 1 && (
                        <div className="mb-4">
                            <label htmlFor="by-category-brand" className="block text-xs font-medium text-gray-500 uppercase tracking-wider mb-1.5">
                                Brand
                            </label>
                            <select
                                id="by-category-brand"
                                value={selectedBrandId ?? ''}
                                onChange={(e) => handleBrandChange(e.target.value ? parseInt(e.target.value, 10) : null)}
                                className="w-full rounded-lg border border-gray-100 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
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
                    <CategoryList
                        groupedCategories={groupedCategories}
                        selectedCategoryId={selectedCategoryId}
                        onSelectCategory={handleSelectCategory}
                        canManageVisibility={canManageVisibility}
                        brandId={brandId}
                        onCategoriesChange={onCategoriesChange}
                        onRename={(cat) => { setEditingCategoryId(cat.id); setEditingCategoryName(cat.name) }}
                        onCategorySettingsClick={handleCategorySettingsClick}
                        onDelete={(cat) => { setCategoryToDelete(cat); setConfirmDeleteOpen(true) }}
                        onRevert={handleRevertClick}
                        onUpgrade={handleUpgradeClick}
                        onSaveRename={(cat, name) => handleRenameCategory(cat, name)}
                        onCancelRename={() => { setEditingCategoryId(null); setEditingCategoryName('') }}
                        onAddCategory={brandId ? handleAddCategory : undefined}
                        editingCategoryId={editingCategoryId}
                        editingCategoryName={editingCategoryName}
                        onEditingCategoryNameChange={setEditingCategoryName}
                    />
                </div>
            </div>

            {/* RIGHT PANEL */}
            <div className="flex-1 min-w-0 min-h-[480px]">
                {!selectedCategory ? (
                    <div className="relative flex items-center justify-center min-h-[320px] p-6">
                        {/* Ambient background */}
                        <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                            <div className="absolute w-96 h-96 rounded-full bg-gradient-to-br from-purple-100/40 to-transparent blur-2xl opacity-40" aria-hidden />
                        </div>
                        {/* Card */}
                        <div className="relative rounded-xl bg-white shadow-sm p-10 text-center max-w-[480px]">
                            <FolderIcon className="mx-auto h-12 w-12 text-purple-400/70 mb-5" aria-hidden />
                            <h3 className="text-xl font-semibold text-gray-900">Select a category</h3>
                            <p className="mt-2 text-sm text-gray-500">Configure how metadata fields behave for each content type.</p>
                            {brandId && (
                                <button
                                    type="button"
                                    onClick={handleAddCategory}
                                    className="mt-6 inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-indigo-600 to-purple-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:shadow-md hover:shadow-purple-500/25 transition-shadow focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                >
                                    <PlusIcon className="h-4 w-4" />
                                    Create Category
                                </button>
                            )}
                        </div>
                    </div>
                ) : (
                    <div
                        key={selectedCategoryId}
                        className="space-y-6 p-6 animate-fade-slide-in"
                    >
                        {/* Category Header */}
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h2 className="text-3xl font-semibold tracking-tight text-gray-900">{selectedCategory.name}</h2>
                                <div className="mt-2 flex items-center gap-1.5 flex-wrap">
                                    {selectedCategory.is_system && (
                                        <span className="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-normal text-gray-500 bg-gray-100">
                                            System{selectedCategory.system_version ? ` · v${selectedCategory.system_version}` : ''}
                                        </span>
                                    )}
                                    {(selectedCategory.upgrade_available || selectedCategory.has_update_available) && selectedCategory.is_system && (
                                        <>
                                            <span className="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-normal text-amber-700 bg-gray-100">Update Available</span>
                                            <button
                                                type="button"
                                                onClick={() => setUpgradeModalOpen(true)}
                                                className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-normal text-amber-700 bg-gray-100 hover:bg-amber-50 transition-colors"
                                            >
                                                <ArrowUpCircleIcon className="h-3 w-3" />
                                                Upgrade
                                            </button>
                                        </>
                                    )}
                                    {selectedCategory.is_customized && (
                                        <span className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-normal text-gray-500 bg-gray-100" title="Customized from system default">
                                            <span className="w-1 h-1 rounded-full bg-gray-400" aria-hidden />
                                            Customized
                                        </span>
                                    )}
                                </div>
                            </div>
                            {canManageVisibility && (
                                <div className="relative">
                                    <button
                                        type="button"
                                        onClick={() => setCategoryMenuOpen((o) => !o)}
                                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                                        aria-expanded={categoryMenuOpen}
                                        aria-haspopup="true"
                                    >
                                        <EllipsisVerticalIcon className="w-5 h-5 text-gray-500" />
                                        Options
                                    </button>
                                    {categoryMenuOpen && (
                                        <>
                                            <div className="fixed inset-0 z-10" onClick={() => setCategoryMenuOpen(false)} aria-hidden />
                                            <div className="absolute right-0 top-full mt-1 w-56 rounded-lg bg-white py-1 shadow-lg ring-1 ring-black/5 z-20">
                                                <button
                                                    type="button"
                                                    onClick={() => { setCategoryMenuOpen(false); setAdvancedSettingsOpen(true) }}
                                                    className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50"
                                                >
                                                    <Squares2X2Icon className="h-4 w-4 text-gray-400" />
                                                    Advanced Settings
                                                </button>
                                                {selectedCategory && !selectedCategory.is_system && (
                                                    <button
                                                        type="button"
                                                        onClick={() => { setCategoryMenuOpen(false); setCategorySettingsCategory(selectedCategory); setCategorySettingsOpen(true) }}
                                                        className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50"
                                                    >
                                                        <PencilIcon className="h-4 w-4 text-gray-400" />
                                                        Category Settings
                                                    </button>
                                                )}
                                            </div>
                                        </>
                                    )}
                                </div>
                            )}
                        </div>
                        <div className="mt-4 border-b border-gray-200" aria-hidden />
                        {previewProfileName && (
                            <div className="rounded-lg bg-amber-50/80 border border-amber-200/60 px-4 py-2 flex items-center justify-between">
                                <span className="text-sm text-amber-800">
                                    Previewing profile &quot;{previewProfileName}&quot; — not saved. Apply to save, or Revert to cancel.
                                </span>
                                <div className="flex items-center gap-2">
                                    <button type="button" onClick={handleRevertPreview} className="text-sm font-medium text-amber-700 hover:text-amber-900">Revert</button>
                                    <button type="button" onClick={() => { setConfirmApplyProfileOpen(true) }} disabled={copyOrResetLoading} className="rounded-md bg-amber-600 px-2.5 py-1.5 text-sm font-medium text-white hover:bg-amber-500 disabled:opacity-50">Apply to save</button>
                                </div>
                            </div>
                        )}

                        {/* Fields content — premium card */}
                        <div className="rounded-xl border border-gray-200 bg-white shadow-md overflow-hidden">
                            <div className="px-8 py-6 space-y-0">
                        {/* Enabled Fields */}
                        <div className="mt-0 first:mt-0">
                            <div className="flex items-center justify-between mb-3">
                                <h3 className="text-sm font-medium text-gray-700">
                                    Enabled Fields ({getFieldsForCategory.enabled.length})
                                </h3>
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
                            <div className="space-y-0.5">
                                {getFieldsForCategory.enabled.length > 0 || (fieldsByFamily.typeFamilyEnabled.length + fieldsByFamily.typeFamilyAvailable.length) > 0 ? (
                                    <>
                                        <div className="flex items-center px-3 py-1.5 text-xs font-medium text-gray-500" aria-hidden>
                                            <div className="flex-1" />
                                            <span>Status</span>
                                        </div>
                                        {typeFamilyConfig && (fieldsByFamily.typeFamilyEnabled.length > 0 || fieldsByFamily.typeFamilyAvailable.length > 0) && (
                                            <FamilyRow
                                                familyKey="type"
                                                familyConfig={typeFamilyConfig}
                                                enabledMembers={fieldsByFamily.typeFamilyEnabled}
                                                availableMembers={fieldsByFamily.typeFamilyAvailable}
                                                categoryId={selectedCategoryId}
                                                expanded={!!expandedFamilies['type']}
                                                onToggleExpand={() => toggleFamilyExpanded('type')}
                                                wrapToggle={wrapToggle}
                                                onVisibilityToggle={toggleVisibility}
                                                onPrimaryToggle={togglePrimary}
                                                onRequiredToggle={toggleRequired}
                                                onAiEligibleToggle={toggleAiEligible}
                                                onEdit={canManageFields ? handleEditField : null}
                                                canManage={canManageVisibility && !previewProfileName}
                                                canManageFields={canManageFields}
                                                systemFields={systemFields}
                                                fieldCategoryData={fieldCategoryData}
                                                previewOverlay={previewOverlay}
                                                highlightedFieldId={highlightedFieldId}
                                            />
                                        )}
                                        <DndContext
                                            sensors={sensors}
                                            collisionDetection={closestCenter}
                                            onDragEnd={handleEnabledFieldsDragEnd}
                                        >
                                            <SortableContext
                                                items={fieldsByFamily.otherEnabled.map(f => f.id)}
                                                strategy={verticalListSortingStrategy}
                                            >
                                                {fieldsByFamily.otherEnabled.map((field) => (
                                                    <SortableFieldRow
                                                        key={field.id}
                                                        field={field}
                                                        categoryId={selectedCategoryId}
                                                        isEnabled={true}
                                                        onToggle={wrapToggle(field, selectedCategoryId)}
                                                        onVisibilityToggle={toggleVisibility}
                                                        onPrimaryToggle={togglePrimary}
                                                        onRequiredToggle={toggleRequired}
                                                        onAiEligibleToggle={toggleAiEligible}
                                                        onEdit={canManageFields ? handleEditField : null}
                                                        onArchive={tenantFieldIds.has(field.id) ? handleArchiveField : null}
                                                        canManage={canManageVisibility && !previewProfileName}
                                                        canManageFields={canManageFields}
                                                        systemFields={systemFields}
                                                        fieldCategoryData={previewOverlay[field.id] ?? fieldCategoryData[field.id]}
                                                        isHighlighted={field.id === highlightedFieldId}
                                                    />
                                                ))}
                                            </SortableContext>
                                        </DndContext>
                                        {fieldReorderLoading && (
                                            <p className="text-xs text-gray-400 mt-1">Saving order…</p>
                                        )}
                                    </>
                                ) : (
                                    <div className="px-4 py-4 text-center text-sm text-gray-500">
                                        No fields enabled.
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* System Automated Fields — collapsed by default */}
                        {(getFieldsForCategory.enabledAutomated.length > 0 || getFieldsForCategory.availableAutomated.length > 0) && (
                            <div className="mt-8">
                                <button
                                    type="button"
                                    onClick={() => setExpandedAutomated(v => !v)}
                                    className="w-full flex items-center justify-between gap-2 text-left py-1.5 -mx-1 px-1 rounded hover:bg-gray-50/50 transition-colors"
                                >
                                    <h3 className="text-sm font-medium text-gray-700">
                                        System Automated ({getFieldsForCategory.enabledAutomated.length + getFieldsForCategory.availableAutomated.length})
                                    </h3>
                                    {expandedAutomated ? <ChevronDownIcon className="w-4 h-4 text-gray-400" /> : <ChevronRightIcon className="w-4 h-4 text-gray-400" />}
                                </button>
                                {expandedAutomated && (
                                <div className="space-y-0.5 mt-2">
                                    {getFieldsForCategory.enabledAutomated.map(field => (
                                        <AutomatedFieldRow
                                            key={field.id}
                                            field={field}
                                            categoryId={selectedCategoryId}
                                            isEnabled={true}
                                            onToggle={wrapToggle(field, selectedCategoryId)}
                                            onVisibilityToggle={toggleVisibility}
                                            canManage={canManageVisibility && !previewProfileName}
                                            systemFields={systemFields}
                                            fieldCategoryData={previewOverlay[field.id] ?? fieldCategoryData[field.id]}
                                        />
                                    ))}
                                    {getFieldsForCategory.availableAutomated.map(field => (
                                        <AutomatedFieldRow
                                            key={field.id}
                                            field={field}
                                            categoryId={selectedCategoryId}
                                            isEnabled={false}
                                            onToggle={wrapToggle(field, selectedCategoryId)}
                                            onVisibilityToggle={toggleVisibility}
                                            canManage={canManageVisibility && !previewProfileName}
                                            systemFields={systemFields}
                                            fieldCategoryData={previewOverlay[field.id] ?? fieldCategoryData[field.id]}
                                        />
                                    ))}
                                </div>
                                )}
                            </div>
                        )}

                        {/* Available Fields — collapsed by default */}
                        {fieldsByFamily.otherAvailable.length > 0 && (
                            <div className="mt-8">
                                <button
                                    type="button"
                                    onClick={() => setExpandedAvailable(v => !v)}
                                    className="w-full flex items-center justify-between gap-2 text-left py-1.5 -mx-1 px-1 rounded hover:bg-gray-50/50 transition-colors"
                                >
                                    <h3 className="text-sm font-medium text-gray-700">
                                        Available ({fieldsByFamily.otherAvailable.length})
                                    </h3>
                                    {expandedAvailable ? <ChevronDownIcon className="w-4 h-4 text-gray-400" /> : <ChevronRightIcon className="w-4 h-4 text-gray-400" />}
                                </button>
                                {expandedAvailable && (
                                <div className="space-y-0.5 mt-2">
                                    {fieldsByFamily.otherAvailable.map(field => (
                                        <FieldRow
                                            key={field.id}
                                            field={field}
                                            categoryId={selectedCategoryId}
                                            isEnabled={false}
                                            onToggle={wrapToggle(field, selectedCategoryId)}
                                            onVisibilityToggle={toggleVisibility}
                                            onPrimaryToggle={togglePrimary}
                                            onRequiredToggle={toggleRequired}
                                            onAiEligibleToggle={toggleAiEligible}
                                            canManage={canManageVisibility && !previewProfileName}
                                            systemFields={systemFields}
                                            fieldCategoryData={previewOverlay[field.id] ?? fieldCategoryData[field.id]}
                                            isDraggable={false}
                                            isHighlighted={field.id === highlightedFieldId}
                                        />
                                    ))}
                                </div>
                                )}
                            </div>
                        )}

                        {/* Custom Fields — empty state when no custom fields exist yet */}
                        {canManageFields && tenant_fields.length === 0 && (
                            <div className="mt-8">
                                <div className="py-12 flex justify-center">
                                    <div className="max-w-md w-full rounded-lg border border-dashed border-gray-200 bg-gray-50/50 px-6 py-8 text-center">
                                        <p className="text-sm text-gray-500 mb-3">
                                            Custom fields let you define structured metadata specific to this category.
                                        </p>
                                        <button
                                            type="button"
                                            onClick={() => { setEditingField(null); setModalOpen(true) }}
                                            disabled={customFieldsLimit && !customFieldsLimit.can_create}
                                            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                            title={customFieldsLimit && !customFieldsLimit.can_create
                                                ? `Plan limit reached (${customFieldsLimit.current}/${customFieldsLimit.max}). Upgrade to create more fields.`
                                                : 'Add custom metadata field'}
                                        >
                                            <PlusIcon className="h-4 w-4" />
                                            Add Custom Field
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* View Archived Fields toggle — at bottom of page */}
                        <div className="mt-8 pt-6 border-t border-gray-200">
                            <button
                                type="button"
                                onClick={() => setShowArchived((v) => !v)}
                                className="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 transition-colors"
                            >
                                {showArchived ? <ChevronDownIcon className="w-4 h-4" /> : <ChevronRightIcon className="w-4 h-4" />}
                                View Archived Fields
                            </button>
                            {showArchived && (
                                <div className="mt-4 rounded-lg border border-gray-200 bg-gray-50/50 p-4">
                                    {archivedLoading ? (
                                        <p className="text-sm text-gray-500">Loading archived fields…</p>
                                    ) : archivedFields.length === 0 ? (
                                        <p className="text-sm text-gray-500">No archived fields.</p>
                                    ) : (
                                        <div className="space-y-2">
                                            <p className="text-xs font-medium text-gray-500 uppercase tracking-wider">Archived ({archivedFields.length})</p>
                                            <ul className="space-y-2">
                                                {archivedFields.map((field) => (
                                                    <li
                                                        key={field.id}
                                                        className="flex items-center justify-between gap-4 py-2 px-3 rounded-md bg-white/80 border border-gray-100 text-sm"
                                                    >
                                                        <span className="text-gray-600 truncate">
                                                            {field.system_label || field.key || 'Unnamed Field'}
                                                        </span>
                                                        <div className="flex items-center gap-2 flex-shrink-0">
                                                            <button
                                                                type="button"
                                                                onClick={() => handleRestoreField(field)}
                                                                disabled={restoreLoading}
                                                                className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                                            >
                                                                <ArrowUpCircleIcon className="w-3.5 h-3.5" />
                                                                Restore
                                                            </button>
                                                            <button
                                                                type="button"
                                                                disabled
                                                                title="Permanently delete (coming soon)"
                                                                className="inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-xs font-medium text-gray-400 cursor-not-allowed"
                                                            >
                                                                <TrashIcon className="w-3.5 h-3.5" />
                                                                Permanently delete
                                                            </button>
                                                        </div>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* Copy settings confirmation */}
            <ConfirmDialog
                open={confirmCopyOpen}
                onClose={() => setConfirmCopyOpen(false)}
                onConfirm={handleCopyFromConfirm}
                title="Copy settings"
                message={copyFromSourceId && selectedCategory
                    ? `Copy settings from "${categoriesForBrand.find(c => c.id === copyFromSourceId)?.name ?? ''}" to "${selectedCategory.name}"? This will overwrite current visibility for ${selectedCategory.name}.`
                    : ''}
                confirmText="OK"
                cancelText="Cancel"
                variant="warning"
                loading={copyOrResetLoading}
            />
            {/* Reset to default confirmation */}
            <ConfirmDialog
                open={confirmResetOpen}
                onClose={() => setConfirmResetOpen(false)}
                onConfirm={handleResetConfirm}
                title="Reset to default"
                message={selectedCategory
                    ? `Reset visibility for "${selectedCategory.name}" to default? This removes all category-level overrides.`
                    : ''}
                confirmText="OK"
                cancelText="Cancel"
                variant="warning"
                loading={copyOrResetLoading}
            />
            {/* Apply profile confirmation */}
            <ConfirmDialog
                open={confirmApplyProfileOpen}
                onClose={() => setConfirmApplyProfileOpen(false)}
                onConfirm={handleApplyProfileConfirm}
                title="Apply profile"
                message={applyProfileId && selectedCategory && profiles.find(p => p.id === applyProfileId)
                    ? `Apply profile "${profiles.find(p => p.id === applyProfileId)?.name}" to "${selectedCategory.name}"? This will overwrite current visibility for this category.`
                    : ''}
                confirmText="Apply"
                cancelText="Cancel"
                variant="warning"
                loading={copyOrResetLoading}
            />
            {/* Delete category confirmation */}
            <ConfirmDialog
                open={confirmDeleteOpen}
                onClose={() => { setConfirmDeleteOpen(false); setCategoryToDelete(null) }}
                onConfirm={handleDeleteCategory}
                title="Delete category"
                message={categoryToDelete ? `Delete "${categoryToDelete.name}"? This cannot be undone.` : ''}
                confirmText="Delete"
                cancelText="Cancel"
                variant="danger"
            />
            {/* Revert to system default confirmation */}
            <ConfirmDialog
                open={confirmRevertOpen}
                onClose={() => { setConfirmRevertOpen(false); setCategoryToRevert(null) }}
                onConfirm={handleRevertConfirm}
                title="Revert to System"
                message={categoryToRevert ? `Revert "${categoryToRevert.name}" to system default? This removes all category-level visibility overrides.` : ''}
                confirmText="Revert"
                cancelText="Cancel"
                variant="warning"
                loading={copyOrResetLoading}
            />
            {/* Apply to other brands confirmation */}
            <ConfirmDialog
                open={confirmApplyOtherBrandsOpen}
                onClose={() => {
                    setConfirmApplyOtherBrandsOpen(false)
                    setApplyOtherBrandsTargets([])
                }}
                onConfirm={handleApplyToOtherBrandsConfirm}
                title="Apply to other brands"
                message={applyOtherBrandsTargets.length > 0 && selectedCategory
                    ? `Apply "${selectedCategory.name}" settings to the same category type in ${applyOtherBrandsTargets.length} other brand(s)? This will overwrite current visibility for: ${applyOtherBrandsTargets.map(t => `${t.brand_name} – ${t.category_name}`).join(', ')}.`
                    : ''}
                confirmText="Apply"
                cancelText="Cancel"
                variant="warning"
                loading={copyOrResetLoading}
            />
            {/* Disable core field confirmation */}
            <ConfirmDialog
                open={confirmDisableCoreOpen}
                onClose={() => { setConfirmDisableCoreOpen(false); setPendingDisable(null) }}
                onConfirm={handleConfirmDisableCore}
                title="Disable Core Field?"
                message="Disabling this field may affect filtering, organization, and AI suggestions."
                confirmText="Disable"
                cancelText="Cancel"
                variant="warning"
            />
            {/* Archive custom field confirmation */}
            <ArchiveFieldModal
                open={confirmArchiveOpen}
                onClose={() => { setConfirmArchiveOpen(false); setPendingArchiveField(null); setArchiveRemoveFromAssets(false) }}
                onConfirm={handleConfirmArchive}
                fieldLabel={pendingArchiveField?.label || pendingArchiveField?.system_label || pendingArchiveField?.key}
                loading={archiveLoading}
                removeFromAssets={archiveRemoveFromAssets}
                onRemoveFromAssetsChange={setArchiveRemoveFromAssets}
            />
            {/* Advanced Settings slide-over */}
            <AdvancedSettingsSlideOver
                isOpen={advancedSettingsOpen}
                onClose={() => setAdvancedSettingsOpen(false)}
                categoriesForBrand={categoriesForBrand}
                selectedCategoryId={selectedCategoryId}
                copyFromSourceId={copyFromSourceId}
                setCopyFromSourceId={setCopyFromSourceId}
                onCopySettings={() => {
                    setAdvancedSettingsOpen(false)
                    setConfirmCopyOpen(true)
                }}
                onReset={() => {
                    setAdvancedSettingsOpen(false)
                    setConfirmResetOpen(true)
                }}
                onSaveProfile={handleSaveProfileSubmit}
                onApplyProfile={() => {
                    setAdvancedSettingsOpen(false)
                    handleApplyProfileClick()
                }}
                onPreviewProfile={async () => {
                    setAdvancedSettingsOpen(false)
                    await handlePreviewProfile()
                }}
                onApplyToOtherBrands={brands.length > 1 ? () => {
                    setAdvancedSettingsOpen(false)
                    handleApplyToOtherBrandsClick()
                } : undefined}
                profiles={profiles}
                applyProfileId={applyProfileId}
                setApplyProfileId={setApplyProfileId}
                fetchProfiles={fetchProfiles}
                saveProfileName={saveProfileName}
                setSaveProfileName={setSaveProfileName}
                profileAvailableToAllBrands={profileAvailableToAllBrands}
                setProfileAvailableToAllBrands={setProfileAvailableToAllBrands}
                brands={brands}
                loading={copyOrResetLoading}
                saveProfileLoading={saveProfileLoading}
            />
            {/* Upgrade category modal */}
            <UpgradeCategoryModal
                isOpen={upgradeModalOpen}
                onClose={() => setUpgradeModalOpen(false)}
                category={selectedCategory}
                brandId={brandId}
                onConfirm={handleUpgradeConfirm}
                loading={upgradeLoading}
            />

            {/* Add Category Slide-over */}
            <AddCategorySlideOver
                isOpen={addCategoryOpen}
                onClose={() => setAddCategoryOpen(false)}
                brandId={brandId}
                brandName={brands.find(b => b.id === brandId)?.name ?? ''}
                categoryLimits={brands.find(b => b.id === brandId)?.category_limits ?? null}
                onSuccess={handleAddCategorySuccess}
            />

            {/* Category Settings Slide-over */}
            <CategorySettingsSlideOver
                isOpen={categorySettingsOpen}
                onClose={() => { setCategorySettingsOpen(false); setCategorySettingsCategory(null) }}
                category={categorySettingsCategory}
                brandId={brandId}
                brandRoles={categoryFormData.brand_roles}
                brandUsers={categoryFormData.brand_users}
                onSuccess={handleCategorySettingsSuccess}
                onDelete={handleCategorySettingsDelete}
            />

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
 * Family Row Component (Phase K)
 *
 * Collapsible row for a field family. Shows family label, system badge, shared visibility toggles.
 * When expanded, shows member fields (enabled then available) as individual FieldRows.
 * Family-level toggle applies to all member fields.
 */
function FamilyRow({
    familyKey,
    familyConfig,
    enabledMembers,
    availableMembers,
    categoryId,
    expanded,
    onToggleExpand,
    wrapToggle,
    onVisibilityToggle,
    onPrimaryToggle,
    onRequiredToggle,
    onAiEligibleToggle,
    onEdit,
    canManage,
    canManageFields,
    systemFields,
    fieldCategoryData,
    previewOverlay,
    highlightedFieldId,
}) {
    const label = familyConfig?.label || familyKey
    const members = [...enabledMembers, ...availableMembers]

    const getEffective = (field, context) => {
        const categoryData = previewOverlay?.[field.id] ?? fieldCategoryData?.[field.id]
        const override = categoryId && categoryData?.overrides?.[categoryId]
        if (context === 'upload') return override?.show_on_upload !== undefined ? override.show_on_upload : (field.effective_show_on_upload ?? field.show_on_upload ?? true)
        if (context === 'edit') return override?.show_on_edit !== undefined ? override.show_on_edit : (field.effective_show_on_edit ?? field.show_on_edit ?? true)
        if (context === 'filter') return override?.show_in_filters !== undefined ? override.show_in_filters : (field.effective_show_in_filters ?? field.show_in_filters ?? true)
        return true
    }

    const uploads = members.map(f => getEffective(f, 'upload'))
    const edits = members.map(f => getEffective(f, 'edit'))
    const filters = members.map(f => getEffective(f, 'filter'))
    const allUpload = uploads.length > 0 && uploads.every(Boolean)
    const noUpload = uploads.length > 0 && uploads.every(v => !v)
    const allEdit = edits.length > 0 && edits.every(Boolean)
    const noEdit = edits.length > 0 && edits.every(v => !v)
    const allFilter = filters.length > 0 && filters.every(Boolean)
    const noFilter = filters.length > 0 && filters.every(v => !v)
    const mixedUpload = uploads.length > 0 && !allUpload && !noUpload
    const mixedEdit = edits.length > 0 && !allEdit && !noEdit
    const mixedFilter = filters.length > 0 && !allFilter && !noFilter

    const handleFamilyVisibility = (context, currentCommon) => {
        const newVal = !currentCommon
        members.forEach(field => {
            const cur = getEffective(field, context)
            if (cur !== newVal) {
                const key = context === 'upload' ? 'upload' : context === 'edit' ? 'edit' : 'filter'
                onVisibilityToggle(field.id, key, cur)
            }
        })
    }

    return (
        <div className="rounded-md overflow-hidden text-sm">
            <div
                className="group px-3 py-1.5 flex items-center gap-2 cursor-pointer hover:bg-gray-50/80 transition-all duration-200 ease-out rounded-md"
                onClick={onToggleExpand}
                role="button"
                tabIndex={0}
                onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onToggleExpand() } }}
                aria-expanded={expanded}
            >
                <span className="flex-shrink-0 text-gray-500">
                    {expanded ? <ChevronDownIcon className="w-4 h-4" /> : <ChevronRightIcon className="w-4 h-4" />}
                </span>
                <span className="w-1.5 h-1.5 rounded-full bg-gray-400 flex-shrink-0" aria-hidden title="System field group" />
                <span className="text-sm font-medium text-gray-900">{label}</span>
                <span className="text-xs text-gray-500" title={`${enabledMembers.length} enabled; ${availableMembers.length} available`}>
                    {enabledMembers.length} enabled · {availableMembers.length} available
                </span>
                <div className="flex-1" />
                <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-70 focus-within:opacity-70 transition-opacity duration-200" onClick={e => e.stopPropagation()}>
                    <button
                        type="button"
                        onClick={() => canManage && members.length > 0 && handleFamilyVisibility('upload', allUpload)}
                        disabled={!canManage || members.length === 0}
                        title={mixedUpload ? 'Some fields differ. Click to set all.' : 'Upload: Show in upload form when adding assets'}
                        className={`w-8 h-8 flex items-center justify-center rounded-full transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${allUpload ? 'text-indigo-600 bg-indigo-100' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'}`}
                    >
                        <CloudArrowUpIcon className="w-4 h-4" />
                    </button>
                    <button
                        type="button"
                        onClick={() => canManage && members.length > 0 && handleFamilyVisibility('edit', allEdit)}
                        disabled={!canManage || members.length === 0}
                        title={mixedEdit ? 'Some fields differ. Click to set all.' : 'Quick View: Show in asset details drawer and modal'}
                        className={`w-8 h-8 flex items-center justify-center rounded-full transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${allEdit ? 'text-indigo-600 bg-indigo-100' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'}`}
                    >
                        <EyeIcon className="w-4 h-4" />
                    </button>
                    <button
                        type="button"
                        onClick={() => canManage && members.length > 0 && handleFamilyVisibility('filter', allFilter)}
                        disabled={!canManage || members.length === 0}
                        title={mixedFilter ? 'Some fields differ. Click to set all.' : 'Filter: Show in asset grid filter bar'}
                        className={`w-8 h-8 flex items-center justify-center rounded-full transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${allFilter ? 'text-indigo-600 bg-indigo-100' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'}`}
                    >
                        <FunnelIcon className="w-4 h-4" />
                    </button>
                </div>
            </div>
            {expanded && (
                <div className="border-t border-gray-100/80 space-y-0.5 pt-2 mt-1">
                    {enabledMembers.map(field => (
                        <div key={field.id}>
                            <FieldRow
                                field={field}
                                categoryId={categoryId}
                                isEnabled={true}
                                onToggle={wrapToggle(field, categoryId)}
                                onVisibilityToggle={onVisibilityToggle}
                                onPrimaryToggle={onPrimaryToggle}
                                onRequiredToggle={onRequiredToggle}
                                onAiEligibleToggle={onAiEligibleToggle}
                                onEdit={onEdit}
                                canManage={canManage}
                                canManageFields={canManageFields}
                                systemFields={systemFields}
                                fieldCategoryData={previewOverlay?.[field.id] ?? fieldCategoryData?.[field.id]}
                                isDraggable={false}
                                isHighlighted={field.id === highlightedFieldId}
                            />
                        </div>
                    ))}
                    {availableMembers.map(field => (
                        <div key={field.id}>
                            <FieldRow
                                field={field}
                                categoryId={categoryId}
                                isEnabled={false}
                                onToggle={wrapToggle(field, categoryId)}
                                onVisibilityToggle={onVisibilityToggle}
                                onPrimaryToggle={onPrimaryToggle}
                                onRequiredToggle={onRequiredToggle}
                                onAiEligibleToggle={onAiEligibleToggle}
                                onEdit={onEdit}
                                canManage={canManage}
                                canManageFields={canManageFields}
                                systemFields={systemFields}
                                fieldCategoryData={previewOverlay?.[field.id] ?? fieldCategoryData?.[field.id]}
                                isDraggable={false}
                                isHighlighted={field.id === highlightedFieldId}
                            />
                        </div>
                    ))}
                </div>
            )}
        </div>
    )
}

/**
 * Sortable Field Row — wraps FieldRow with dnd-kit useSortable
 */
function SortableFieldRow(props) {
    const { field, canManage, ...rest } = props
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: field.id })

    const style = { transform: CSS.Transform.toString(transform), transition }

    return (
        <div ref={setNodeRef} style={style}>
            <div className="transition-transform duration-200 ease-out hover:scale-[1.01] origin-left">
                <FieldRow
                {...rest}
                field={field}
                isDraggable={!!canManage}
                isDragging={isDragging}
                dragHandleProps={canManage ? { ...attributes, ...listeners } : null}
            />
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
    onPrimaryToggle,
    onRequiredToggle,
    onAiEligibleToggle,
    onEdit,
    onArchive,
    canManage, 
    canManageFields = false,
    systemFields,
    fieldCategoryData,
    isDraggable = false,
    onDragStart,
    onDragOver,
    onDrop,
    onDragEnd,
    isDragging = false,
    dragHandleProps = null,
    isHighlighted = false,
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

    // Resolve effective_is_required for this category (category-scoped, like is_primary)
    const resolveRequired = (f, catId, categoryData) => {
        if (!catId || !categoryData) return f.is_required ?? false
        const override = categoryData.overrides?.[catId]
        if (override && override.is_required !== null && override.is_required !== undefined) {
            return override.is_required
        }
        return f.is_required ?? false
    }
    const effectiveIsRequired = categoryId && fieldCategoryData
        ? resolveRequired(field, categoryId, fieldCategoryData)
        : field.is_required ?? false

    const aiEligible = field.ai_eligible ?? false
    // dominant_hue_group: filter-only — user may only control is_filter_hidden
    const isFilterOnlyField = (field.key ?? '') === 'dominant_hue_group'

    const iconButtonClass = (active) =>
        `w-8 h-8 flex items-center justify-center rounded-full transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${
            active ? 'text-indigo-600 bg-indigo-100' : 'text-gray-400 opacity-60 hover:opacity-80'
        }`

    return (
        <div 
            className={`group px-3 py-1.5 rounded-md text-sm transition-colors duration-200 ease-out ${
                isDragging ? 'opacity-50' : ''
            } ${isEnabled ? 'bg-gray-50' : ''} ${isHighlighted ? 'bg-gray-100' : ''} ${!isEnabled ? 'hover:bg-gray-50/80' : ''}`}
            draggable={isDraggable && canManage && !isSystem}
            onDragStart={isDraggable && !isSystem ? (e) => onDragStart(e, field.id) : undefined}
            onDragOver={isDraggable && !isSystem ? onDragOver : undefined}
            onDrop={isDraggable && !isSystem ? (e) => onDrop(e, field.id) : undefined}
            onDragEnd={isDraggable && !isSystem ? onDragEnd : undefined}
        >
            <div className="flex items-center gap-2">
                {/* Drag Handle (only for enabled, non-system fields) */}
                {isDraggable && canManage && (
                    <div
                        className={`flex-shrink-0 text-gray-400 opacity-60 hover:opacity-100 transition-opacity ${isSystem ? 'cursor-not-allowed' : 'cursor-grab active:cursor-grabbing hover:text-gray-600'}`}
                        {...(dragHandleProps || {})}
                    >
                        <Bars3Icon className="w-4 h-4" aria-hidden />
                    </div>
                )}
                {/* Left: Field name */}
                <div className="flex-1 min-w-0 flex items-center gap-2">
                    {/* Field Name with System dot */}
                    <div className="flex items-center gap-2 min-w-0">
                        {isSystem && (
                            <span className="w-1.5 h-1.5 rounded-full bg-gray-400 flex-shrink-0" aria-hidden title="System field" />
                        )}
                        <span className="text-sm font-semibold text-gray-900 truncate">
                            {field.label || field.system_label || field.key || 'Unnamed Field'}
                        </span>
                    </div>

                    {/* Middle: Visibility icons — always visible; active = accent + soft bg, inactive = muted 60% */}
                    {isEnabled && (
                        <div className="flex items-center gap-0.5 flex-shrink-0 relative z-10" onClick={(e) => e.stopPropagation()}>
                            {!isFilterOnlyField && (
                                <button
                                    type="button"
                                    onClick={(e) => { e.stopPropagation(); canManage && onVisibilityToggle(field.id, 'upload', effectiveUpload) }}
                                    disabled={!canManage}
                                    title="Upload: Show in upload form when adding assets"
                                    className={iconButtonClass(effectiveUpload)}
                                >
                                    <CloudArrowUpIcon className="w-4 h-4" />
                                </button>
                            )}
                            {!isFilterOnlyField && (
                                <button
                                    type="button"
                                    onClick={(e) => { e.stopPropagation(); canManage && onVisibilityToggle(field.id, 'edit', effectiveEdit) }}
                                    disabled={!canManage}
                                    title="Quick View: Show in asset details drawer and modal"
                                    className={iconButtonClass(effectiveEdit)}
                                >
                                    <EyeIcon className="w-4 h-4" />
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={(e) => { e.stopPropagation(); canManage && onVisibilityToggle(field.id, 'filter', effectiveFilter) }}
                                disabled={!canManage}
                                title="Filter: Show in asset grid filter bar"
                                className={iconButtonClass(effectiveFilter)}
                            >
                                <FunnelIcon className="w-4 h-4" />
                            </button>
                            {!isFilterOnlyField && (
                                <button
                                    type="button"
                                    onClick={(e) => { e.stopPropagation(); effectiveFilter && canManage && categoryId && onPrimaryToggle(field.id, categoryId, effectiveIsPrimary) }}
                                    disabled={!canManage || !effectiveFilter || !categoryId}
                                    title={!effectiveFilter ? 'Enable Filter first to add to primary filter bar' : !categoryId ? 'Select a category first' : 'Primary: Show inline in grid. Off: Under More filters'}
                                    className={iconButtonClass(effectiveFilter && effectiveIsPrimary)}
                                >
                                    {effectiveIsPrimary ? (
                                        <StarIconSolid className="w-4 h-4" />
                                    ) : (
                                        <StarIcon className="w-4 h-4" />
                                    )}
                                </button>
                            )}
                            {!isFilterOnlyField && onRequiredToggle && (
                                <button
                                    type="button"
                                    onClick={(e) => { e.stopPropagation(); effectiveUpload && canManage && categoryId && onRequiredToggle(field.id, categoryId, effectiveIsRequired) }}
                                    disabled={!canManage || !effectiveUpload || !categoryId}
                                    title={!effectiveUpload ? 'Enable Upload first to require this field' : !categoryId ? 'Select a category first' : 'Required: Must be filled when adding assets'}
                                    className={iconButtonClass(effectiveUpload && effectiveIsRequired)}
                                >
                                    <ExclamationCircleIcon className="w-4 h-4" />
                                </button>
                            )}
                            <span
                                title="AI Suggestions: AI can suggest values for this field"
                                className={`inline-flex items-center justify-center gap-1 min-w-8 h-8 px-1.5 rounded-full transition-colors pointer-events-none ${
                                    aiEligible ? 'text-purple-600 bg-purple-100 ring-1 ring-purple-200' : 'text-gray-400 opacity-60'
                                }`}
                                aria-hidden
                            >
                                {aiEligible && <span className="text-[10px] font-semibold uppercase tracking-wide">AI</span>}
                                <SparklesIcon className="w-4 h-4 flex-shrink-0" />
                            </span>
                        </div>
                    )}
                </div>

                {/* Right: Status label above toggle column + Edit/Archive + toggle */}
                <div className="flex-shrink-0 flex flex-col items-end gap-0.5">
                    <span className="text-xs text-gray-500 font-medium" aria-hidden>
                        {isEnabled ? 'Enabled' : 'Off'}
                    </span>
                    <div className="flex items-center gap-1.5">
                        {onEdit && (canManageFields || !isSystem) && (field.key ?? '') !== 'dominant_hue_group' && (
                            <button
                                onClick={() => onEdit(field)}
                                className="opacity-0 group-hover:opacity-70 focus:opacity-70 hover:opacity-100 text-gray-400 hover:text-gray-600 rounded p-1 transition-opacity"
                                title="Edit field"
                            >
                                <PencilIcon className="w-4 h-4" />
                            </button>
                        )}
                        {onArchive && !isSystem && canManageFields && (
                            <button
                                onClick={() => onArchive(field)}
                                className="opacity-0 group-hover:opacity-70 focus:opacity-70 hover:opacity-100 text-gray-400 hover:text-red-600 rounded p-1 transition-opacity"
                                title="Archive field"
                            >
                                <TrashIcon className="w-4 h-4" />
                            </button>
                        )}
                        <button
                            type="button"
                            role="switch"
                            aria-checked={isEnabled}
                            onClick={(e) => {
                                e.preventDefault()
                                e.stopPropagation()
                                onToggle(field.id, categoryId, !isEnabled)
                            }}
                            className="relative inline-flex items-center cursor-pointer focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-1 rounded-full"
                        >
                            <div className={`relative w-9 h-5 rounded-full transition-colors ${isEnabled ? 'bg-indigo-600' : 'bg-gray-200'}`}>
                                <div className={`absolute top-[2px] left-[2px] w-4 h-4 bg-white border border-gray-300 rounded-full transition-transform ${isEnabled ? 'translate-x-4' : 'translate-x-0'}`} />
                            </div>
                        </button>
                    </div>
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
    fieldCategoryData,
}) {
    const isSystem = field.scope === 'system' || (systemFields && systemFields.some(sf => sf.id === field.id))
    const effectiveFilter = field.effective_show_in_filters ?? field.show_in_filters ?? true
    const effectiveEdit = field.effective_show_on_edit ?? field.show_on_edit ?? false
    // dominant_hue_group: filter-only — user may only control is_filter_hidden
    const isFilterOnlyField = (field.key ?? '') === 'dominant_hue_group'

    const iconButtonClass = (active) =>
        `w-8 h-8 flex items-center justify-center rounded-full transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${
            active ? 'text-indigo-600 bg-indigo-100' : 'text-gray-400 opacity-60 hover:opacity-80'
        }`

    return (
        <div
            className={`group flex items-center justify-between py-1.5 px-3 rounded-md transition-colors duration-200 ease-out text-sm ${isEnabled ? 'bg-gray-50' : ''} ${!isEnabled ? 'hover:bg-gray-50/80' : ''}`}
        >
            <div className="flex items-center gap-3 flex-1 min-w-0">
                <div className="flex items-center gap-2 min-w-0">
                    {isSystem && (
                        <span className="w-1.5 h-1.5 rounded-full bg-gray-400 flex-shrink-0" aria-hidden title="System automated field" />
                    )}
                    <span className="text-sm text-gray-900 truncate">
                        {field.label || field.system_label || field.key || 'Unnamed Field'}
                    </span>
                </div>
                {isEnabled && (
                    <div className="flex items-center gap-0.5 flex-shrink-0 relative z-10" onClick={(e) => e.stopPropagation()}>
                        {!isFilterOnlyField && (
                            <span title="Upload: Disabled for automated fields (system-filled)" className="w-8 h-8 flex items-center justify-center rounded-full text-gray-300 opacity-60 cursor-not-allowed pointer-events-none">
                                <CloudArrowUpIcon className="w-4 h-4" />
                            </span>
                        )}
                        {!isFilterOnlyField && (
                            <button
                                type="button"
                                onClick={(e) => { e.stopPropagation(); canManage && onVisibilityToggle(field.id, 'edit', effectiveEdit) }}
                                disabled={!canManage}
                                title="Quick View: Show in asset details drawer and modal"
                                className={iconButtonClass(effectiveEdit)}
                            >
                                <EyeIcon className="w-4 h-4" />
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); canManage && onVisibilityToggle(field.id, 'filter', effectiveFilter) }}
                            disabled={!canManage}
                            title="Filter: Show in asset grid filter bar"
                            className={iconButtonClass(effectiveFilter)}
                        >
                            <FunnelIcon className="w-4 h-4" />
                        </button>
                    </div>
                )}
            </div>
            <div className="flex flex-col items-end gap-0.5 flex-shrink-0">
                <span className="text-xs text-gray-500 font-medium" aria-hidden>
                    {isEnabled ? 'Enabled' : 'Off'}
                </span>
                <button
                    type="button"
                    role="switch"
                    aria-checked={isEnabled}
                    onClick={(e) => {
                        e.preventDefault()
                        e.stopPropagation()
                        onToggle(field.id, categoryId, !isEnabled)
                    }}
                    className="relative inline-flex items-center cursor-pointer focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-1 rounded-full"
                >
                    <div className={`relative w-9 h-5 rounded-full transition-colors ${isEnabled ? 'bg-indigo-600' : 'bg-gray-200'}`}>
                        <div className={`absolute top-[2px] left-[2px] w-4 h-4 bg-white border border-gray-300 rounded-full transition-transform ${isEnabled ? 'translate-x-4' : 'translate-x-0'}`} />
                    </div>
                </button>
            </div>
        </div>
    )
}
