import { useState, useEffect, useRef } from 'react'
import { router } from '@inertiajs/react'
import { TrashIcon, XMarkIcon } from '@heroicons/react/24/outline'

/**
 * Category Settings slide-over for editing custom categories.
 * Basic info, permissions, danger zone. No page reload.
 */
export default function CategorySettingsSlideOver({
    isOpen,
    onClose,
    category,
    brandId,
    brandRoles = [],
    brandUsers = [],
    onSuccess,
    onDelete,
}) {
    const [name, setName] = useState('')
    const [assetType, setAssetType] = useState('asset')
    const [isHidden, setIsHidden] = useState(false)
    const [isPrivate, setIsPrivate] = useState(false)
    const [selectedRoles, setSelectedRoles] = useState([])
    const [selectedUserIds, setSelectedUserIds] = useState([])
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)
    const [slideIn, setSlideIn] = useState(false)
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
            setSlideIn(false)
            const raf = requestAnimationFrame(() => {
                requestAnimationFrame(() => setSlideIn(true))
            })
            return () => cancelAnimationFrame(raf)
        }
    }, [isOpen, category])

    useEffect(() => {
        if (isOpen && slideIn && nameInputRef.current) {
            nameInputRef.current.focus()
        }
    }, [isOpen, slideIn])

    useEffect(() => {
        if (isOpen) document.body.style.overflow = 'hidden'
        return () => { document.body.style.overflow = '' }
    }, [isOpen])

    if (!isOpen || !category || category.is_system) return null

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
                aria-labelledby="category-settings-title"
            >
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <h2 id="category-settings-title" className="text-lg font-semibold text-gray-900">
                        Category Settings
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-2 -m-2 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                        aria-label="Close"
                    >
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto px-6 py-6 space-y-6">
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
                                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
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
                                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
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

                    {error && (
                        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 border border-red-100">
                            {error}
                        </div>
                    )}
                </div>

                <div className="flex justify-end gap-2 px-6 py-4 border-t border-gray-200 bg-gray-50">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={handleSave}
                        disabled={loading || !name?.trim() || (isPrivate && !hasRestrictSelection)}
                        className="rounded-lg px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {loading ? 'Saving…' : 'Save'}
                    </button>
                </div>
            </div>

        </>
    )
}
