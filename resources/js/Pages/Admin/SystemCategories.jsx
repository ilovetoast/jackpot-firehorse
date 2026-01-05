import { useState } from 'react'
import { useForm, Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import {
    PlusIcon,
    PencilIcon,
    TrashIcon,
    XMarkIcon,
    CheckIcon,
} from '@heroicons/react/24/outline'

export default function SystemCategories({ templates, asset_types }) {
    const { auth, flash } = usePage().props
    const [showForm, setShowForm] = useState(false)
    const [editingTemplate, setEditingTemplate] = useState(null)
    const [deletingTemplate, setDeletingTemplate] = useState(null)

    const { data, setData, post, put, delete: destroy, processing, errors, reset } = useForm({
        name: '',
        slug: '',
        asset_type: 'basic',
        is_private: false,
        is_hidden: false,
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
            asset_type: template.asset_type,
            is_private: template.is_private,
            is_hidden: template.is_hidden,
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
        if (confirm(`Are you sure you want to delete the system category "${template.name}"? This will only affect new brands created after this change.`)) {
            destroy(`/app/admin/system-categories/${template.id}`, {
                onSuccess: () => {
                    setDeletingTemplate(null)
                },
            })
        }
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
                        <div className="flex items-center justify-between">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">System Categories</h1>
                                <p className="mt-2 text-sm text-gray-700">
                                    Manage global system category templates. These categories are automatically copied to new brands when they are created.
                                </p>
                            </div>
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

                    {/* Success/Error Messages */}
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 p-4">
                            <div className="flex">
                                <CheckIcon className="h-5 w-5 text-green-400" aria-hidden="true" />
                                <p className="ml-3 text-sm font-medium text-green-800">{flash.success}</p>
                            </div>
                        </div>
                    )}

                    {flash?.error && (
                        <div className="mb-4 rounded-md bg-red-50 p-4">
                            <div className="flex">
                                <XMarkIcon className="h-5 w-5 text-red-400" aria-hidden="true" />
                                <p className="ml-3 text-sm font-medium text-red-800">{flash.error}</p>
                            </div>
                        </div>
                    )}

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

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
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

                                    <div className="flex items-center">
                                        <input
                                            id="is_private"
                                            name="is_private"
                                            type="checkbox"
                                            checked={data.is_private}
                                            onChange={(e) => setData('is_private', e.target.checked)}
                                            className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                        />
                                        <label htmlFor="is_private" className="ml-3 block text-sm font-medium text-gray-900">
                                            Is Private
                                        </label>
                                    </div>

                                    <div className="flex items-center">
                                        <input
                                            id="is_hidden"
                                            name="is_hidden"
                                            type="checkbox"
                                            checked={data.is_hidden}
                                            onChange={(e) => setData('is_hidden', e.target.checked)}
                                            className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                        />
                                        <label htmlFor="is_hidden" className="ml-3 block text-sm font-medium text-gray-900">
                                            Is Hidden
                                        </label>
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
                        {/* Basic Asset Categories */}
                        <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-4 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Basic Asset Categories</h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    Categories for basic assets (logos, graphics, photography, etc.)
                                </p>
                            </div>

                            {templates.filter(t => t.asset_type === 'basic').length === 0 ? (
                                <div className="px-6 py-12 text-center">
                                    <p className="text-sm text-gray-500">No basic categories yet.</p>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setData('asset_type', 'basic')
                                            handleCreate()
                                        }}
                                        className="mt-4 inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark"
                                    >
                                        <PlusIcon className="-ml-0.5 mr-1.5 h-5 w-5" aria-hidden="true" />
                                        Create Basic Category
                                    </button>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
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
                                                <th scope="col" className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {templates.filter(t => t.asset_type === 'basic').map((template) => (
                                                <tr key={template.id} className="hover:bg-gray-50">
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm font-medium text-gray-900">{template.name}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.slug}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.sort_order}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex gap-2">
                                                            {template.is_private && (
                                                                <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-yellow-50 text-yellow-700 ring-yellow-600/20">
                                                                    Private
                                                                </span>
                                                            )}
                                                            {template.is_hidden && (
                                                                <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-700 ring-gray-600/20">
                                                                    Hidden
                                                                </span>
                                                            )}
                                                        </div>
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

                        {/* Marketing Asset Categories */}
                        <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-4 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Marketing Asset Categories</h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    Categories for marketing assets (catalogs, press releases, digital ads, etc.)
                                </p>
                            </div>

                            {templates.filter(t => t.asset_type === 'marketing').length === 0 ? (
                                <div className="px-6 py-12 text-center">
                                    <p className="text-sm text-gray-500">No marketing categories yet.</p>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setData('asset_type', 'marketing')
                                            handleCreate()
                                        }}
                                        className="mt-4 inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark"
                                    >
                                        <PlusIcon className="-ml-0.5 mr-1.5 h-5 w-5" aria-hidden="true" />
                                        Create Marketing Category
                                    </button>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
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
                                                <th scope="col" className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {templates.filter(t => t.asset_type === 'marketing').map((template) => (
                                                <tr key={template.id} className="hover:bg-gray-50">
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm font-medium text-gray-900">{template.name}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.slug}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.sort_order}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex gap-2">
                                                            {template.is_private && (
                                                                <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-yellow-50 text-yellow-700 ring-yellow-600/20">
                                                                    Private
                                                                </span>
                                                            )}
                                                            {template.is_hidden && (
                                                                <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-700 ring-gray-600/20">
                                                                    Hidden
                                                                </span>
                                                            )}
                                                        </div>
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
        </div>
    )
}
