import { useState, useEffect, useMemo, useCallback } from 'react'
import { Link, router } from '@inertiajs/react'
import CategoryList from '../Metadata/CategoryList'
import AddCategoryModal from '../Metadata/AddCategoryModal'
import CategorySettingsModal from '../Metadata/CategorySettingsModal'
import ConfirmDialog from '../ConfirmDialog'

const MANAGE_STRUCTURE_URL =
    typeof route === 'function' ? route('manage.structure') : '/app/manage/structure'
const DEFAULT_MANAGE_PAGE_URL = MANAGE_STRUCTURE_URL
const MANAGE_CATEGORIES_URL =
    typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'

function getCsrfToken() {
    if (typeof document === 'undefined') return ''
    return document.querySelector('meta[name="csrf-token"]')?.content || ''
}

export default function ManageStructureWorkspace({
    brand,
    categories: initialCategories,
    category_limits: categoryLimitsProp,
    canManageBrandCategories,
    canManageVisibility,
    canManageFields = false,
    selectedCategoryId: selectedCategoryIdProp,
    onSelectCategory: onSelectCategoryProp,
    managePageUrl,
    hubLayout = false,
    onSaveNotice,
}) {
    const brandId = brand?.id
    const [localCategories, setLocalCategories] = useState(initialCategories || [])
    const [internalSelectedCategoryId, setInternalSelectedCategoryId] = useState(null)
    const selectionControlled = typeof onSelectCategoryProp === 'function'
    const selectedCategoryId = selectionControlled ? selectedCategoryIdProp : internalSelectedCategoryId
    const [editingCategoryId, setEditingCategoryId] = useState(null)
    const [editingCategoryName, setEditingCategoryName] = useState('')
    const [addCategoryOpen, setAddCategoryOpen] = useState(false)
    const [categorySettingsOpen, setCategorySettingsOpen] = useState(false)
    const [categorySettingsCategory, setCategorySettingsCategory] = useState(null)
    const [categoryFormData, setCategoryFormData] = useState({ brand_roles: [], brand_users: [] })
    const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false)
    const [categoryToDelete, setCategoryToDelete] = useState(null)
    const [confirmRevertOpen, setConfirmRevertOpen] = useState(false)
    const [categoryToRevert, setCategoryToRevert] = useState(null)
    const [revertLoading, setRevertLoading] = useState(false)

    useEffect(() => {
        setLocalCategories(initialCategories || [])
    }, [initialCategories])

    const groupedCategories = useMemo(() => {
        const groups = { asset: [], deliverable: [] }
        ;(localCategories || []).forEach((category) => {
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
    }, [localCategories])

    const onCategoriesChange = useCallback(
        (newGrouped) => {
            if (!brandId) return
            const brandName = brand?.name
            const assetWithBrand = (newGrouped.asset || []).map((c) => ({
                ...c,
                brand_id: brandId,
                brand_name: brandName,
            }))
            const deliverableWithBrand = (newGrouped.deliverable || []).map((c) => ({
                ...c,
                brand_id: brandId,
                brand_name: brandName,
            }))
            setLocalCategories([...assetWithBrand, ...deliverableWithBrand])
        },
        [brandId, brand?.name]
    )

    const listPageUrl = managePageUrl ?? DEFAULT_MANAGE_PAGE_URL

    const refreshStructure = useCallback(
        (opts = {}) => {
            router.get(
                listPageUrl,
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    ...opts,
                }
            )
        },
        [listPageUrl]
    )

    const handleRenameCategory = useCallback(
        async (category, newName) => {
            if (!brandId || !newName?.trim() || category?.is_system) {
                setEditingCategoryId(null)
                setEditingCategoryName('')
                return
            }
            const trimmed = newName.trim()
            const slug = trimmed
                .toLowerCase()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9-]/g, '')
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
                    onSaveNotice?.('Folder name saved.')
                }
            } finally {
                setEditingCategoryId(null)
                setEditingCategoryName('')
            }
        },
        [brandId, onSaveNotice]
    )

    const handleDeleteCategory = useCallback(() => {
        if (!brandId || !categoryToDelete) return
        const url =
            typeof route === 'function'
                ? route('brands.categories.destroy', { brand: brandId, category: categoryToDelete.id })
                : `/app/brands/${brandId}/categories/${categoryToDelete.id}`
        router.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                onSaveNotice?.('Folder deleted.')
                if (selectionControlled) onSelectCategoryProp?.(null)
                else setInternalSelectedCategoryId(null)
                setConfirmDeleteOpen(false)
                setCategoryToDelete(null)
                refreshStructure()
            },
            onError: () => {
                setConfirmDeleteOpen(false)
                setCategoryToDelete(null)
            },
        })
    }, [brandId, categoryToDelete, refreshStructure, selectionControlled, onSelectCategoryProp, onSaveNotice])

    const handleAddCategorySuccess = useCallback(
        (newCat) => {
            if (!newCat?.id) return
            setLocalCategories((prev) => [
                ...prev,
                {
                    ...newCat,
                    type_field: 'custom',
                    is_private: false,
                    access_rules: [],
                    ebi_enabled: newCat.asset_type === 'deliverable',
                    deletion_available: false,
                    upgrade_available: false,
                },
            ])
            onSaveNotice?.('Custom folder added.')
        },
        [onSaveNotice]
    )

    const handleAfterAddSystemCategory = useCallback(
        (category) => {
            const params = {}
            if (category?.slug) params.category = category.slug
            router.get(listPageUrl, params, {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    if (category?.id != null) {
                        if (selectionControlled) onSelectCategoryProp?.(category.id)
                        else setInternalSelectedCategoryId(category.id)
                    }
                },
            })
        },
        [listPageUrl, selectionControlled, onSelectCategoryProp]
    )

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

    const handleCategorySettingsSuccess = useCallback(
        (updatedCategory) => {
            setLocalCategories((prev) =>
                prev.map((c) => (c.id === updatedCategory.id ? { ...c, ...updatedCategory } : c))
            )
            onSaveNotice?.('Folder settings saved.')
        },
        [onSaveNotice]
    )

    const handleCategorySettingsDelete = useCallback((cat) => {
        setCategoryToDelete(cat)
        setConfirmDeleteOpen(true)
    }, [])

    const handleCategorySettingsClick = useCallback((cat) => {
        setCategorySettingsCategory(cat)
        setCategorySettingsOpen(true)
    }, [])

    const handleRevertClick = useCallback((category) => {
        setCategoryToRevert(category)
        setConfirmRevertOpen(true)
    }, [])

    const handleRevertConfirm = useCallback(async () => {
        if (!categoryToRevert?.id) return
        setRevertLoading(true)
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
            if (response.ok) {
                onSaveNotice?.('Folder reset to system defaults.')
                setCategoryToRevert(null)
                setConfirmRevertOpen(false)
                refreshStructure()
            }
        } finally {
            setRevertLoading(false)
        }
    }, [categoryToRevert, refreshStructure, onSaveNotice])

    const handleSelectCategory = useCallback(
        (categoryId) => {
            if (selectionControlled) onSelectCategoryProp(categoryId)
            else setInternalSelectedCategoryId(categoryId)
        },
        [selectionControlled, onSelectCategoryProp]
    )

    return (
        <div className="space-y-6">
            <div className="rounded-lg bg-white shadow-sm border border-gray-100 p-5 lg:p-6">
                <h2 className="text-base font-semibold text-gray-900">
                    {hubLayout ? 'Folders' : 'Brand structure'}
                </h2>
                <p className="mt-1.5 text-sm text-gray-600 leading-relaxed">
                    {hubLayout
                        ? 'Select a folder to configure its fields. Drag to reorder; the eye hides a folder from library sidebars. Add custom folders or use the catalog below. Changes save immediately; a confirmation appears at the top when each save succeeds.'
                        : 'Drag the handle beside a visible folder to set sidebar order. The eye icon hides a folder from the library sidebars; hidden folders stay under the collapsed Hidden section. Add custom categories or pull in platform folders from the catalog below.'}
                </p>
                {brand?.name ? (
                    <p className="mt-3 text-xs text-gray-500">
                        Active brand: <span className="font-medium text-gray-700">{brand.name}</span>
                    </p>
                ) : null}
                <div className="mt-4">
                    <CategoryList
                        groupedCategories={groupedCategories}
                        selectedCategoryId={selectedCategoryId}
                        onSelectCategory={handleSelectCategory}
                        canManageVisibility={canManageVisibility}
                        canManageBrandCategories={canManageBrandCategories}
                        brandId={brandId}
                        onCategoriesChange={onCategoriesChange}
                        onSaveNotice={onSaveNotice}
                        onAfterAddSystemCategory={handleAfterAddSystemCategory}
                        onRename={(cat) => {
                            setEditingCategoryId(cat.id)
                            setEditingCategoryName(cat.name)
                        }}
                        onCategorySettingsClick={handleCategorySettingsClick}
                        onDelete={(cat) => {
                            setCategoryToDelete(cat)
                            setConfirmDeleteOpen(true)
                        }}
                        onRevert={handleRevertClick}
                        onSaveRename={(cat, name) => handleRenameCategory(cat, name)}
                        onCancelRename={() => {
                            setEditingCategoryId(null)
                            setEditingCategoryName('')
                        }}
                        onAddCategory={brandId && canManageBrandCategories ? () => setAddCategoryOpen(true) : undefined}
                        editingCategoryId={editingCategoryId}
                        editingCategoryName={editingCategoryName}
                        onEditingCategoryNameChange={setEditingCategoryName}
                    />
                </div>
                {!hubLayout && (
                    <p className="mt-6 text-sm text-gray-600">
                        To configure metadata fields, filters, and visibility per folder, open{' '}
                        <Link href={MANAGE_CATEGORIES_URL} className="font-medium text-indigo-600 hover:text-indigo-500">
                            Manage → Categories
                        </Link>
                        .
                    </p>
                )}
            </div>

            <ConfirmDialog
                open={confirmDeleteOpen}
                onClose={() => {
                    setConfirmDeleteOpen(false)
                    setCategoryToDelete(null)
                }}
                onConfirm={handleDeleteCategory}
                title="Delete category"
                message={categoryToDelete ? `Delete "${categoryToDelete.name}"? This cannot be undone.` : ''}
                confirmText="Delete"
                cancelText="Cancel"
                variant="danger"
            />

            <ConfirmDialog
                open={confirmRevertOpen}
                onClose={() => {
                    setConfirmRevertOpen(false)
                    setCategoryToRevert(null)
                }}
                onConfirm={handleRevertConfirm}
                title="Revert to System"
                message={
                    categoryToRevert
                        ? `Revert "${categoryToRevert.name}" to system default? This removes category-level visibility overrides.`
                        : ''
                }
                confirmText="Revert"
                cancelText="Cancel"
                variant="warning"
                loading={revertLoading}
            />

            <AddCategoryModal
                isOpen={addCategoryOpen}
                onClose={() => setAddCategoryOpen(false)}
                brandId={brandId}
                brandName={brand?.name ?? ''}
                categoryLimits={categoryLimitsProp ?? null}
                canViewMetadataRegistry={canManageVisibility || canManageFields}
                onSuccess={handleAddCategorySuccess}
            />

            <CategorySettingsModal
                isOpen={categorySettingsOpen}
                onClose={() => {
                    setCategorySettingsOpen(false)
                    setCategorySettingsCategory(null)
                }}
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
