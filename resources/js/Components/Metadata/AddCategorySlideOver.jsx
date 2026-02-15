import { useState, useEffect, useRef } from 'react'
import { XMarkIcon } from '@heroicons/react/24/outline'

/**
 * Slide-over panel for adding a new category.
 * Fixed right side, max-w-md, backdrop overlay.
 * Shows category plan limit, restrict access (roles + users), validation.
 */
export default function AddCategorySlideOver({
    isOpen,
    onClose,
    assetType: assetTypeProp,
    brandId,
    brandName,
    categoryLimits = null,
    onSuccess,
}) {
    const [name, setName] = useState('')
    const [assetType, setAssetType] = useState(assetTypeProp || 'asset')
    const [isPrivate, setIsPrivate] = useState(false)
    const [selectedRoles, setSelectedRoles] = useState([])
    const [selectedUserIds, setSelectedUserIds] = useState([])
    const [brandRoles, setBrandRoles] = useState([])
    const [brandUsers, setBrandUsers] = useState([])
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)
    const [slideIn, setSlideIn] = useState(false)
    const nameInputRef = useRef(null)

    useEffect(() => {
        if (isOpen) {
            setSlideIn(false)
            const raf = requestAnimationFrame(() => {
                requestAnimationFrame(() => setSlideIn(true))
            })
            return () => cancelAnimationFrame(raf)
        }
    }, [isOpen])

    useEffect(() => {
        if (isOpen && slideIn && nameInputRef.current) {
            nameInputRef.current.focus()
        }
    }, [isOpen, slideIn])

    useEffect(() => {
        if (isOpen) {
            setName('')
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

        if (categoryLimits && !categoryLimits.can_create) {
            setError("You've reached your category limit for this plan.")
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
                onSuccess({
                    id: data.category.id,
                    name: data.category.name,
                    slug: data.category.slug,
                    asset_type: type,
                    is_system: false,
                    is_hidden: false,
                    brand_id: brandId,
                    brand_name: brandName,
                    sort_order: data.category.sort_order,
                })
                onClose()
            } else {
                setError(data.message || data.error || data.errors?.name?.[0] || 'Failed to create category.')
            }
        } catch (e) {
            setError('Network error. Please try again.')
        } finally {
            setLoading(false)
        }
    }

    if (!isOpen) return null

    const type = assetTypeProp ?? assetType
    const atLimit = categoryLimits && !categoryLimits.can_create
    const canSave =
        (name?.trim().length ?? 0) > 2 &&
        !loading &&
        !atLimit &&
        (!isPrivate || hasRestrictSelection)

    const limitsLabel =
        categoryLimits && categoryLimits.max > 0
            ? `${categoryLimits.current} of ${categoryLimits.max} custom categories used`
            : null

    return (
        <>
            <div
                className="fixed inset-0 z-40 bg-black/20 backdrop-blur-sm transition-opacity duration-300"
                onClick={onClose}
                aria-hidden="true"
            />
            <div
                className={`fixed inset-y-0 right-0 z-50 w-full max-w-md bg-white shadow-xl flex flex-col rounded-l-lg transition-transform duration-300 ease-out ${
                    slideIn ? 'translate-x-0' : 'translate-x-full'
                }`}
                role="dialog"
                aria-modal="true"
                aria-labelledby="add-category-title"
            >
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <div>
                        <h2 id="add-category-title" className="text-lg font-semibold text-gray-900">
                            New Category
                        </h2>
                        {limitsLabel && (
                            <p
                                className={`mt-1 text-xs ${atLimit ? 'text-amber-600' : 'text-gray-500'}`}
                            >
                                {limitsLabel}
                            </p>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-2 -m-2 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                        aria-label="Close"
                    >
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto px-6 py-6 space-y-3">
                    {atLimit && (
                        <p className="text-sm text-amber-700 rounded-md bg-amber-50 px-3 py-2 border border-amber-100">
                            You&apos;ve reached your category limit for this plan.
                        </p>
                    )}

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
                            className="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            autoFocus
                        />
                        <p className="mt-1.5 text-xs text-gray-500">
                            Categories help organize your assets and define metadata behavior.
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
                                className="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
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

                    {error && (
                        <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700 border border-red-100">
                            {error}
                        </div>
                    )}
                </div>

                <div className="flex items-center justify-end gap-2 px-6 py-4 border-t border-gray-200 bg-gray-50">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={handleSave}
                        disabled={!canSave}
                        className="rounded-md px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {loading ? 'Saving…' : 'Create'}
                    </button>
                </div>
            </div>
        </>
    )
}
