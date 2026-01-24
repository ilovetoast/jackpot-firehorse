import { Link, router, useForm, usePage } from '@inertiajs/react'
import { useState, useRef, useEffect } from 'react'
import PlanLimitIndicator from '../../Components/PlanLimitIndicator'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import CategoryIconSelector from '../../Components/CategoryIconSelector'
import CategoryUpgradeModal from '../../Components/CategoryUpgradeModal'
import { CategoryIcon, getIconById } from '../../Helpers/categoryIcons'

export default function CategoriesIndex({ categories, filters, limits, asset_types, plan }) {
    const { auth } = usePage().props
    const { data, setData, post, processing, reset } = useForm({
        name: '',
        slug: '',
        icon: 'folder',
        asset_type: 'asset', // Default to 'asset' to match initial activeTab
        is_private: false,
    })
    const [showCreateForm, setShowCreateForm] = useState(false)
    const [activeTab, setActiveTab] = useState('asset') // 'asset' or 'deliverable'
    const [editingId, setEditingId] = useState(null)
    
    // Update form asset_type when tab changes
    useEffect(() => {
        if (activeTab === 'asset') {
            setData('asset_type', 'asset')
        } else if (activeTab === 'deliverable') {
            setData('asset_type', 'deliverable')
        }
    }, [activeTab, setData])
    const [editName, setEditName] = useState('')
    const [editIcon, setEditIcon] = useState('folder')
    const [draggedItem, setDraggedItem] = useState(null)
    const [localCategories, setLocalCategories] = useState(categories || [])
    const [upgradeModalOpen, setUpgradeModalOpen] = useState(false)
    const [selectedCategoryForUpgrade, setSelectedCategoryForUpgrade] = useState(null)
    const editInputRef = useRef(null)

    // Update local categories when props change
    useEffect(() => {
        setLocalCategories(categories || [])
    }, [categories])

    // Focus edit input when editing starts
    useEffect(() => {
        if (editingId && editInputRef.current) {
            editInputRef.current.focus()
            editInputRef.current.select()
        }
    }, [editingId])

    // Filter categories by active tab
    const filteredCategories = localCategories.filter(cat => {
        if (activeTab === 'asset') return cat.asset_type === 'asset'
        if (activeTab === 'deliverable') return cat.asset_type === 'deliverable'
        return true
    }).sort((a, b) => {
        // Sort by order first, then by name
        if (a.order !== b.order) return (a.order || 0) - (b.order || 0)
        return a.name.localeCompare(b.name)
    })

    const handleFilter = (key, value) => {
        const newFilters = { ...filters, [key]: value }
        router.get('/app/categories', newFilters, { preserveState: true })
    }

    const handleCreate = (e) => {
        e.preventDefault()
        post('/app/categories', {
            onSuccess: () => {
                setShowCreateForm(false)
                reset()
            },
        })
    }

    const handleDelete = (categoryId) => {
        if (confirm('Are you sure you want to delete this category?')) {
            router.delete(`/app/categories/${categoryId}`, {
                preserveScroll: true,
            })
        }
    }

    const handleEditStart = (category) => {
        if (category.is_system || category.is_locked || category.is_template) return
        setEditingId(category.id)
        setEditName(category.name)
        setEditIcon(category.icon || 'folder')
    }

    const handleEditCancel = () => {
        setEditingId(null)
        setEditName('')
        setEditIcon('folder')
    }

    const handleEditSave = (categoryId) => {
        if (!editName.trim()) {
            handleEditCancel()
            return
        }

        router.put(`/app/categories/${categoryId}`, {
            name: editName.trim(),
            icon: editIcon,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingId(null)
                setEditName('')
                setEditIcon('folder')
            },
        })
    }

    const handleEditKeyDown = (e, categoryId) => {
        if (e.key === 'Enter') {
            handleEditSave(categoryId)
        } else if (e.key === 'Escape') {
            handleEditCancel()
        }
    }

    // Drag and drop handlers
    const handleDragStart = (e, category) => {
        if (category.is_template) return
        setDraggedItem(category)
        e.dataTransfer.effectAllowed = 'move'
        e.dataTransfer.setData('text/plain', category.id?.toString() || '')
        // Make the dragged element semi-transparent
        if (e.target) {
            e.target.style.opacity = '0.5'
        }
    }

    const handleDragOver = (e) => {
        e.preventDefault()
        e.stopPropagation()
        e.dataTransfer.dropEffect = 'move'
    }

    const handleDragEnter = (e) => {
        e.preventDefault()
        e.stopPropagation()
    }

    const handleDrop = (e, targetCategory) => {
        e.preventDefault()
        e.stopPropagation()
        if (!draggedItem || draggedItem.id === targetCategory.id || targetCategory.is_template) {
            setDraggedItem(null)
            return
        }

        const draggedIndex = filteredCategories.findIndex(c => c.id === draggedItem.id)
        const targetIndex = filteredCategories.findIndex(c => c.id === targetCategory.id)

        if (draggedIndex === -1 || targetIndex === -1) {
            setDraggedItem(null)
            return
        }

        // Create new order array
        const newCategories = [...filteredCategories]
        const [removed] = newCategories.splice(draggedIndex, 1)
        newCategories.splice(targetIndex, 0, removed)

        // Update order values
        const orderUpdates = newCategories.map((cat, index) => ({
            id: cat.id,
            order: index * 10, // Use increments of 10 for easier reordering
        })).filter(item => item.id && !newCategories.find(c => c.id === item.id && c.is_template))

        // Update local state immediately for better UX
        setLocalCategories(prev => {
            const updated = [...prev]
            orderUpdates.forEach(({ id, order }) => {
                const idx = updated.findIndex(c => c.id === id)
                if (idx !== -1) {
                    updated[idx] = { ...updated[idx], order }
                }
            })
            return updated
        })

        // Send update to server
        router.post('/app/categories/update-order', {
            categories: orderUpdates,
        }, {
            preserveScroll: true,
            onError: () => {
                // Revert on error
                setLocalCategories(categories || [])
            },
        })

        setDraggedItem(null)
    }

    const handleDragEnd = (e) => {
        // Reset opacity
        if (e.target) {
            e.target.style.opacity = ''
        }
        setDraggedItem(null)
    }

    const formatLimit = (limit) => {
        if (limit === Number.MAX_SAFE_INTEGER || limit === 2147483647) {
            return 'Unlimited'
        }
        return limit
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8 flex items-center justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-gray-900">Categories</h1>
                            <p className="mt-2 text-sm text-gray-700">Manage your asset categories</p>
                            {plan && !plan.can_edit_system_categories && (
                                <p className="mt-1 text-xs text-amber-600">
                                    System categories are locked. Upgrade to <span className="font-semibold">Pro</span> or <span className="font-semibold">Enterprise</span> to edit system categories.
                                </p>
                            )}
                        </div>
                        {limits.can_create && (
                            <button
                                type="button"
                                onClick={() => setShowCreateForm(!showCreateForm)}
                                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                            >
                                {showCreateForm ? 'Cancel' : 'Create Category'}
                            </button>
                        )}
                        {!limits.can_create && (
                            <div className="text-sm text-gray-500">
                                Limit reached ({limits.current}/{formatLimit(limits.max)})
                                <Link
                                    href="/app/billing"
                                    className="ml-2 font-medium text-indigo-600 hover:text-indigo-500"
                                >
                                    Upgrade →
                                </Link>
                            </div>
                        )}
                    </div>

                    {/* Limit Indicator */}
                    {!limits.can_create && (
                        <PlanLimitIndicator
                            current={limits.current}
                            max={limits.max}
                            label="Categories"
                            className="mb-6"
                        />
                    )}

                    {/* Create Form */}
                    {showCreateForm && (
                        <div className="mb-6 overflow-hidden rounded-lg bg-white shadow">
                            <form onSubmit={handleCreate} className="px-4 py-5 sm:p-6">
                                <div className="space-y-4">
                                    <div>
                                        <label htmlFor="create_name" className="block text-sm font-medium text-gray-700">
                                            Name
                                        </label>
                                        <input
                                            type="text"
                                            id="create_name"
                                            required
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            className="mt-1 block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        />
                                    </div>
                                    <div>
                                        <label htmlFor="create_asset_type" className="block text-sm font-medium text-gray-700">
                                            Asset Type
                                        </label>
                                        <select
                                            id="create_asset_type"
                                            required
                                            value={data.asset_type}
                                            onChange={(e) => setData('asset_type', e.target.value)}
                                            className="mt-1 block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                        >
                                            {asset_types?.map((type) => (
                                                <option key={type.value} value={type.value}>
                                                    {type.label}
                                                </option>
                                            ))}
                                        </select>
                                        <p className="mt-1 text-xs text-gray-500">
                                            Creating a {data.asset_type === 'asset' ? 'Asset' : 'Deliverable'} category
                                        </p>
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Icon
                                        </label>
                                        <CategoryIconSelector
                                            value={data.icon}
                                            onChange={(iconId) => setData('icon', iconId)}
                                        />
                                    </div>
                                    <div className="flex items-center">
                                        <input
                                            type="checkbox"
                                            id="create_is_private"
                                            checked={data.is_private}
                                            onChange={(e) => setData('is_private', e.target.checked)}
                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <label htmlFor="create_is_private" className="ml-2 block text-sm text-gray-900">
                                            Private
                                        </label>
                                    </div>
                                    <div className="flex justify-end gap-3">
                                        <button
                                            type="button"
                                            onClick={() => setShowCreateForm(false)}
                                            className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                        >
                                            Create
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    )}

                    {/* Tab Navigation */}
                    <div className="mb-6">
                        <div className="border-b border-gray-200">
                            <nav className="-mb-px flex space-x-8" aria-label="Tabs">
                                <button
                                    onClick={() => setActiveTab('asset')}
                                    className={`
                                        group inline-flex items-center border-b-2 py-4 px-1 text-sm font-medium transition-colors
                                        ${activeTab === 'asset'
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                        }
                                    `}
                                >
                                    <svg
                                        className={`
                                            -ml-0.5 mr-2 h-5 w-5
                                            ${activeTab === 'asset' ? 'text-indigo-500' : 'text-gray-400 group-hover:text-gray-500'}
                                        `}
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        strokeWidth="1.5"
                                        stroke="currentColor"
                                    >
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                                    </svg>
                                    Asset
                                </button>
                                <button
                                    onClick={() => setActiveTab('deliverable')}
                                    className={`
                                        group inline-flex items-center border-b-2 py-4 px-1 text-sm font-medium transition-colors
                                        ${activeTab === 'deliverable'
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                        }
                                    `}
                                >
                                    <svg
                                        className={`
                                            -ml-0.5 mr-2 h-5 w-5
                                            ${activeTab === 'deliverable' ? 'text-indigo-500' : 'text-gray-400 group-hover:text-gray-500'}
                                        `}
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        strokeWidth="1.5"
                                        stroke="currentColor"
                                    >
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75m0 0h.375c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-.375A1.125 1.125 0 012 19.875v-4.5c0-.621.504-1.125 1.125-1.125H4.5z" />
                                    </svg>
                                    Deliverable
                                </button>
                            </nav>
                        </div>
                    </div>

                    {/* Categories List */}
                    <div className="overflow-hidden bg-white shadow sm:rounded-md">
                        <ul className="divide-y divide-gray-200">
                            {filteredCategories?.length === 0 ? (
                                <li className="px-6 py-4 text-center text-sm text-gray-500">
                                    No categories found. Create your first category to get started.
                                </li>
                            ) : (
                                filteredCategories?.map((category) => {
                                    const isDragging = draggedItem?.id === category.id
                                    // Custom categories are always editable if not locked/template
                                    // System categories are editable only if plan allows it
                                    const isEditable = category.id && !category.is_template && !category.is_locked && (
                                        !category.is_system || (plan?.can_edit_system_categories === true)
                                    )
                                    const isDeletable = category.id && !category.is_template && !category.is_locked && !category.is_system

                                    return (
                                        <li
                                            key={category.id || `template-${category.slug}`}
                                            draggable={!category.is_template && category.id && editingId !== category.id}
                                            onDragStart={(e) => {
                                                // Don't drag if clicking on interactive elements
                                                if (e.target.closest('button, input, a, [role="button"]')) {
                                                    e.preventDefault()
                                                    return
                                                }
                                                handleDragStart(e, category)
                                            }}
                                            onDragOver={handleDragOver}
                                            onDragEnter={handleDragEnter}
                                            onDrop={(e) => handleDrop(e, category)}
                                            onDragEnd={handleDragEnd}
                                            className={`
                                                px-6 py-4 transition-colors
                                                ${isDragging ? 'opacity-50' : 'hover:bg-gray-50'}
                                                ${!category.is_template && category.id && editingId !== category.id ? 'cursor-move' : ''}
                                            `}
                                        >
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center flex-1 min-w-0">
                                                    {/* Drag Handle Icon */}
                                                    {!category.is_template && category.id && (
                                                        <svg
                                                            className="h-5 w-5 text-gray-400 mr-3 flex-shrink-0 cursor-move"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            strokeWidth="1.5"
                                                            stroke="currentColor"
                                                        >
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                                        </svg>
                                                    )}

                                                    {/* Category Icon */}
                                                    <div className="mr-3 flex-shrink-0">
                                                        {category.is_system || category.is_template ? (
                                                            <CategoryIcon 
                                                                iconId={category.icon || 'folder'} 
                                                                className="h-5 w-5" 
                                                                color="text-gray-400"
                                                            />
                                                        ) : (
                                                            <CategoryIcon 
                                                                iconId={category.icon || 'plus-circle'} 
                                                                className="h-5 w-5" 
                                                                color="text-indigo-500"
                                                            />
                                                        )}
                                                    </div>

                                                    <div className="flex-1 min-w-0">
                                                        {editingId === category.id ? (
                                                            <div className="space-y-2">
                                                                <input
                                                                    ref={editInputRef}
                                                                    type="text"
                                                                    value={editName}
                                                                    onChange={(e) => setEditName(e.target.value)}
                                                                    onKeyDown={(e) => handleEditKeyDown(e, category.id)}
                                                                    className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                                                />
                                                                <CategoryIconSelector
                                                                    value={editIcon}
                                                                    onChange={setEditIcon}
                                                                />
                                                                <div className="flex items-center gap-2 pt-1">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleEditSave(category.id)}
                                                                        disabled={processing || !editName.trim()}
                                                                        className="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                    >
                                                                        <svg className="h-3.5 w-3.5 mr-1" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                                        </svg>
                                                                        Save
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={handleEditCancel}
                                                                        className="inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                                    >
                                                                        <svg className="h-3.5 w-3.5 mr-1" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                                        </svg>
                                                                        Cancel
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        ) : (
                                                            <>
                                                                <div className="flex items-center gap-2">
                                                                    <p className="text-sm font-medium text-gray-900 truncate">
                                                                        {category.name}
                                                                    </p>
                                                                    {category.is_system && (
                                                                        <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-blue-50 text-blue-700 ring-blue-600/20">
                                                                            System
                                                                        </span>
                                                                    )}
                                                                    {category.upgrade_available && category.is_system && (
                                                                        <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-600/20">
                                                                            Update available
                                                                        </span>
                                                                    )}
                                                                    {category.is_private && (
                                                                        <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-indigo-100 text-indigo-800 ring-indigo-600/20">
                                                                            Private
                                                                        </span>
                                                                    )}
                                                                    {category.is_system && !plan?.can_edit_system_categories && (
                                                                        <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-600 ring-gray-600/20" title="Upgrade to Pro or Enterprise to edit system categories">
                                                                            Locked
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                <p className="text-sm text-gray-500 truncate">
                                                                    {category.slug}
                                                                </p>
                                                            </>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2 ml-4">
                                                    {category.upgrade_available && category.is_system && category.id && (
                                                        <button
                                                            type="button"
                                                            onClick={(e) => {
                                                                e.stopPropagation()
                                                                setSelectedCategoryForUpgrade(category)
                                                                setUpgradeModalOpen(true)
                                                            }}
                                                            onMouseDown={(e) => e.stopPropagation()}
                                                            className="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600"
                                                            title="Review update"
                                                        >
                                                            Review update
                                                        </button>
                                                    )}
                                                    {isEditable && editingId !== category.id && (
                                                        <button
                                                            type="button"
                                                            onClick={(e) => {
                                                                e.stopPropagation()
                                                                handleEditStart(category)
                                                            }}
                                                            onMouseDown={(e) => e.stopPropagation()}
                                                            className="rounded-md bg-white px-2 py-1.5 text-sm font-semibold text-gray-600 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                            title="Edit category name"
                                                        >
                                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                            </svg>
                                                        </button>
                                                    )}
                                                    {isDeletable && (
                                                        <button
                                                            type="button"
                                                            onClick={(e) => {
                                                                e.stopPropagation()
                                                                handleDelete(category.id)
                                                            }}
                                                            onMouseDown={(e) => e.stopPropagation()}
                                                            disabled={processing}
                                                            className="rounded-md bg-white px-2 py-1.5 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-red-300 hover:bg-red-50"
                                                            title="Delete category"
                                                        >
                                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                            </svg>
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        </li>
                                    )
                                })
                            )}
                        </ul>
                    </div>
                </div>
            </main>
            <AppFooter />
            {upgradeModalOpen && selectedCategoryForUpgrade && (
                <CategoryUpgradeModal
                    category={selectedCategoryForUpgrade}
                    isOpen={upgradeModalOpen}
                    onClose={() => {
                        setUpgradeModalOpen(false)
                        setSelectedCategoryForUpgrade(null)
                    }}
                    onSuccess={() => {
                        setUpgradeModalOpen(false)
                        setSelectedCategoryForUpgrade(null)
                        router.reload()
                    }}
                />
            )}
        </div>
    )
}
