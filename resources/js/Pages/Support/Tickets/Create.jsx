import { useForm, Link, usePage } from '@inertiajs/react'
import { useState, useEffect, useRef, useMemo } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'

export default function TicketsCreate({ 
    brands = [], 
    plan_limits = { max_attachment_size: '10 MB', max_attachments: 5, can_attach_files: true }, 
    sla_message = null, 
    categories = [] 
}) {
    const { auth, old } = usePage().props
    
    if (!auth) {
        return <div>Loading...</div>
    }

    // Initialize useForm with old values if they exist (from validation errors)
    // Use useMemo to ensure we always use the latest old values
    const initialFormData = useMemo(() => ({
        category: old?.category || '',
        brand_ids: old?.brand_ids ? (Array.isArray(old.brand_ids) ? old.brand_ids : [old.brand_ids]) : [],
        subject: old?.subject || '',
        description: old?.description || '',
        attachments: [],
    }), [old])

    const { data, setData, post, processing, errors } = useForm(initialFormData)

    const [attachmentFiles, setAttachmentFiles] = useState([])
    const hasSyncedOldRef = useRef(false)

    // Sync form data with old input when validation errors occur
    // This ensures form data is preserved after validation errors
    // Use a ref to track if we've synced to avoid infinite loops
    useEffect(() => {
        // Only sync once when old becomes available
        if (old && !hasSyncedOldRef.current) {
            hasSyncedOldRef.current = true
            
            // Update all fields from old input
            if (old.category !== undefined) {
                setData('category', old.category)
            }
            if (old.brand_ids !== undefined) {
                const brandIds = Array.isArray(old.brand_ids) ? old.brand_ids : [old.brand_ids]
                setData('brand_ids', brandIds)
            }
            if (old.subject !== undefined) {
                setData('subject', old.subject)
            }
            if (old.description !== undefined) {
                setData('description', old.description)
            }
        }
        
        // Reset sync flag when old becomes unavailable (new form submission)
        if (!old) {
            hasSyncedOldRef.current = false
        }
    }, [old, setData])

    const handleSubmit = (e) => {
        e.preventDefault()
        
        // Set attachments in form data
        setData('attachments', attachmentFiles)

        post('/app/support/tickets', {
            forceFormData: true,
            preserveScroll: true,
        })
    }

    const handleBrandToggle = (brandId) => {
        const newBrandIds = data.brand_ids.includes(brandId)
            ? data.brand_ids.filter((id) => id !== brandId)
            : [...data.brand_ids, brandId]
        setData('brand_ids', newBrandIds)
    }

    const handleFileChange = (e) => {
        const files = Array.from(e.target.files)
        setAttachmentFiles(files)
    }

    return (
        <div className="min-h-full bg-gray-50">
            <AppNav brand={auth.activeBrand} tenant={auth.tenant} />
            <main className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <Link
                        href="/app/support/tickets"
                        className="text-sm text-gray-500 hover:text-gray-700 mb-4 inline-block"
                    >
                        ← Back to tickets
                    </Link>
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900">Create Support Ticket</h1>
                    <p className="mt-2 text-sm text-gray-700">
                        We're here to help. Please provide as much detail as possible about your issue.
                    </p>
                </div>

                {/* SLA Message */}
                {sla_message && (
                    <div className="mb-6 rounded-md bg-blue-50 p-4 border border-blue-200">
                        <div className="flex">
                            <div className="flex-shrink-0">
                                <svg className="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path
                                        fillRule="evenodd"
                                        d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                                        clipRule="evenodd"
                                    />
                                </svg>
                            </div>
                            <div className="ml-3">
                                <p className="text-sm text-blue-700">
                                    <strong>{sla_message}</strong> - Our team will prioritize your request based on operational targets.
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                <form onSubmit={handleSubmit} className="bg-white shadow-sm ring-1 ring-gray-200 rounded-lg overflow-hidden">
                    <div className="px-6 py-6 space-y-6">
                        {/* Category */}
                        <div>
                            <label htmlFor="category" className="block text-sm font-medium leading-6 text-gray-900">
                                Category <span className="text-red-500">*</span>
                            </label>
                            <select
                                id="category"
                                value={data.category}
                                onChange={(e) => setData('category', e.target.value)}
                                className="mt-2 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6"
                            >
                                <option value="">Select a category</option>
                                {categories.map((cat) => (
                                    <option key={cat.value} value={cat.value}>
                                        {cat.label}
                                    </option>
                                ))}
                            </select>
                            {errors.category && <p className="mt-1 text-sm text-red-600">{errors.category}</p>}
                        </div>

                        {/* Brands */}
                        <div>
                            <label className="block text-sm font-medium leading-6 text-gray-900 mb-2">
                                Associated Brands <span className="text-red-500">*</span>
                            </label>
                            <div className="space-y-2">
                                {brands.map((brand) => (
                                    <label key={brand.id} className="flex items-center">
                                        <input
                                            type="checkbox"
                                            checked={data.brand_ids.includes(brand.id)}
                                            onChange={() => handleBrandToggle(brand.id)}
                                            className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                        />
                                        <span className="ml-2 text-sm text-gray-900">{brand.name}</span>
                                    </label>
                                ))}
                            </div>
                            {errors.brand_ids && <p className="mt-1 text-sm text-red-600">{errors.brand_ids}</p>}
                        </div>

                        {/* Subject */}
                        <div>
                            <label htmlFor="subject" className="block text-sm font-medium leading-6 text-gray-900">
                                Subject <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="subject"
                                value={data.subject}
                                onChange={(e) => setData('subject', e.target.value)}
                                className="mt-2 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                placeholder="Brief description of your issue"
                            />
                            {errors.subject && <p className="mt-1 text-sm text-red-600">{errors.subject}</p>}
                        </div>

                        {/* Description */}
                        <div>
                            <label htmlFor="description" className="block text-sm font-medium leading-6 text-gray-900">
                                Description <span className="text-red-500">*</span>
                            </label>
                            <textarea
                                id="description"
                                rows={8}
                                maxLength={250}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                className="mt-2 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                placeholder="Please provide as much detail as possible about your issue..."
                            />
                            <div className="mt-1 flex justify-between items-center">
                                {errors.description ? (
                                    <p className="text-sm text-red-600">{errors.description}</p>
                                ) : (
                                    <div></div>
                                )}
                                <p className={`text-xs ${
                                    data.description.length > 237 
                                        ? 'text-red-600 font-semibold' 
                                        : data.description.length > 200 
                                        ? 'text-orange-600' 
                                        : 'text-gray-500'
                                }`}>
                                    {data.description.length.toLocaleString()} / 250 characters
                                </p>
                            </div>
                        </div>

                        {/* Attachments */}
                        {plan_limits.can_attach_files && (
                            <div>
                                <label htmlFor="attachments" className="block text-sm font-medium leading-6 text-gray-900">
                                    Attachments (Optional)
                                </label>
                                <input
                                    type="file"
                                    id="attachments"
                                    multiple
                                    onChange={handleFileChange}
                                    className="mt-2 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                                />
                                <p className="mt-1 text-xs text-gray-500">
                                    Maximum {plan_limits.max_attachment_size} per file, up to {plan_limits.max_attachments} files
                                </p>
                                {errors.attachments && <p className="mt-1 text-sm text-red-600">{errors.attachments}</p>}
                            </div>
                        )}
                    </div>

                    <div className="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-end gap-3">
                        <Link
                            href="/app/support/tickets"
                            className="text-sm font-medium text-gray-700 hover:text-gray-900"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                        >
                            {processing ? 'Creating...' : 'Create Ticket'}
                        </button>
                    </div>
                </form>
            </main>
            <AppFooter />
        </div>
    )
}
