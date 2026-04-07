import { useState, useEffect, useMemo, useCallback } from 'react'
import { Link, router } from '@inertiajs/react'
import {
    ArrowPathIcon,
    ArrowUpCircleIcon,
    CheckCircleIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    EllipsisVerticalIcon,
    FolderIcon,
    InformationCircleIcon,
    PencilIcon,
    PlusIcon,
    RectangleStackIcon,
    Squares2X2Icon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline'
import ConfirmDialog from '../../../Components/ConfirmDialog'
import AddCategoryModal from '../../../Components/Metadata/AddCategoryModal'
import AdvancedSettingsSlideOver from '../../../Components/Metadata/AdvancedSettingsSlideOver'
import CategorySettingsModal from '../../../Components/Metadata/CategorySettingsModal'
import CategoryList from '../../../Components/Metadata/CategoryList'
/**
 * By Category: category tree, EBI, advanced profiles, archived fields restore.
 * Per-field enablement, upload/quick view/filter, primary/required, AI, ordering → Manage → Fields.
 */
const MANAGE_CATEGORIES_URL =
    typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'
const PENDING_SYSTEM_OPTIONS_URL =
    typeof route === 'function'
        ? route('tenant.metadata.system-options.pending-count')
        : '/app/api/tenant/metadata/system-options/pending-count'
const REVEAL_SYSTEM_OPTIONS_URL =
    typeof route === 'function'
        ? route('tenant.metadata.system-options.reveal-pending')
        : '/app/api/tenant/metadata/system-options/reveal-pending'
const PENDING_SYSTEM_FIELDS_URL =
    typeof route === 'function'
        ? route('tenant.metadata.system-fields.pending-count')
        : '/app/api/tenant/metadata/system-fields/pending-count'
const REVEAL_SYSTEM_FIELDS_URL =
    typeof route === 'function'
        ? route('tenant.metadata.system-fields.reveal-pending')
        : '/app/api/tenant/metadata/system-fields/reveal-pending'
function getCsrfTokenForOptions() {
    if (typeof document === 'undefined') return ''
    return document.querySelector('meta[name="csrf-token"]')?.content || ''
}

export default function ByCategoryView({ 
    brands = [],
    selectedBrandId,
    onBrandChange,
    categories, 
    canManageBrandCategories = false,
    canManageVisibility = true, // Default true so toggles are clickable; API enforces permission
    canManageFields = false,
    initialCategorySlug = null,
}) {
    const [selectedCategoryId, setSelectedCategoryId] = useState(null)
    const [successMessage, setSuccessMessage] = useState(null) // Success message state
    const [copyFromSourceId, setCopyFromSourceId] = useState(null) // Source category for Copy from
    const [copyOrResetLoading, setCopyOrResetLoading] = useState(false)
    const [confirmCopyOpen, setConfirmCopyOpen] = useState(false)
    const [confirmResetOpen, setConfirmResetOpen] = useState(false)
    const [confirmApplyOtherBrandsOpen, setConfirmApplyOtherBrandsOpen] = useState(false)
    const [applyOtherBrandsTargets, setApplyOtherBrandsTargets] = useState([]) // { brand_name, category_name }[]
    const [applyOtherBrandsLoading, setApplyOtherBrandsLoading] = useState(false)
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
    const [editingCategoryId, setEditingCategoryId] = useState(null)
    const [editingCategoryName, setEditingCategoryName] = useState('')
    const [advancedSettingsOpen, setAdvancedSettingsOpen] = useState(false)
    const [categorySettingsOpen, setCategorySettingsOpen] = useState(false)
    const [categorySettingsCategory, setCategorySettingsCategory] = useState(null)
    const [categoryMenuOpen, setCategoryMenuOpen] = useState(false)
    const [categoryFormData, setCategoryFormData] = useState({ brand_roles: [], brand_users: [] })
    const [confirmRevertOpen, setConfirmRevertOpen] = useState(false)
    const [categoryToRevert, setCategoryToRevert] = useState(null)
    const [showArchived, setShowArchived] = useState(false)
    const [archivedFields, setArchivedFields] = useState([])
    const [archivedLoading, setArchivedLoading] = useState(false)
    const [restoreLoading, setRestoreLoading] = useState(false)
    const [ebiToggleLoading, setEbiToggleLoading] = useState(false)
    const [pendingSystemOptionCount, setPendingSystemOptionCount] = useState(0)
    const [revealSystemOptionsLoading, setRevealSystemOptionsLoading] = useState(false)
    const [pendingSystemFieldCount, setPendingSystemFieldCount] = useState(0)
    const [revealSystemFieldsLoading, setRevealSystemFieldsLoading] = useState(false)

    useEffect(() => {
        setLocalCategories(categories)
    }, [categories])

    useEffect(() => {
        let cancelled = false
        Promise.all([
            fetch(PENDING_SYSTEM_OPTIONS_URL, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }).then((r) => (r.ok ? r.json() : { pending_count: 0 })),
            fetch(PENDING_SYSTEM_FIELDS_URL, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }).then((r) => (r.ok ? r.json() : { pending_count: 0 })),
        ])
            .then(([opt, fld]) => {
                if (!cancelled) {
                    setPendingSystemOptionCount(Number(opt.pending_count) || 0)
                    setPendingSystemFieldCount(Number(fld.pending_count) || 0)
                }
            })
            .catch(() => {})
        return () => {
            cancelled = true
        }
    }, [])

    // Scope to one brand at a time so the list has no duplicate category names
    const categoriesForBrand = useMemo(() => {
        if (!selectedBrandId) return localCategories
        return localCategories.filter(c => c.brand_id === selectedBrandId)
    }, [localCategories, selectedBrandId])

    const selectedCategory = useMemo(
        () => categoriesForBrand.find((cat) => cat.id === selectedCategoryId) ?? null,
        [categoriesForBrand, selectedCategoryId]
    )

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

    // Clear selected category if it no longer belongs to the selected brand
    useEffect(() => {
        if (!selectedCategoryId) return
        const stillInList = categoriesForBrand.some(c => c.id === selectedCategoryId)
        if (!stillInList) setSelectedCategoryId(null)
    }, [selectedBrandId, categoriesForBrand, selectedCategoryId])

    // Group categories by asset_type (within the selected brand), stable order for sidebar + reorder UI
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

        const sortInGroup = (list) =>
            [...list].sort((a, b) => {
                const ao = a.sort_order ?? 9999
                const bo = b.sort_order ?? 9999
                if (ao !== bo) return ao - bo
                return String(a.name || '').localeCompare(String(b.name || ''))
            })

        groups.asset = sortInGroup(groups.asset)
        groups.deliverable = sortInGroup(groups.deliverable)

        return groups
    }, [categoriesForBrand])

    const brandId = selectedBrandId ?? brands[0]?.id ?? categoriesForBrand[0]?.brand_id

    const canRevealSystemOptions = canManageFields || canManageVisibility

    const handleRevealSystemOptions = useCallback(async () => {
        if (!canRevealSystemOptions) return
        setRevealSystemOptionsLoading(true)
        try {
            const res = await fetch(REVEAL_SYSTEM_OPTIONS_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfTokenForOptions(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: '{}',
            })
            const data = await res.json().catch(() => ({}))
            if (res.ok) {
                setPendingSystemOptionCount(0)
                if (typeof window !== 'undefined' && window.toast) {
                    const n = Number(data.rows_removed)
                    const msg =
                        Number.isFinite(n) && n > 0
                            ? `Revealed ${n} new platform value${n === 1 ? '' : 's'} in pickers.`
                            : 'Platform pickers updated.'
                    window.toast(msg, 'success')
                }
            } else if (typeof window !== 'undefined' && window.toast) {
                window.toast(data.message || 'Could not apply update', 'error')
            }
        } finally {
            setRevealSystemOptionsLoading(false)
        }
    }, [canRevealSystemOptions])

    const handleRevealSystemFields = useCallback(async () => {
        if (!canRevealSystemOptions) return
        setRevealSystemFieldsLoading(true)
        try {
            const res = await fetch(REVEAL_SYSTEM_FIELDS_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfTokenForOptions(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: '{}',
            })
            const data = await res.json().catch(() => ({}))
            if (res.ok) {
                setPendingSystemFieldCount(0)
                if (typeof window !== 'undefined' && window.toast) {
                    const n = Number(data.rows_updated)
                    const msg =
                        Number.isFinite(n) && n > 0
                            ? `Enabled ${n} new platform field row${n === 1 ? '' : 's'} on your categories.`
                            : 'Category fields updated.'
                    window.toast(msg, 'success')
                }
            } else if (typeof window !== 'undefined' && window.toast) {
                window.toast(data.message || 'Could not apply update', 'error')
            }
        } finally {
            setRevealSystemFieldsLoading(false)
        }
    }, [canRevealSystemOptions])

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

    // Category-level Brand Intelligence (settings.ebi_enabled)
    const toggleEbiEnabled = async () => {
        if (!canManageVisibility || !selectedCategory || !brandId) return
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
                setLocalCategories((prev) =>
                    prev.map((c) => (c.id === selectedCategory.id ? { ...c, ebi_enabled: newValue } : c))
                )
                setSuccessMessage(`Brand Intelligence ${newValue ? 'enabled' : 'disabled'} for this category`)
                setTimeout(() => setSuccessMessage(null), 3000)
            } else {
                const errorData = await response.json().catch(() => ({}))
                setSuccessMessage(errorData.message || errorData.error || 'Failed to update Brand Intelligence setting')
                setTimeout(() => setSuccessMessage(null), 4000)
            }
        } catch (error) {
            console.error('Failed to toggle ebi_enabled:', error)
            setSuccessMessage('Failed to update Brand Intelligence setting')
            setTimeout(() => setSuccessMessage(null), 3000)
        } finally {
            setEbiToggleLoading(false)
        }
    }

    // Refresh via Manage → Categories (session brand); preserve selected folder slug when possible.
    const refreshMetadataRegistry = useCallback((opts = {}) => {
        const params = {}
        if (selectedCategory?.slug) params.category = selectedCategory.slug
        router.get(MANAGE_CATEGORIES_URL, params, {
            preserveState: true,
            preserveScroll: true,
            ...opts,
        })
    }, [selectedCategory?.slug])

    const handleAfterAddSystemCategory = useCallback((category) => {
        const params = {}
        if (category?.slug) params.category = category.slug
        router.get(MANAGE_CATEGORIES_URL, params, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                if (category?.id != null) {
                    setSelectedCategoryId(category.id)
                }
            },
        })
    }, [])

    // Select category: update URL and local state
    const handleSelectCategory = useCallback((categoryId) => {
        const cat = categoriesForBrand.find(c => c.id === categoryId)
        const params = {}
        if (cat?.slug) params.category = cat.slug
        router.get(MANAGE_CATEGORIES_URL, params, {
            preserveState: true,
            preserveScroll: true,
        })
        setSelectedCategoryId(categoryId)
    }, [categoriesForBrand])

    // Brand change: update URL (Index syncs from initial_brand_id)
    const handleBrandChange = useCallback(
        (brandId) => {
            const params = {}
            if (selectedCategory?.slug) params.category = selectedCategory.slug
            router.get(MANAGE_CATEGORIES_URL, params, {
                preserveState: true,
                preserveScroll: true,
            })
            onBrandChange(brandId)
        },
        [selectedCategory?.slug, onBrandChange]
    )

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
                refreshMetadataRegistry()
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
                refreshMetadataRegistry()
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
                refreshMetadataRegistry()
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
                refreshMetadataRegistry()
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
                refreshMetadataRegistry()
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
                    <h2 className="text-base font-semibold text-gray-900">Brand Content Structure</h2>
                    <p className="mt-1.5 text-xs text-gray-500 leading-relaxed">
                        Drag the handle beside a visible folder to set sidebar order in the asset library and executions.
                        The eye icon hides a folder from those sidebars; hidden folders stay listed below so you can still manage fields.
                        Platform folders you haven&apos;t added yet appear in the same panel as hidden folders, under <span className="font-medium text-gray-600">Available from catalog</span>.
                    </p>
                    {pendingSystemOptionCount > 0 && (
                        <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50/90 px-3 py-2.5">
                            <p className="text-xs text-amber-950/90">
                                <span className="font-semibold tabular-nums">{pendingSystemOptionCount}</span> new platform
                                select value{pendingSystemOptionCount === 1 ? ' is' : 's are'} hidden in your pickers until
                                you add them.
                            </p>
                            {canRevealSystemOptions ? (
                                <button
                                    type="button"
                                    onClick={handleRevealSystemOptions}
                                    disabled={revealSystemOptionsLoading}
                                    className="mt-2 inline-flex items-center rounded-md bg-amber-800 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-amber-900 disabled:opacity-60"
                                >
                                    {revealSystemOptionsLoading ? 'Applying…' : 'Show new platform values'}
                                </button>
                            ) : (
                                <p className="mt-2 text-[11px] text-amber-900/80">
                                    Ask a tenant admin with metadata field or visibility management access to reveal them.
                                </p>
                            )}
                        </div>
                    )}
                    {pendingSystemFieldCount > 0 && (
                        <div className="mt-4 rounded-lg border border-indigo-200 bg-indigo-50/90 px-3 py-2.5">
                            <p className="text-xs text-indigo-950/90">
                                <span className="font-semibold tabular-nums">{pendingSystemFieldCount}</span> new platform
                                metadata field placement{pendingSystemFieldCount === 1 ? ' is' : 's are'} on hold until you
                                enable them (upload, grid, filters, and detail stay off for those rows).
                            </p>
                            {canRevealSystemOptions ? (
                                <button
                                    type="button"
                                    onClick={handleRevealSystemFields}
                                    disabled={revealSystemFieldsLoading}
                                    className="mt-2 inline-flex items-center rounded-md bg-indigo-700 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-800 disabled:opacity-60"
                                >
                                    {revealSystemFieldsLoading ? 'Applying…' : 'Enable new platform fields'}
                                </button>
                            ) : (
                                <p className="mt-2 text-[11px] text-indigo-900/80">
                                    Ask a tenant admin with metadata field or visibility management access to enable them.
                                </p>
                            )}
                        </div>
                    )}
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
                        canManageBrandCategories={canManageBrandCategories}
                        brandId={brandId}
                        onCategoriesChange={onCategoriesChange}
                        onAfterAddSystemCategory={handleAfterAddSystemCategory}
                        onRename={(cat) => { setEditingCategoryId(cat.id); setEditingCategoryName(cat.name) }}
                        onCategorySettingsClick={handleCategorySettingsClick}
                        onDelete={(cat) => { setCategoryToDelete(cat); setConfirmDeleteOpen(true) }}
                        onRevert={handleRevertClick}
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
                                            System
                                        </span>
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
                        {canManageVisibility && selectedCategory && (
                            <div className="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-gray-900">Enable Brand Intelligence</p>
                                    <p className="text-xs text-gray-500 mt-0.5">
                                        When on, assets in this category are scored after analysis (same idea as AI field
                                        toggles).
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={selectedCategory.ebi_enabled === true}
                                    disabled={ebiToggleLoading || !!previewProfileName}
                                    onClick={toggleEbiEnabled}
                                    className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed ${
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

                        {/* Fields — full editing lives under Manage → Fields */}
                        <div className="rounded-xl border border-gray-200 bg-white shadow-md overflow-hidden">
                            <div className="px-8 py-6 space-y-8">
                                <div className="text-center max-w-xl mx-auto py-4">
                                    <p className="text-sm text-gray-600">
                                        Field enablement, upload and filter visibility, AI options, and ordering are
                                        managed in <strong className="text-gray-900">Manage → Categories</strong> with a
                                        table layout for this brand&apos;s folders.
                                    </p>
                                    <Link
                                        href={
                                            typeof route === 'function'
                                                ? `${route('manage.categories')}${
                                                      selectedCategory?.slug
                                                          ? `?category=${encodeURIComponent(selectedCategory.slug)}`
                                                          : ''
                                                  }`
                                                : `/app/manage/categories${
                                                      selectedCategory?.slug
                                                          ? `?category=${encodeURIComponent(selectedCategory.slug)}`
                                                          : ''
                                                  }`
                                        }
                                        className="mt-6 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    >
                                        Open Categories workspace
                                    </Link>
                                </div>

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
            {/* Add Category Modal */}
            <AddCategoryModal
                isOpen={addCategoryOpen}
                onClose={() => setAddCategoryOpen(false)}
                brandId={brandId}
                brandName={brands.find(b => b.id === brandId)?.name ?? ''}
                categoryLimits={brands.find(b => b.id === brandId)?.category_limits ?? null}
                canViewMetadataRegistry={canManageVisibility || canManageFields}
                onSuccess={handleAddCategorySuccess}
            />

            {/* Category Settings Modal */}
            <CategorySettingsModal
                isOpen={categorySettingsOpen}
                onClose={() => { setCategorySettingsOpen(false); setCategorySettingsCategory(null) }}
                category={categorySettingsCategory}
                brandId={brandId}
                brandRoles={categoryFormData.brand_roles}
                brandUsers={categoryFormData.brand_users}
                canViewMetadataRegistry={canManageVisibility || canManageFields}
                onSuccess={handleCategorySettingsSuccess}
                onDelete={handleCategorySettingsDelete}
            />

        </div>
    )
}
