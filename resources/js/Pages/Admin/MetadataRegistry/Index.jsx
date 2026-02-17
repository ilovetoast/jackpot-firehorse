import { Link, usePage, router } from '@inertiajs/react'
import { usePermission } from '../../../hooks/usePermission'
import { useState } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import {
    InformationCircleIcon,
    SparklesIcon,
    FunnelIcon,
    LockClosedIcon,
    PencilSquareIcon,
    EyeIcon,
    EyeSlashIcon,
    XMarkIcon,
    AdjustmentsHorizontalIcon,
} from '@heroicons/react/24/outline'

export default function MetadataRegistryIndex({ fields = [] }) {
    const { can } = usePermission()
    const [categoryModalOpen, setCategoryModalOpen] = useState(false)
    const [selectedField, setSelectedField] = useState(null)
    const [categories, setCategories] = useState([])
    const [loadingCategories, setLoadingCategories] = useState(false)
    const [saving, setSaving] = useState(false)

    // Check if user has permission to manage visibility (unified: effective_permissions)
    const canManageVisibility = can('metadata.system.visibility.manage')

    /**
     * Get badge color for population mode
     */
    const getPopulationModeBadge = (mode) => {
        const colors = {
            manual: 'bg-blue-100 text-blue-800',
            automatic: 'bg-green-100 text-green-800',
            hybrid: 'bg-purple-100 text-purple-800',
        }
        return colors[mode] || 'bg-gray-100 text-gray-800'
    }

    /**
     * Get badge for field type
     */
    const getTypeBadge = (type) => {
        return (
            <span className="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10">
                {type}
            </span>
        )
    }

    /**
     * Get applies to badge
     */
    const getAppliesToBadge = (appliesTo) => {
        const colors = {
            all: 'bg-indigo-100 text-indigo-800',
            image: 'bg-pink-100 text-pink-800',
            video: 'bg-red-100 text-red-800',
            document: 'bg-yellow-100 text-yellow-800',
        }
        const color = colors[appliesTo] || 'bg-gray-100 text-gray-800'
        return (
            <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset ring-gray-500/10 ${color}`}>
                {appliesTo}
            </span>
        )
    }

    /**
     * Open category visibility modal for a field
     */
    const openCategoryModal = async (field) => {
        setSelectedField(field)
        setLoadingCategories(true)
        setCategoryModalOpen(true)

        try {
            const response = await fetch(`/app/admin/metadata/fields/${field.id}/categories`)
            const data = await response.json()
            setCategories(data.categories || [])
        } catch (error) {
            console.error('Failed to load categories:', error)
            setCategories([])
        } finally {
            setLoadingCategories(false)
        }
    }

    /**
     * Toggle category suppression
     */
    const toggleCategorySuppression = async (categoryId) => {
        if (!selectedField || saving) return

        const category = categories.find(c => c.id === categoryId)
        if (!category) return

        setSaving(true)

        try {
            if (category.is_suppressed) {
                // Unsuppress
                const response = await fetch(
                    `/app/admin/metadata/fields/${selectedField.id}/categories/${categoryId}/suppress`,
                    { method: 'DELETE' }
                )
                if (response.ok) {
                    setCategories(categories.map(c =>
                        c.id === categoryId ? { ...c, is_suppressed: false, is_visible: true } : c
                    ))
                }
            } else {
                // Suppress
                const response = await fetch(
                    `/app/admin/metadata/fields/${selectedField.id}/categories/${categoryId}/suppress`,
                    { method: 'POST' }
                )
                if (response.ok) {
                    setCategories(categories.map(c =>
                        c.id === categoryId ? { ...c, is_suppressed: true, is_visible: false } : c
                    ))
                }
            }
        } catch (error) {
            console.error('Failed to toggle suppression:', error)
            alert('Failed to update category visibility')
        } finally {
            setSaving(false)
        }
    }

    /**
     * Close category modal
     */
    const closeCategoryModal = () => {
        setCategoryModalOpen(false)
        setSelectedField(null)
        setCategories([])
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
                            ← Back to Admin Dashboard
                        </Link>
                        <div className="flex items-center justify-between">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">
                                    System Metadata Registry
                                </h1>
                                <p className="mt-2 text-sm text-gray-700">
                                    Read-only inspection of all system-provided metadata fields and their behavior
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Info Banner */}
                    <div className="mb-6 rounded-md bg-blue-50 border border-blue-200 p-4">
                        <div className="flex">
                            <InformationCircleIcon className="h-5 w-5 text-blue-600 flex-shrink-0" />
                            <div className="ml-3">
                                <h3 className="text-sm font-medium text-blue-800">
                                    {canManageVisibility ? 'System Metadata Governance' : 'Observability Only'}
                                </h3>
                                <div className="mt-2 text-sm text-blue-700">
                                    <p>
                                        {canManageVisibility
                                            ? 'Inspect system metadata fields and configure category-level visibility suppression.'
                                            : 'This registry provides read-only inspection of system metadata fields. No mutations or configuration changes are available in this view.'}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Summary Stats */}
                    <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <div className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm font-medium text-gray-500">Total Fields</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">{fields.length}</p>
                        </div>
                        <div className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm font-medium text-gray-500">AI-Related</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                {fields.filter(f => f.is_ai_related).length}
                            </p>
                        </div>
                        <div className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm font-medium text-gray-500">System Generated</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                {fields.filter(f => f.is_system_generated).length}
                            </p>
                        </div>
                        <div className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm font-medium text-gray-500">Filter-Only</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                {fields.filter(f => f.is_filter_only).length}
                            </p>
                        </div>
                    </div>

                    {/* Fields Table */}
                    <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-300">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th scope="col" className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-6">
                                            Field
                                        </th>
                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
                                            Type
                                        </th>
                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
                                            Population
                                        </th>
                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
                                            Context
                                        </th>
                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
                                            Flags
                                        </th>
                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
                                            Usage
                                        </th>
                                        {canManageVisibility && (
                                            <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
                                                Actions
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {fields.length === 0 ? (
                                        <tr>
                                            <td colSpan={canManageVisibility ? "7" : "6"} className="px-6 py-8 text-center text-sm text-gray-500">
                                                No system metadata fields found.
                                            </td>
                                        </tr>
                                    ) : (
                                        fields.map((field) => (
                                            <tr key={field.id} className="hover:bg-gray-50">
                                                <td className="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                                                    <div>
                                                        <div className="font-mono text-xs text-gray-500 mb-1">
                                                            {field.key}
                                                        </div>
                                                        <div className="font-medium text-gray-900">
                                                            {field.label}
                                                        </div>
                                                        <div className="mt-1">
                                                            {getAppliesToBadge(field.applies_to)}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                                    {getTypeBadge(field.field_type)}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                    <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${getPopulationModeBadge(field.population_mode)}`}>
                                                        {field.population_mode}
                                                    </span>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                    <div className="flex items-center gap-2">
                                                        {field.show_on_upload ? (
                                                            <span className="inline-flex items-center gap-1 text-green-600" title="Visible on Upload">
                                                                <EyeIcon className="h-4 w-4" />
                                                                <span className="text-xs">Upload</span>
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1 text-gray-400" title="Hidden on Upload">
                                                                <EyeSlashIcon className="h-4 w-4" />
                                                                <span className="text-xs">Upload</span>
                                                            </span>
                                                        )}
                                                        {field.show_on_edit ? (
                                                            <span className="inline-flex items-center gap-1 text-green-600" title="Visible on Edit">
                                                                <PencilSquareIcon className="h-4 w-4" />
                                                                <span className="text-xs">Edit</span>
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1 text-gray-400" title="Hidden on Edit">
                                                                <EyeSlashIcon className="h-4 w-4" />
                                                                <span className="text-xs">Edit</span>
                                                            </span>
                                                        )}
                                                        {field.show_in_filters ? (
                                                            <span className="inline-flex items-center gap-1 text-green-600" title="Visible in Filters">
                                                                <FunnelIcon className="h-4 w-4" />
                                                                <span className="text-xs">Filter</span>
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1 text-gray-400" title="Hidden in Filters">
                                                                <EyeSlashIcon className="h-4 w-4" />
                                                                <span className="text-xs">Filter</span>
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                    <div className="flex flex-wrap gap-1">
                                                        {field.is_ai_related && (
                                                            <span className="inline-flex items-center gap-1 rounded-md bg-purple-100 px-2 py-1 text-xs font-medium text-purple-800 ring-1 ring-inset ring-purple-500/10" title="Has AI-generated candidates">
                                                                <SparklesIcon className="h-3 w-3" />
                                                                AI
                                                            </span>
                                                        )}
                                                        {field.is_filter_only && (
                                                            <span className="inline-flex items-center gap-1 rounded-md bg-orange-100 px-2 py-1 text-xs font-medium text-orange-800 ring-1 ring-inset ring-orange-500/10" title="Filter-only field">
                                                                <FunnelIcon className="h-3 w-3" />
                                                                Filter-Only
                                                            </span>
                                                        )}
                                                        {field.readonly && (
                                                            <span className="inline-flex items-center gap-1 rounded-md bg-red-100 px-2 py-1 text-xs font-medium text-red-800 ring-1 ring-inset ring-red-500/10" title="Read-only field">
                                                                <LockClosedIcon className="h-3 w-3" />
                                                                Read-Only
                                                            </span>
                                                        )}
                                                        {field.supports_override && (
                                                            <span className="inline-flex items-center rounded-md bg-blue-100 px-2 py-1 text-xs font-medium text-blue-800 ring-1 ring-inset ring-blue-500/10" title="Supports user override">
                                                                Override
                                                            </span>
                                                        )}
                                                        {field.is_internal_only && (
                                                            <span className="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-500/10" title="Internal-only field">
                                                                Internal
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                                    <div className="space-y-1">
                                                        <div>
                                                            <span className="font-medium text-gray-900">{field.total_assets_with_value.toLocaleString()}</span>
                                                            <span className="text-xs text-gray-500"> assets</span>
                                                        </div>
                                                        <div className="text-xs">
                                                            <span className="text-gray-600">{field.percent_populated}%</span>
                                                            <span className="text-gray-500"> populated</span>
                                                        </div>
                                                        {field.supports_override && (
                                                            <div className="text-xs">
                                                                <span className="text-gray-600">{field.percent_user_override}%</span>
                                                                <span className="text-gray-500"> overridden</span>
                                                            </div>
                                                        )}
                                                        {field.pending_review_count > 0 && (
                                                            <div className="text-xs text-orange-600">
                                                                {field.pending_review_count} pending review
                                                            </div>
                                                        )}
                                                    </div>
                                                </td>
                                                {canManageVisibility && (
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                        <button
                                                            onClick={() => openCategoryModal(field)}
                                                            className="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                                                            title="Manage category visibility"
                                                        >
                                                            <AdjustmentsHorizontalIcon className="h-3 w-3" />
                                                            Categories
                                                        </button>
                                                    </td>
                                                )}
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Category Visibility Modal */}
                    {categoryModalOpen && selectedField && (
                        <>
                            <div
                                className="fixed inset-0 z-50 bg-gray-500 bg-opacity-75 transition-opacity"
                                onClick={closeCategoryModal}
                            />
                            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                                <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                                    <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                                        <button
                                            type="button"
                                            className="rounded-md bg-white text-gray-400 hover:text-gray-500"
                                            onClick={closeCategoryModal}
                                        >
                                            <span className="sr-only">Close</span>
                                            <XMarkIcon className="h-6 w-6" />
                                        </button>
                                    </div>
                                    <div className="sm:flex sm:items-start">
                                        <div className="mt-3 text-center sm:mt-0 sm:text-left w-full">
                                            <h3 className="text-base font-semibold leading-6 text-gray-900 mb-2">
                                                Category Visibility: {selectedField.label}
                                            </h3>
                                            <p className="text-sm text-gray-500 mb-4">
                                                Configure which system categories this field is visible for. Suppressing a field hides it in upload, edit, and filter UIs for that category.
                                            </p>
                                            
                                            {/* Warning */}
                                            <div className="mb-4 rounded-md bg-yellow-50 border border-yellow-200 p-3">
                                                <div className="flex">
                                                    <InformationCircleIcon className="h-5 w-5 text-yellow-600 flex-shrink-0" />
                                                    <div className="ml-3">
                                                        <p className="text-sm text-yellow-800">
                                                            <strong>Note:</strong> Suppressing a field hides it from UI, but does not delete existing data. Existing metadata values remain in the database.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Categories List */}
                                            <div className="max-h-96 overflow-y-auto border border-gray-200 rounded-md">
                                                {loadingCategories ? (
                                                    <div className="p-4 text-center text-sm text-gray-500">
                                                        Loading categories...
                                                    </div>
                                                ) : categories.length === 0 ? (
                                                    <div className="p-4 text-center text-sm text-gray-500">
                                                        No categories found.
                                                    </div>
                                                ) : (
                                                    <table className="min-w-full divide-y divide-gray-200">
                                                        <thead className="bg-gray-50">
                                                            <tr>
                                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                                    Category
                                                                </th>
                                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                                    Asset Type
                                                                </th>
                                                                <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                                                    Status
                                                                </th>
                                                                <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                                                    Action
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y divide-gray-200 bg-white">
                                                            {categories.map((category) => (
                                                                <tr key={category.id}>
                                                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900">
                                                                        {category.name}
                                                                    </td>
                                                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-500">
                                                                        {category.asset_type}
                                                                    </td>
                                                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-center">
                                                                        {category.is_suppressed ? (
                                                                            <span className="inline-flex items-center rounded-md bg-red-100 px-2 py-1 text-xs font-medium text-red-800">
                                                                                Suppressed
                                                                            </span>
                                                                        ) : (
                                                                            <span className="inline-flex items-center rounded-md bg-green-100 px-2 py-1 text-xs font-medium text-green-800">
                                                                                Visible
                                                                            </span>
                                                                        )}
                                                                    </td>
                                                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-center">
                                                                        <button
                                                                            onClick={() => toggleCategorySuppression(category.id)}
                                                                            disabled={saving}
                                                                            className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${
                                                                                category.is_suppressed
                                                                                    ? 'bg-green-50 text-green-700 hover:bg-green-100'
                                                                                    : 'bg-red-50 text-red-700 hover:bg-red-100'
                                                                            } disabled:opacity-50`}
                                                                        >
                                                                            {category.is_suppressed ? 'Unsuppress' : 'Suppress'}
                                                                        </button>
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                )}
                                            </div>

                                            <div className="mt-4 flex justify-end">
                                                <button
                                                    type="button"
                                                    onClick={closeCategoryModal}
                                                    className="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                >
                                                    Close
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
