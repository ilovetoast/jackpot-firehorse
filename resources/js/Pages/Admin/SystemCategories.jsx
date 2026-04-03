import { useState, useEffect } from 'react'
import { useForm, Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import CategoryIconSelector from '../../Components/CategoryIconSelector'
import { CategoryIcon } from '../../Helpers/categoryIcons'
import ConfirmDialog from '../../Components/ConfirmDialog'
import {
    PlusIcon,
    PencilIcon,
    TrashIcon,
    XMarkIcon,
    CheckIcon,
    InformationCircleIcon,
    Bars3Icon,
} from '@heroicons/react/24/outline'

export default function SystemCategories({ templates, asset_types }) {
    const { auth, flash } = usePage().props
    const [showForm, setShowForm] = useState(false)
    const [editingTemplate, setEditingTemplate] = useState(null)
    const [deletingTemplate, setDeletingTemplate] = useState(null)
    const [draggedTemplate, setDraggedTemplate] = useState(null)
    const [localTemplates, setLocalTemplates] = useState(templates || [])
    const [deleteConfirm, setDeleteConfirm] = useState({ open: false, template: null })

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        slug: '',
        icon: 'folder',
        asset_type: 'asset',
        is_hidden: false,
        auto_provision: false,
        sort_order: 0,
    })

    const handleCreate = () => {
        setEditingTemplate(null)
        reset()
        setShowForm(true)
    }

    const handleEdit = (template) => {
        setEditingTemplate(template)
        setData({
            name: template.name,
            slug: template.slug,
            icon: template.icon || 'folder',
            asset_type: template.asset_type,
            is_hidden: template.is_hidden,
            auto_provision: !!template.auto_provision,
            sort_order: template.sort_order,
        })
        setShowForm(true)
    }

    const handleCancel = () => {
        setShowForm(false)
        setEditingTemplate(null)
        reset()
    }

    const handleSubmit = (e) => {
        e.preventDefault()
        if (editingTemplate) {
            put(`/app/admin/system-categories/${editingTemplate.id}`, {
                onSuccess: () => {
                    handleCancel()
                },
            })
        } else {
            post('/app/admin/system-categories', {
                onSuccess: () => {
                    handleCancel()
                },
            })
        }
    }

    const handleDelete = (template) => {
        setDeleteConfirm({ open: true, template })
    }

    const confirmDelete = () => {
        if (deleteConfirm.template) {
            router.delete(`/app/admin/system-categories/${deleteConfirm.template.id}`, {
                preserveScroll: true,
                onSuccess: () => {
                    setDeleteConfirm({ open: false, template: null })
                    setDeletingTemplate(null)
                },
            })
        }
    }

    // Update local templates when props change
    useEffect(() => {
        setLocalTemplates(templates || [])
    }, [templates])

    const handleDragStart = (e, template) => {
        setDraggedTemplate(template)
        e.dataTransfer.effectAllowed = 'move'
        e.dataTransfer.setData('text/plain', template.id?.toString() || '')
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

    const handleDrop = (e, targetTemplate, assetType) => {
        e.preventDefault()
        e.stopPropagation()
        if (!draggedTemplate || draggedTemplate.id === targetTemplate.id || draggedTemplate.asset_type !== assetType) {
            setDraggedTemplate(null)
            return
        }

        const filteredTemplates = localTemplates.filter(t => t.asset_type === assetType)
        const draggedIndex = filteredTemplates.findIndex(t => t.id === draggedTemplate.id)
        const targetIndex = filteredTemplates.findIndex(t => t.id === targetTemplate.id)

        if (draggedIndex === -1 || targetIndex === -1) {
            setDraggedTemplate(null)
            return
        }

        // Create new order array
        const newTemplates = [...filteredTemplates]
        const [removed] = newTemplates.splice(draggedIndex, 1)
        newTemplates.splice(targetIndex, 0, removed)

        // Update sort_order values (use increments of 10 for easier reordering)
        const orderUpdates = newTemplates.map((template, index) => ({
            id: template.id,
            sort_order: index * 10,
        }))

        // Update local state immediately for better UX
        setLocalTemplates(prev => {
            const updated = [...prev]
            orderUpdates.forEach(({ id, sort_order }) => {
                const idx = updated.findIndex(t => t.id === id)
                if (idx !== -1) {
                    updated[idx] = { ...updated[idx], sort_order }
                }
            })
            return updated
        })

        // Send update to server
        router.post('/app/admin/system-categories/update-order', {
            templates: orderUpdates,
        }, {
            preserveScroll: true,
            onError: () => {
                // Revert on error
                setLocalTemplates(templates || [])
            },
        })

        setDraggedTemplate(null)
    }

    const handleDragEnd = (e) => {
        // Reset opacity
        if (e.target) {
            e.target.style.opacity = ''
        }
        setDraggedTemplate(null)
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <Link
                            href="/app/admin"
                            className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block"
                        >
                            ← Back to Admin
                        </Link>
                        <div className="flex items-start justify-between">
                            <div className="flex-1">
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">System Categories</h1>
                                <p className="mt-2 text-sm text-gray-700">
                                    This page is the <strong>platform catalog</strong>: every template here is <em>available</em> to all tenants. <strong>Auto-add to new brands</strong> creates the folder row on new brands and queues a hidden copy on existing brands so tenants can show it when ready. <strong>Catalog only</strong> means the row is created when a tenant adds or shows that folder (same API as “add from catalog”). Saving a template pushes <strong>name and icon</strong> to all brand rows that already use this slug; tenants cannot rename system folders locally.
                                </p>
                                <div className="mt-4 rounded-md bg-blue-50 p-4">
                                    <div className="flex">
                                        <div className="flex-shrink-0">
                                            <InformationCircleIcon className="h-5 w-5 text-blue-400" aria-hidden="true" />
                                        </div>
                                        <div className="ml-3">
                                            <p className="text-sm text-blue-700">
                                                <strong>Visibility:</strong> <strong>Hidden template default</strong> applies to <em>new</em> brands and to the template itself in listings. When auto-adding to existing brands, folders are created <strong>hidden</strong> regardless of that checkbox so libraries do not change until a tenant enables them in Metadata → By category. Each brand may show at most 20 visible categories per asset and executions library.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="ml-6 flex-shrink-0">
                                <button
                                    type="button"
                                    onClick={handleCreate}
                                    className="inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                >
                                    <PlusIcon className="-ml-0.5 mr-1.5 h-5 w-5" aria-hidden="true" />
                                    New Category
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Form Modal */}
                    {showForm && (
                        <div className="mb-6 rounded-lg bg-white shadow-sm ring-1 ring-gray-200 p-6">
                            <div className="flex items-center justify-between mb-4">
                                <h2 className="text-lg font-semibold text-gray-900">
                                    {editingTemplate ? 'Edit System Category' : 'Create System Category'}
                                </h2>
                                <button
                                    type="button"
                                    onClick={handleCancel}
                                    className="text-gray-400 hover:text-gray-500"
                                >
                                    <XMarkIcon className="h-5 w-5" aria-hidden="true" />
                                </button>
                            </div>

                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label htmlFor="name" className="block text-sm font-medium leading-6 text-gray-900">
                                            Name <span className="text-red-500">*</span>
                                        </label>
                                        <div className="mt-2">
                                            <input
                                                type="text"
                                                name="name"
                                                id="name"
                                                required
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary sm:text-sm sm:leading-6"
                                                placeholder="e.g., Logos"
                                            />
                                            {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name}</p>}
                                        </div>
                                    </div>

                                    <div>
                                        <label htmlFor="slug" className="block text-sm font-medium leading-6 text-gray-900">
                                            Slug
                                        </label>
                                        <div className="mt-2">
                                            <input
                                                type="text"
                                                name="slug"
                                                id="slug"
                                                value={data.slug}
                                                onChange={(e) => setData('slug', e.target.value)}
                                                className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary sm:text-sm sm:leading-6"
                                                placeholder="Auto-generated from name"
                                            />
                                            <p className="mt-1 text-xs text-gray-500">Leave empty to auto-generate from name</p>
                                            {errors.slug && <p className="mt-1 text-sm text-red-600">{errors.slug}</p>}
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium leading-6 text-gray-900 mb-2">
                                        Icon
                                    </label>
                                    <CategoryIconSelector
                                        value={data.icon}
                                        onChange={(iconId) => setData('icon', iconId)}
                                    />
                                </div>

                                <div>
                                    <label htmlFor="asset_type" className="block text-sm font-medium leading-6 text-gray-900">
                                        Asset Type <span className="text-red-500">*</span>
                                    </label>
                                    <div className="mt-2">
                                        <select
                                            name="asset_type"
                                            id="asset_type"
                                            required
                                            value={data.asset_type}
                                            onChange={(e) => setData('asset_type', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-primary sm:text-sm sm:leading-6"
                                        >
                                            {asset_types.map((type) => (
                                                <option key={type.value} value={type.value}>
                                                    {type.label}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.asset_type && <p className="mt-1 text-sm text-red-600">{errors.asset_type}</p>}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label htmlFor="sort_order" className="block text-sm font-medium leading-6 text-gray-900">
                                            Sort Order
                                        </label>
                                        <div className="mt-2">
                                            <input
                                                type="number"
                                                name="sort_order"
                                                id="sort_order"
                                                min="0"
                                                value={data.sort_order}
                                                onChange={(e) => setData('sort_order', parseInt(e.target.value) || 0)}
                                                className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary sm:text-sm sm:leading-6"
                                            />
                                            {errors.sort_order && <p className="mt-1 text-sm text-red-600">{errors.sort_order}</p>}
                                        </div>
                                    </div>

                                    <div className="flex flex-col gap-4 sm:flex-row sm:gap-8">
                                        <div className="flex flex-col gap-1 max-w-md">
                                            <div className="flex items-start gap-3">
                                                <input
                                                    id="is_hidden"
                                                    name="is_hidden"
                                                    type="checkbox"
                                                    checked={data.is_hidden}
                                                    onChange={(e) => setData('is_hidden', e.target.checked)}
                                                    className="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                                />
                                                <label htmlFor="is_hidden" className="block text-sm font-medium text-gray-900">
                                                    Hidden by default (new brands)
                                                </label>
                                            </div>
                                            <p className="text-xs text-gray-500 pl-7">
                                                Applies when a <strong>new</strong> brand is created. Existing brands always get new auto-added folders <strong>hidden</strong> until a tenant shows them.
                                            </p>
                                        </div>
                                        <div className="flex flex-col gap-1 max-w-md">
                                            <div className="flex items-start gap-3">
                                                <input
                                                    id="auto_provision"
                                                    name="auto_provision"
                                                    type="checkbox"
                                                    checked={data.auto_provision}
                                                    onChange={(e) => setData('auto_provision', e.target.checked)}
                                                    className="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                                />
                                                <label htmlFor="auto_provision" className="block text-sm font-medium text-gray-900">
                                                    Auto-add to brands (new + existing)
                                                </label>
                                            </div>
                                            <p className="text-xs text-gray-500 pl-7">
                                                Queues a background job to create the folder on every brand that does not have it yet (hidden on existing brands).
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                {errors.error && (
                                    <div className="rounded-md bg-red-50 p-4">
                                        <p className="text-sm text-red-800">{errors.error}</p>
                                    </div>
                                )}

                                <div className="flex items-center justify-end gap-3 pt-4">
                                    <button
                                        type="button"
                                        onClick={handleCancel}
                                        className="text-sm font-semibold leading-6 text-gray-900"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="inline-flex justify-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50"
                                    >
                                        {processing ? 'Saving...' : editingTemplate ? 'Update' : 'Create'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}

                    {/* Categories Tables - Separated by Asset Type */}
                    <div className="space-y-6">
                        {/* Asset Categories */}
                        <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-4 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Asset Categories</h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    Categories for assets (logos, graphics, photography, etc.)
                                </p>
                            </div>

                            {localTemplates.filter(t => t.asset_type === 'asset').length === 0 ? (
                                <div className="px-6 py-12 text-center">
                                    <p className="text-sm text-gray-500">No asset categories yet.</p>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setData('asset_type', 'asset')
                                            handleCreate()
                                        }}
                                        className="mt-4 inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark"
                                    >
                                        <PlusIcon className="-ml-0.5 mr-1.5 h-5 w-5" aria-hidden="true" />
                                        Create Asset Category
                                    </button>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-10">
                                                    <span className="sr-only">Drag handle</span>
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Name
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Slug
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Sort Order
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Flags
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    New brands
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Brand folders
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {localTemplates.filter(t => t.asset_type === 'asset').map((template) => (
                                                <tr 
                                                    key={template.id} 
                                                    className="hover:bg-gray-50"
                                                    draggable
                                                    onDragStart={(e) => handleDragStart(e, template)}
                                                    onDragOver={handleDragOver}
                                                    onDragEnter={handleDragEnter}
                                                    onDrop={(e) => handleDrop(e, template, 'asset')}
                                                    onDragEnd={handleDragEnd}
                                                >
                                                    <td className="px-6 py-4 whitespace-nowrap cursor-move">
                                                        <Bars3Icon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex items-center gap-3">
                                                            <CategoryIcon 
                                                                iconId={template.icon || 'folder'} 
                                                                className="h-5 w-5 flex-shrink-0" 
                                                                color="text-gray-400"
                                                            />
                                                            <div className="text-sm font-medium text-gray-900">{template.name}</div>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.slug}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.sort_order}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex gap-2">
                                                            {template.is_hidden && (
                                                                <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-700 ring-gray-600/20">
                                                                    Hidden
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        {template.auto_provision ? (
                                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-green-50 text-green-800 ring-green-600/20">
                                                                Auto-add
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-600 ring-gray-600/20">
                                                                Catalog only
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-700 tabular-nums">
                                                            {typeof template.brand_row_count === 'number' ? template.brand_row_count : '—'}
                                                        </div>
                                                        <div className="text-xs text-gray-500">rows across brands</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => handleEdit(template)}
                                                                className="text-primary hover:text-primary-dark"
                                                            >
                                                                <PencilIcon className="h-5 w-5" aria-hidden="true" />
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleDelete(template)}
                                                                className="text-red-600 hover:text-red-700"
                                                            >
                                                                <TrashIcon className="h-5 w-5" aria-hidden="true" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>

                        {/* Deliverable Categories (UI label: Executions) */}
                        <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-4 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Execution Categories</h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    Categories for executions (catalogs, press releases, digital ads, etc.)
                                </p>
                            </div>

                            {localTemplates.filter(t => t.asset_type === 'deliverable').length === 0 ? (
                                <div className="px-6 py-12 text-center">
                                    <p className="text-sm text-gray-500">No execution categories yet.</p>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setData('asset_type', 'deliverable')
                                            handleCreate()
                                        }}
                                        className="mt-4 inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark"
                                    >
                                        <PlusIcon className="-ml-0.5 mr-1.5 h-5 w-5" aria-hidden="true" />
                                        Create Execution Category
                                    </button>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-10">
                                                    <span className="sr-only">Drag handle</span>
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Name
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Slug
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Sort Order
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Flags
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    New brands
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Brand folders
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {localTemplates.filter(t => t.asset_type === 'deliverable').map((template) => (
                                                <tr 
                                                    key={template.id} 
                                                    className="hover:bg-gray-50"
                                                    draggable
                                                    onDragStart={(e) => handleDragStart(e, template)}
                                                    onDragOver={handleDragOver}
                                                    onDragEnter={handleDragEnter}
                                                    onDrop={(e) => handleDrop(e, template, 'deliverable')}
                                                    onDragEnd={handleDragEnd}
                                                >
                                                    <td className="px-6 py-4 whitespace-nowrap cursor-move">
                                                        <Bars3Icon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex items-center gap-3">
                                                            <CategoryIcon 
                                                                iconId={template.icon || 'folder'} 
                                                                className="h-5 w-5 flex-shrink-0" 
                                                                color="text-gray-400"
                                                            />
                                                            <div className="text-sm font-medium text-gray-900">{template.name}</div>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.slug}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.sort_order}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex gap-2">
                                                            {template.is_hidden && (
                                                                <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-700 ring-gray-600/20">
                                                                    Hidden
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        {template.auto_provision ? (
                                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-green-50 text-green-800 ring-green-600/20">
                                                                Auto-add
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-600 ring-gray-600/20">
                                                                Catalog only
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-700 tabular-nums">
                                                            {typeof template.brand_row_count === 'number' ? template.brand_row_count : '—'}
                                                        </div>
                                                        <div className="text-xs text-gray-500">rows across brands</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => handleEdit(template)}
                                                                className="text-primary hover:text-primary-dark"
                                                            >
                                                                <PencilIcon className="h-5 w-5" aria-hidden="true" />
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleDelete(template)}
                                                                className="text-red-600 hover:text-red-700"
                                                            >
                                                                <TrashIcon className="h-5 w-5" aria-hidden="true" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
            
            {/* Delete Confirmation Dialog */}
            <ConfirmDialog
                open={deleteConfirm.open}
                onClose={() => setDeleteConfirm({ open: false, template: null })}
                onConfirm={confirmDelete}
                title="Delete System Category"
                message={
                    deleteConfirm.template
                        ? `Are you sure you want to delete the system category "${deleteConfirm.template.name}"? This will mark the template for deletion and allow tenants to delete their existing categories based on this template. This action cannot be undone.`
                        : ''
                }
                confirmText="Delete"
                cancelText="Cancel"
                variant="danger"
                loading={processing}
            />
        </div>
    )
}
