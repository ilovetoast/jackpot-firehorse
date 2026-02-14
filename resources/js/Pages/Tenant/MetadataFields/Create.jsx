import { useState } from 'react'
import { router, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import { usePermission } from '../../../hooks/usePermission'

export default function CreateTenantMetadataField({ categories = [], preselectedCategoryId = null }) {
    const { auth } = usePage().props
    const { can } = usePermission()
    const canCreate = can('metadata.tenant.field.create')
    const [formData, setFormData] = useState({
        key: '',
        system_label: '',
        type: 'text',
        applies_to: 'all',
        options: [],
        ai_eligible: false,
        is_filterable: true,
        show_on_upload: true,
        show_on_edit: true,
        show_in_filters: true,
        group_key: '',
        category_id: preselectedCategoryId || null,
    })
    const [newOption, setNewOption] = useState({ value: '', label: '' })
    const [errors, setErrors] = useState({})
    const [submitting, setSubmitting] = useState(false)

    if (!canCreate) {
        return (
            <div className="min-h-screen bg-gray-50">
                <AppNav />
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <div className="bg-white rounded-lg shadow p-6">
                        <p className="text-red-600">You do not have permission to create custom metadata fields.</p>
                    </div>
                </div>
                <AppFooter />
            </div>
        )
    }

    const handleSubmit = async (e) => {
        e.preventDefault()
        setSubmitting(true)
        setErrors({})

        // Ensure key starts with custom__ prefix
        const fieldKey = formData.key.startsWith('custom__') 
            ? formData.key 
            : `custom__${formData.key}`

        const submitData = {
            ...formData,
            key: fieldKey,
            // Remove category_id from submission (it's not part of field creation, just for UI)
            category_id: undefined,
        }

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
            const response = await fetch('/app/tenant/metadata/fields', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify(submitData),
            })

            const data = await response.json()

            if (response.ok) {
                // Redirect to metadata registry, optionally with category selected
                router.visit('/app/tenant/metadata/registry#by-category', {
                    onSuccess: () => {
                        // If category was selected, we could scroll to it, but the page will reload with the new field
                    }
                })
            } else {
                setErrors(data.errors || { error: data.error || 'Failed to create field' })
            }
        } catch (error) {
            setErrors({ error: 'An error occurred while creating the field' })
        } finally {
            setSubmitting(false)
        }
    }

    const addOption = () => {
        if (newOption.value && newOption.label) {
            setFormData({
                ...formData,
                options: [...formData.options, { ...newOption }],
            })
            setNewOption({ value: '', label: '' })
        }
    }

    const removeOption = (index) => {
        setFormData({
            ...formData,
            options: formData.options.filter((_, i) => i !== index),
        })
    }

    const requiresOptions = formData.type === 'select' || formData.type === 'multiselect'

    return (
        <div className="min-h-screen bg-gray-50">
            <AppNav />
            <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-200">
                        <h1 className="text-2xl font-bold text-gray-900">Create Custom Metadata Field</h1>
                        <p className="mt-1 text-sm text-gray-600">
                            Create a new metadata field for your tenant. This field will be available across all brands.
                        </p>
                    </div>

                    <form onSubmit={handleSubmit} className="px-6 py-6 space-y-6">
                        {errors.error && (
                            <div className="rounded-md bg-red-50 p-4">
                                <p className="text-sm text-red-800">{errors.error}</p>
                            </div>
                        )}

                        {/* Field Key */}
                        <div>
                            <label htmlFor="key" className="block text-sm font-medium text-gray-700">
                                Field Key <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="key"
                                required
                                value={formData.key}
                                onChange={(e) => setFormData({ ...formData, key: e.target.value })}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                placeholder="my_field"
                            />
                            <p className="mt-1 text-xs text-gray-500">
                                Internal identifier (will be prefixed with "custom__" automatically). Use lowercase with underscores.
                            </p>
                            {errors.key && <p className="mt-1 text-sm text-red-600">{errors.key}</p>}
                        </div>

                        {/* System Label */}
                        <div>
                            <label htmlFor="system_label" className="block text-sm font-medium text-gray-700">
                                Display Name <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="system_label"
                                required
                                value={formData.system_label}
                                onChange={(e) => setFormData({ ...formData, system_label: e.target.value })}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                placeholder="My Custom Field"
                            />
                            {errors.system_label && <p className="mt-1 text-sm text-red-600">{errors.system_label}</p>}
                        </div>

                        {/* Field Type */}
                        <div>
                            <label htmlFor="type" className="block text-sm font-medium text-gray-700">
                                Field Type <span className="text-red-500">*</span>
                            </label>
                            <select
                                id="type"
                                required
                                value={formData.type}
                                onChange={(e) => setFormData({ ...formData, type: e.target.value, options: requiresOptions ? formData.options : [] })}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            >
                                <option value="text">Text</option>
                                <option value="textarea">Textarea</option>
                                <option value="select">Select (Single)</option>
                                <option value="multiselect">Select (Multiple)</option>
                                <option value="number">Number</option>
                                <option value="boolean">Boolean (Yes/No)</option>
                                <option value="date">Date</option>
                            </select>
                            {errors.type && <p className="mt-1 text-sm text-red-600">{errors.type}</p>}
                        </div>

                        {/* Applies To */}
                        <div>
                            <label htmlFor="applies_to" className="block text-sm font-medium text-gray-700">
                                Applies To <span className="text-red-500">*</span>
                            </label>
                            <select
                                id="applies_to"
                                required
                                value={formData.applies_to}
                                onChange={(e) => setFormData({ ...formData, applies_to: e.target.value })}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            >
                                <option value="all">All Asset Types</option>
                                <option value="image">Images Only</option>
                                <option value="video">Videos Only</option>
                                <option value="document">Documents Only</option>
                            </select>
                            {errors.applies_to && <p className="mt-1 text-sm text-red-600">{errors.applies_to}</p>}
                        </div>

                        {/* Options (for select/multiselect) */}
                        {requiresOptions && (
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Options
                                </label>
                                <div className="space-y-2">
                                    {formData.options.map((option, index) => (
                                        <div key={index} className="flex items-center gap-2">
                                            <input
                                                type="text"
                                                value={option.value}
                                                readOnly
                                                className="flex-1 rounded-md border-gray-300 shadow-sm sm:text-sm"
                                            />
                                            <input
                                                type="text"
                                                value={option.label}
                                                readOnly
                                                className="flex-1 rounded-md border-gray-300 shadow-sm sm:text-sm"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => removeOption(index)}
                                                className="text-red-600 hover:text-red-800"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    ))}
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="text"
                                            value={newOption.value}
                                            onChange={(e) => setNewOption({ ...newOption, value: e.target.value })}
                                            placeholder="Value"
                                            className="flex-1 rounded-md border-gray-300 shadow-sm sm:text-sm"
                                        />
                                        <input
                                            type="text"
                                            value={newOption.label}
                                            onChange={(e) => setNewOption({ ...newOption, label: e.target.value })}
                                            placeholder="Label"
                                            className="flex-1 rounded-md border-gray-300 shadow-sm sm:text-sm"
                                        />
                                        <button
                                            type="button"
                                            onClick={addOption}
                                            className="px-3 py-2 text-sm font-medium text-indigo-600 hover:text-indigo-800"
                                        >
                                            Add
                                        </button>
                                    </div>
                                </div>
                                {errors.options && <p className="mt-1 text-sm text-red-600">{errors.options}</p>}
                            </div>
                        )}

                        {/* Category (Pre-selected if coming from By Category view) */}
                        {preselectedCategoryId && (
                            <div>
                                <label className="block text-sm font-medium text-gray-700">
                                    Category
                                </label>
                                <select
                                    value={formData.category_id || ''}
                                    onChange={(e) => setFormData({ ...formData, category_id: e.target.value ? parseInt(e.target.value) : null })}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                >
                                    <option value="">All Categories</option>
                                    {categories.map(category => (
                                        <option key={category.id} value={category.id}>
                                            {category.brand_name} / {category.name}
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-gray-500">
                                    This field will be automatically enabled for the selected category.
                                </p>
                            </div>
                        )}

                        {/* Visibility Options */}
                        <div className="space-y-3">
                            <label className="block text-sm font-medium text-gray-700">Visibility</label>
                            <div className="space-y-2">
                                <label className="flex items-center">
                                    <input
                                        type="checkbox"
                                        checked={formData.show_on_upload}
                                        onChange={(e) => setFormData({ ...formData, show_on_upload: e.target.checked })}
                                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span className="ml-2 text-sm text-gray-700">Show on Upload</span>
                                </label>
                                <label className="flex items-center">
                                    <input
                                        type="checkbox"
                                        checked={formData.show_on_edit}
                                        onChange={(e) => setFormData({ ...formData, show_on_edit: e.target.checked })}
                                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span className="ml-2 text-sm text-gray-700">Quick View</span>
                                </label>
                                <label className="flex items-center">
                                    <input
                                        type="checkbox"
                                        checked={formData.show_in_filters}
                                        onChange={(e) => setFormData({ ...formData, show_in_filters: e.target.checked })}
                                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span className="ml-2 text-sm text-gray-700">Show in Filters</span>
                                </label>
                            </div>
                        </div>

                        {/* AI Eligible (only for select/multiselect with options) */}
                        {requiresOptions && formData.options.length > 0 && (
                            <div>
                                <label className="flex items-center">
                                    <input
                                        type="checkbox"
                                        checked={formData.ai_eligible}
                                        onChange={(e) => setFormData({ ...formData, ai_eligible: e.target.checked })}
                                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span className="ml-2 text-sm text-gray-700">Enable AI Suggestions</span>
                                </label>
                                <p className="mt-1 text-xs text-gray-500">
                                    Allow AI to suggest values for this field based on asset content.
                                </p>
                            </div>
                        )}

                        {/* Actions */}
                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-200">
                            <button
                                type="button"
                                onClick={() => router.visit('/app/tenant/metadata/registry')}
                                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={submitting}
                                className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {submitting ? 'Creating...' : 'Create Field'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <AppFooter />
        </div>
    )
}
