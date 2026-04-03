import { useState, useEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import { ArrowTopRightOnSquareIcon, CheckCircleIcon, XMarkIcon } from '@heroicons/react/24/outline'
import CategoryIconSelector from '../CategoryIconSelector'

/**
 * Centered modal for adding a new category.
 * Matches the Create Custom Metadata Field modal style (screenshot 2).
 * Shows category plan limit, restrict access (roles + users), validation.
 * After create, offers "Configure metadata fields" to view fields for the new category.
 */
export default function AddCategoryModal({
    isOpen,
    onClose,
    assetType: assetTypeProp,
    brandId,
    brandName,
    categoryLimits: categoryLimitsProp = null,
    onSuccess,
    canViewMetadataRegistry = true,
}) {
    const [name, setName] = useState('')
    const [icon, setIcon] = useState('folder')
    const [assetType, setAssetType] = useState(assetTypeProp || 'asset')
    const [isPrivate, setIsPrivate] = useState(false)
    const [selectedRoles, setSelectedRoles] = useState([])
    const [selectedUserIds, setSelectedUserIds] = useState([])
    const [brandRoles, setBrandRoles] = useState([])
    const [brandUsers, setBrandUsers] = useState([])
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)
    const [fetchedCategoryLimits, setFetchedCategoryLimits] = useState(null)
    const [createdCategory, setCreatedCategory] = useState(null)
    const nameInputRef = useRef(null)

    useEffect(() => {
        if (!isOpen) setCreatedCategory(null)
    }, [isOpen])

    useEffect(() => {
        if (isOpen && nameInputRef.current) {
            nameInputRef.current.focus()
        }
    }, [isOpen])

    useEffect(() => {
        if (isOpen) {
            setName('')
            setIcon('folder')
            setAssetType(assetTypeProp || 'asset')
            setIsPrivate(false)
            setSelectedRoles([])
            setSelectedUserIds([])
            setError(null)
            if (brandId) {
                fetch(`/app/api/brands/${brandId}/category-form-data`, { credentials: 'same-origin' })
                    .then((r) => r.json())
                    .then((data) => {
                        setBrandRoles(data.brand_roles || [])
                        setBrandUsers(data.brand_users || [])
                        setFetchedCategoryLimits(data.category_limits || null)
                    })
                    .catch(() => {
                        setBrandRoles([])
                        setBrandUsers([])
                    })
            } else {
                setBrandRoles([])
                setBrandUsers([])
            }
        }
    }, [isOpen, assetTypeProp, brandId])

    const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || ''

    const toggleRole = (role) => {
        setSelectedRoles((prev) =>
            prev.includes(role) ? prev.filter((r) => r !== role) : [...prev, role]
        )
    }

    const toggleUser = (userId) => {
        setSelectedUserIds((prev) =>
            prev.includes(userId) ? prev.filter((id) => id !== userId) : [...prev, userId]
        )
    }

    const hasRestrictSelection = selectedRoles.length > 0 || selectedUserIds.length > 0

    const handleSave = async () => {
        const trimmed = name?.trim()
        const type = assetTypeProp ?? assetType
        if (!trimmed || trimmed.length <= 2 || !brandId) return

        if (isPrivate && !hasRestrictSelection) {
            setError('Select at least one role or user for private categories.')
            return
        }

        const limits = categoryLimitsProp ?? fetchedCategoryLimits
        if (limits && limits.can_create === false) {
            setError("You've reached your category limit for this plan.")
            return
        }
        const vis = limits?.visible_by_asset_type?.[type]
        if (vis?.at_cap) {
            setError(
                `You already have ${vis.max} visible categories for this library. Hide a category first, or contact support.`
            )
            return
        }

        setLoading(true)
        setError(null)
        try {
            const accessRules = []
            if (isPrivate) {
                selectedRoles.forEach((role) => accessRules.push({ type: 'role', role }))
                selectedUserIds.forEach((userId) => accessRules.push({ type: 'user', user_id: userId }))
            }

            const payload = {
                name: trimmed,
                asset_type: type,
                icon: icon || 'folder',
                is_private: isPrivate,
                ...(accessRules.length > 0 && { access_rules: accessRules }),
            }

            const res = await fetch(`/app/brands/${brandId}/categories`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            })

            const data = await res.json().catch(() => ({}))
            if (res.ok && data?.category) {
                const newCat = {
                    id: data.category.id,
                    name: data.category.name,
                    slug: data.category.slug,
                    icon: data.category.icon || icon || 'folder',
                    asset_type: type,
                    is_system: false,
                    is_hidden: false,
                    brand_id: brandId,
                    brand_name: brandName,
                    sort_order: data.category.sort_order,
                }
                onSuccess(newCat)
                if (canViewMetadataRegistry) {
                    setCreatedCategory(newCat)
                } else {
                    onClose()
                }
            } else {
                setError(data.message || data.error || data.errors?.name?.[0] || 'Failed to create category.')
            }
        } catch (e) {
            setError('Network error. Please try again.')
        } finally {
            setLoading(false)
        }
    }

    const handleDone = () => {
        setCreatedCategory(null)
        onClose()
    }

    if (!isOpen) return null

    if (typeof window === 'undefined' || !document.body) return null

    const type = assetTypeProp ?? assetType
    const limits = categoryLimitsProp ?? fetchedCategoryLimits
    const atLimit = limits && !limits.can_create
    const canSave =
        (name?.trim().length ?? 0) > 2 &&
        !loading &&
        !atLimit &&
        (!isPrivate || hasRestrictSelection)

    const limitsLabel =
        limits && limits.max > 0
            ? `${limits.current} of ${limits.max} custom categories used`
            : null

    // Success state: prompt to configure metadata fields
    if (createdCategory && canViewMetadataRegistry) {
        const slug = createdCategory.slug || createdCategory.name?.toLowerCase().replace(/\s+/g, '-')
        const registryUrl = typeof route === 'function'
            ? route('tenant.metadata.registry.index', { brand: brandId, category: slug })
            : `/app/tenant/metadata/registry?brand=${brandId}&category=${encodeURIComponent(slug)}`

        const successContent = (
            <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="add-category-success" role="dialog" aria-modal="true">
                <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={handleDone} />
                    <div className="relative transform overflow-hidden rounded-xl bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                        <div className="px-6 py-8 text-center">
                            <CheckCircleIcon className="mx-auto h-12 w-12 text-green-500" aria-hidden />
                            <h3 id="add-category-success" className="mt-4 text-lg font-semibold text-gray-900">
                                Category created
                            </h3>
                            <p className="mt-2 text-sm text-gray-500">
                                Configure which metadata fields appear for &quot;{createdCategory.name}&quot;.
                            </p>
                            <div className="mt-6 flex flex-col sm:flex-row gap-3 justify-center">
                                <a
                                    href={registryUrl}
                                    className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                >
                                    <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                                    Configure metadata fields
                                </a>
                                <button
                                    type="button"
                                    onClick={handleDone}
                                    className="inline-flex justify-center rounded-md bg-white px-4 py-2.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                >
                                    Done
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        )
        return createPortal(successContent, document.body)
    }

    const modalContent = (
        <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="add-category-title" role="dialog" aria-modal="true">
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                {/* Backdrop */}
                <div
                    className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    onClick={onClose}
                />

                {/* Modal */}
                <div className="relative transform overflow-hidden rounded-xl bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3 sm:px-5">
                        <div className="flex items-center gap-3 min-w-0">
                            <h3 id="add-category-title" className="text-base font-semibold text-gray-900 truncate">
                                New Category
                            </h3>
                            {limitsLabel && (
                                <span className={`flex-shrink-0 text-xs px-2 py-0.5 rounded-full ${
                                    atLimit ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-600'
                                }`}>
                                    {limitsLabel}
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

                    {/* Form */}
                    <div className="px-4 py-4 sm:px-5 sm:py-5 max-h-[calc(100vh-12rem)] overflow-y-auto">
                        {atLimit && (
                            <p className="mb-4 text-sm text-amber-700 rounded-md bg-amber-50 px-3 py-2 border border-amber-100">
                                You&apos;ve reached your category limit for this plan.
                            </p>
                        )}

                        {error && (
                            <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700 border border-red-100">
                                {error}
                            </div>
                        )}

                        <div className="space-y-4">
                            <div>
                                <label htmlFor="add-category-name" className="block text-sm font-medium text-gray-700 mb-1">
                                    Category Name
                                </label>
                                <input
                                    ref={nameInputRef}
                                    id="add-category-name"
                                    type="text"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    placeholder="Enter category name"
                                    className="block w-full rounded-md border border-gray-300 py-1.5 px-2.5 text-sm placeholder-gray-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                />
                                <p className="mt-1.5 text-xs text-gray-500">
                                    Categories help organize your assets and define metadata behavior.
                                </p>
                            </div>

                            <div>
                                <span className="block text-sm font-medium text-gray-700 mb-1.5">
                                    Icon
                                </span>
                                <CategoryIconSelector value={icon} onChange={setIcon} disabled={loading || atLimit} />
                                <p className="mt-1.5 text-xs text-gray-500">
                                    Shown next to this folder in sidebars and category lists.
                                </p>
                            </div>

                            {assetTypeProp == null && (
                                <div>
                                    <label htmlFor="add-category-type" className="block text-sm font-medium text-gray-700 mb-1">
                                        Type
                                    </label>
                                    <select
                                        id="add-category-type"
                                        value={assetType}
                                        onChange={(e) => setAssetType(e.target.value)}
                                        className="block w-full rounded-md border border-gray-300 py-1.5 px-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    >
                                        <option value="asset">Asset</option>
                                        <option value="deliverable">Execution</option>
                                    </select>
                                </div>
                            )}

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Restrict access
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={isPrivate}
                                        onChange={(e) => {
                                            setIsPrivate(e.target.checked)
                                            if (!e.target.checked) {
                                                setSelectedRoles([])
                                                setSelectedUserIds([])
                                                setError(null)
                                            }
                                        }}
                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span className="text-sm text-gray-700">Restrict access to this category</span>
                                </label>
                                {isPrivate && (
                                    <div className="mt-3 rounded-md border border-gray-100 bg-gray-50/50 p-4 space-y-3">
                                        <div>
                                            <label className="block text-xs font-medium text-gray-600 mb-2">
                                                Brand Roles
                                            </label>
                                            <div className="space-y-2">
                                                {(brandRoles.length > 0 ? brandRoles : ['admin', 'brand_manager', 'contributor', 'viewer']).map((role) => {
                                                    const roleKey = typeof role === 'string' ? role : role.value || role
                                                    const roleLabel = typeof role === 'string'
                                                        ? role.replace(/_/g, ' ')
                                                        : (role.label || roleKey).replace(/_/g, ' ')
                                                    const isSelected = selectedRoles.includes(roleKey)
                                                    return (
                                                        <label key={roleKey} className="flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                checked={isSelected}
                                                                onChange={() => toggleRole(roleKey)}
                                                                className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                            />
                                                            <span className="text-sm text-gray-700 capitalize">{roleLabel}</span>
                                                        </label>
                                                    )
                                                })}
                                            </div>
                                        </div>
                                        <div className="border-t border-gray-100 pt-3">
                                            <label className="block text-xs font-medium text-gray-600 mb-2">
                                                Individual Users
                                            </label>
                                            <div className="space-y-2 max-h-48 overflow-y-auto">
                                                {brandUsers.map((user) => {
                                                    const isSelected = selectedUserIds.includes(user.id)
                                                    return (
                                                        <label key={user.id} className="flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                checked={isSelected}
                                                                onChange={() => toggleUser(user.id)}
                                                                className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                            />
                                                            <span className="text-sm text-gray-700">
                                                                {user.name} ({user.email})
                                                            </span>
                                                        </label>
                                                    )
                                                })}
                                                {brandUsers.length === 0 && (
                                                    <p className="text-xs text-gray-500">No users available</p>
                                                )}
                                            </div>
                                        </div>
                                        {isPrivate && !hasRestrictSelection && (
                                            <p className="text-xs text-amber-600">
                                                Select at least one role or user for private categories.
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Footer */}
                    <div className="flex items-center justify-end gap-2 border-t border-gray-200 bg-gray-50 px-4 py-3 sm:px-5">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={!canSave}
                            className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {loading ? 'Creating…' : 'Create'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )

    return createPortal(modalContent, document.body)
}
