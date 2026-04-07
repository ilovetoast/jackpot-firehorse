import { useState, useEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import { router } from '@inertiajs/react'
import { ArrowTopRightOnSquareIcon, TrashIcon, XMarkIcon } from '@heroicons/react/24/outline'

/**
 * Centered modal for editing category settings.
 * Matches the Create Custom Metadata Field modal style.
 * Basic info, permissions, metadata fields link, danger zone.
 */
export default function CategorySettingsModal({
    isOpen,
    onClose,
    category,
    brandId,
    brandRoles = [],
    brandUsers = [],
    onSuccess,
    onDelete,
    canViewMetadataRegistry = true,
}) {
    const [name, setName] = useState('')
    const [assetType, setAssetType] = useState('asset')
    const [isHidden, setIsHidden] = useState(false)
    const [isPrivate, setIsPrivate] = useState(false)
    const [selectedRoles, setSelectedRoles] = useState([])
    const [selectedUserIds, setSelectedUserIds] = useState([])
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)
    const nameInputRef = useRef(null)

    useEffect(() => {
        if (isOpen && category) {
            setName(category.name || '')
            setAssetType(category.asset_type || 'asset')
            setIsHidden(category.is_hidden || false)
            setIsPrivate(category.is_private || false)
            const rules = category.access_rules || []
            setSelectedRoles(rules.filter((r) => r.type === 'role').map((r) => r.role))
            setSelectedUserIds(rules.filter((r) => r.type === 'user').map((r) => r.user_id))
            setError(null)
        }
    }, [isOpen, category])

    useEffect(() => {
        if (isOpen && nameInputRef.current) {
            nameInputRef.current.focus()
        }
    }, [isOpen])

    if (!isOpen || !category || category.is_system) return null

    if (typeof window === 'undefined' || !document.body) return null

    const hasRestrictSelection = selectedRoles.length > 0 || selectedUserIds.length > 0
    const roles = brandRoles.length > 0 ? brandRoles : ['admin', 'brand_manager', 'contributor', 'viewer']

    const toggleRole = (role) => {
        const roleKey = typeof role === 'string' ? role : role.value || role
        setSelectedRoles((prev) =>
            prev.includes(roleKey) ? prev.filter((r) => r !== roleKey) : [...prev, roleKey]
        )
    }

    const toggleUser = (userId) => {
        setSelectedUserIds((prev) =>
            prev.includes(userId) ? prev.filter((id) => id !== userId) : [...prev, userId]
        )
    }

    const handleSave = () => {
        const trimmed = name?.trim()
        if (!trimmed || trimmed.length <= 2) {
            setError('Category name must be at least 3 characters.')
            return
        }
        if (isPrivate && !hasRestrictSelection) {
            setError('Select at least one role or user for restricted access.')
            return
        }

        setLoading(true)
        setError(null)

        const accessRules = []
        if (isPrivate) {
            selectedRoles.forEach((role) => accessRules.push({ type: 'role', role }))
            selectedUserIds.forEach((userId) => accessRules.push({ type: 'user', user_id: userId }))
        }

        const data = {
            name: trimmed,
            asset_type: assetType,
            is_hidden: isHidden,
            is_private: isPrivate,
            ...(accessRules.length > 0 && { access_rules: accessRules }),
        }

        const updateUrl = typeof route === 'function'
            ? route('brands.categories.update', { brand: brandId, category: category.id })
            : `/app/brands/${brandId}/categories/${category.id}`

        router.put(updateUrl, data, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                onSuccess?.({ ...category, name: trimmed, asset_type: assetType, is_hidden: isHidden, is_private: isPrivate, access_rules: accessRules })
                onClose()
            },
            onError: (errors) => {
                setError(errors?.error || errors?.name?.[0] || 'Failed to update category.')
            },
            onFinish: () => setLoading(false),
        })
    }

    const handleDeleteClick = () => {
        onDelete?.(category)
        onClose()
    }

    const slug = category.slug || category.name?.toLowerCase().replace(/\s+/g, '-') || ''
    const manageCategoriesUrl = typeof route === 'function'
        ? route('manage.categories', { category: slug })
        : `/app/manage/categories?category=${encodeURIComponent(slug)}`

    const modalContent = (
        <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="category-settings-title" role="dialog" aria-modal="true">
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
                        <h3 id="category-settings-title" className="text-base font-semibold text-gray-900">
                            Category Settings
                        </h3>
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
                    <div className="px-4 py-4 sm:px-5 sm:py-5 max-h-[calc(100vh-12rem)] overflow-y-auto space-y-6">
                        {error && (
                            <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700 border border-red-100">
                                {error}
                            </div>
                        )}

                        {/* Section: Basic */}
                        <section>
                            <h3 className="text-sm font-semibold text-gray-900 mb-3">Basic</h3>
                            <div className="space-y-4">
                                <div>
                                    <label htmlFor="category-name" className="block text-sm font-medium text-gray-700 mb-1">
                                        Category Name
                                    </label>
                                    <input
                                        ref={nameInputRef}
                                        id="category-name"
                                        type="text"
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        className="block w-full rounded-md border border-gray-300 py-1.5 px-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label htmlFor="category-type" className="block text-sm font-medium text-gray-700 mb-1">
                                        Category Type
                                    </label>
                                    <select
                                        id="category-type"
                                        value={assetType}
                                        onChange={(e) => setAssetType(e.target.value)}
                                        className="block w-full rounded-md border border-gray-300 py-1.5 px-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    >
                                        <option value="asset">Asset</option>
                                        <option value="deliverable">Execution</option>
                                    </select>
                                </div>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={isHidden}
                                        onChange={(e) => setIsHidden(e.target.checked)}
                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span className="text-sm text-gray-700">Hidden from uploader</span>
                                </label>
                            </div>
                        </section>

                        <hr className="border-gray-200" />

                        {/* Section: Metadata Fields */}
                        {canViewMetadataRegistry && (
                            <>
                                <section>
                                    <h3 className="text-sm font-semibold text-gray-900 mb-2">Metadata Fields</h3>
                                    <p className="text-sm text-gray-500 mb-3">
                                        Configure which metadata fields appear for assets in this category.
                                    </p>
                                    <a
                                        href={manageCategoriesUrl}
                                        className="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                    >
                                        <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                                        Configure metadata fields
                                    </a>
                                </section>
                                <hr className="border-gray-200" />
                            </>
                        )}

                        {/* Section: Permissions */}
                        <section>
                            <h3 className="text-sm font-semibold text-gray-900 mb-3">Permissions</h3>
                            <label className="flex items-center gap-2 mb-3">
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
                                <span className="text-sm text-gray-700">Restrict access</span>
                            </label>
                            {isPrivate && (
                                <div className="rounded-lg border border-gray-100 bg-gray-50/50 p-4 space-y-4">
                                    <div>
                                        <label className="block text-xs font-medium text-gray-600 mb-2">Brand Roles</label>
                                        <div className="space-y-2">
                                            {roles.map((role) => {
                                                const roleKey = typeof role === 'string' ? role : role.value || role
                                                const roleLabel = typeof role === 'string' ? role.replace(/_/g, ' ') : (role.label || roleKey).replace(/_/g, ' ')
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
                                        <label className="block text-xs font-medium text-gray-600 mb-2">Individual Users</label>
                                        <div className="space-y-2 max-h-40 overflow-y-auto">
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
                                                            {user.name} {user.email && `(${user.email})`}
                                                        </span>
                                                    </label>
                                                )
                                            })}
                                            {brandUsers.length === 0 && <p className="text-xs text-gray-500">No users available</p>}
                                        </div>
                                    </div>
                                    {!hasRestrictSelection && (
                                        <p className="text-xs text-amber-600">Select at least one role or user.</p>
                                    )}
                                </div>
                            )}
                        </section>

                        <hr className="border-gray-200" />

                        {/* Section: Danger Zone */}
                        <section>
                            <h3 className="text-sm font-semibold text-gray-900 mb-3">Danger Zone</h3>
                            <button
                                type="button"
                                onClick={handleDeleteClick}
                                className="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100 transition-colors"
                            >
                                <TrashIcon className="h-4 w-4" />
                                Delete Category
                            </button>
                        </section>
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
                            disabled={loading || !name?.trim() || (isPrivate && !hasRestrictSelection)}
                            className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {loading ? 'Saving…' : 'Save'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )

    return createPortal(modalContent, document.body)
}
