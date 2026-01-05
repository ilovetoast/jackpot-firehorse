import { Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import PlanLimitIndicator from '../../Components/PlanLimitIndicator'

export default function CategoriesIndex({ categories, filters, limits, asset_types }) {
    const { data, setData, post, processing, reset } = useForm({
        name: '',
        slug: '',
        asset_type: asset_types?.[0]?.value || '',
        is_private: false,
    })
    const [showCreateForm, setShowCreateForm] = useState(false)

    const handleFilter = (key, value) => {
        const newFilters = { ...filters, [key]: value }
        router.get('/categories', newFilters, { preserveState: true })
    }

    const handleCreate = (e) => {
        e.preventDefault()
        post('/categories', {
            onSuccess: () => {
                setShowCreateForm(false)
                reset()
            },
        })
    }

    const handleDelete = (categoryId) => {
        if (confirm('Are you sure you want to delete this category?')) {
            router.delete(`/categories/${categoryId}`, {
                preserveScroll: true,
            })
        }
    }

    const formatLimit = (limit) => {
        if (limit === Number.MAX_SAFE_INTEGER || limit === 2147483647) {
            return 'Unlimited'
        }
        return limit
    }

    return (
        <div className="min-h-full bg-gray-50">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8 flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Categories</h1>
                        <p className="mt-2 text-sm text-gray-700">Manage your asset categories</p>
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
                                href="/billing"
                                className="ml-2 font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                Upgrade →
                            </Link>
                        </div>
                    )}
                </div>

                {/* Limit Indicator */}
                <div className="mb-6 overflow-hidden rounded-lg bg-white shadow">
                    <div className="px-4 py-3">
                        <p className="text-sm text-gray-600">
                            Using <span className="font-medium">{limits.current}</span> of{' '}
                            <span className="font-medium">{formatLimit(limits.max)}</span> categories
                        </p>
                    </div>
                </div>

                {/* Filters */}
                <div className="mb-6 overflow-hidden rounded-lg bg-white shadow">
                    <div className="px-4 py-4">
                        <div className="flex gap-4">
                            <div className="flex-1">
                                <label htmlFor="asset_type" className="block text-sm font-medium text-gray-700">
                                    Asset Type
                                </label>
                                <select
                                    id="asset_type"
                                    value={filters.asset_type || ''}
                                    onChange={(e) => handleFilter('asset_type', e.target.value || null)}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                >
                                    <option value="">All Types</option>
                                    {asset_types?.map((type) => (
                                        <option key={type.value} value={type.value}>
                                            {type.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex-1">
                                <label htmlFor="is_system" className="block text-sm font-medium text-gray-700">
                                    Type
                                </label>
                                <select
                                    id="is_system"
                                    value={filters.is_system === null ? '' : filters.is_system ? '1' : '0'}
                                    onChange={(e) =>
                                        handleFilter('is_system', e.target.value === '' ? null : e.target.value === '1')
                                    }
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                >
                                    <option value="">All Categories</option>
                                    <option value="1">System</option>
                                    <option value="0">Custom</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

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
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
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
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    >
                                        {asset_types?.map((type) => (
                                            <option key={type.value} value={type.value}>
                                                {type.label}
                                            </option>
                                        ))}
                                    </select>
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

                {/* Categories List */}
                <div className="overflow-hidden bg-white shadow sm:rounded-md">
                    <ul className="divide-y divide-gray-200">
                        {categories?.length === 0 ? (
                            <li className="px-6 py-4 text-center text-sm text-gray-500">
                                No categories found. Create your first category to get started.
                            </li>
                        ) : (
                            categories?.map((category) => (
                                <li key={category.id} className="px-6 py-4">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center">
                                            <div>
                                                <div className="flex items-center">
                                                    <p className="text-sm font-medium text-gray-900">{category.name}</p>
                                                    {category.is_system && (
                                                        <span className="ml-2 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-800">
                                                            System
                                                        </span>
                                                    )}
                                                    {category.is_private && (
                                                        <span className="ml-2 rounded-full bg-indigo-100 px-2 py-1 text-xs font-medium text-indigo-800">
                                                            Private
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="text-sm text-gray-500">
                                                    {category.asset_type} • {category.slug}
                                                </p>
                                            </div>
                                        </div>
                                        <div>
                                            {!category.is_locked && (
                                                <button
                                                    type="button"
                                                    onClick={() => handleDelete(category.id)}
                                                    disabled={processing}
                                                    className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-red-300 hover:bg-red-50"
                                                >
                                                    Delete
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            ))
                        )}
                    </ul>
                </div>
            </div>
        </div>
    )
}
