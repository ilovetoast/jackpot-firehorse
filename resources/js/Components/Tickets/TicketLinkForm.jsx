import { useForm } from '@inertiajs/react'
import { useState } from 'react'

export default function TicketLinkForm({ ticketId }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        linkable_type: '',
        linkable_id: '',
        link_type: 'event',
        designation: 'related',
        metadata: '',
    })

    const handleSubmit = (e) => {
        e.preventDefault()
        
        // Parse metadata if provided
        let metadataObj = null
        if (data.metadata && data.metadata.trim()) {
            try {
                metadataObj = JSON.parse(data.metadata)
            } catch (err) {
                // If invalid JSON, send as null
                console.error('Invalid JSON in metadata:', err)
            }
        }
        
        // Update form data with parsed metadata
        const formData = { ...data }
        if (metadataObj !== null) {
            formData.metadata = metadataObj
        } else if (!data.metadata || !data.metadata.trim()) {
            formData.metadata = null
        }
        
        setData(formData)
        
        post(`/app/admin/support/tickets/${ticketId}/link`, {
            preserveScroll: true,
            onSuccess: () => {
                reset()
            },
        })
    }

    return (
        <div className="border border-gray-200 rounded-lg p-4 bg-gray-50">
            <h4 className="text-sm font-semibold text-gray-900 mb-3">Link Ticket</h4>
            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label htmlFor="link_type" className="block text-sm font-medium text-gray-700 mb-1">
                        Link Type <span className="text-red-500">*</span>
                    </label>
                    <select
                        id="link_type"
                        value={data.link_type}
                        onChange={(e) => setData('link_type', e.target.value)}
                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        required
                    >
                        <option value="event">Event</option>
                        <option value="error_log">Error Log</option>
                        <option value="ticket">Ticket</option>
                        <option value="frontend_error">Frontend Error</option>
                        <option value="job_failure">Job Failure</option>
                    </select>
                </div>

                <div>
                    <label htmlFor="linkable_type" className="block text-sm font-medium text-gray-700 mb-1">
                        Model Type <span className="text-red-500">*</span>
                    </label>
                    <input
                        type="text"
                        id="linkable_type"
                        value={data.linkable_type}
                        onChange={(e) => setData('linkable_type', e.target.value)}
                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        placeholder="e.g., App\Models\ActivityEvent"
                        required
                    />
                    {errors.linkable_type && <p className="mt-1 text-sm text-red-600">{errors.linkable_type}</p>}
                </div>

                <div>
                    <label htmlFor="linkable_id" className="block text-sm font-medium text-gray-700 mb-1">
                        Model ID <span className="text-red-500">*</span>
                    </label>
                    <input
                        type="number"
                        id="linkable_id"
                        value={data.linkable_id}
                        onChange={(e) => setData('linkable_id', e.target.value)}
                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        placeholder="123"
                        required
                    />
                    {errors.linkable_id && <p className="mt-1 text-sm text-red-600">{errors.linkable_id}</p>}
                </div>

                <div>
                    <label htmlFor="designation" className="block text-sm font-medium text-gray-700 mb-1">
                        Designation
                    </label>
                    <select
                        id="designation"
                        value={data.designation}
                        onChange={(e) => setData('designation', e.target.value)}
                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    >
                        <option value="primary">Primary</option>
                        <option value="related">Related</option>
                        <option value="duplicate">Duplicate</option>
                    </select>
                    {errors.designation && <p className="mt-1 text-sm text-red-600">{errors.designation}</p>}
                </div>

                <div>
                    <label htmlFor="metadata" className="block text-sm font-medium text-gray-700 mb-1">
                        Metadata (Optional JSON)
                    </label>
                    <textarea
                        id="metadata"
                        rows={3}
                        value={data.metadata}
                        onChange={(e) => setData('metadata', e.target.value)}
                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono text-xs"
                        placeholder='{"key": "value"}'
                    />
                    {errors.metadata && <p className="mt-1 text-sm text-red-600">{errors.metadata}</p>}
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                >
                    {processing ? 'Linking...' : 'Link Ticket'}
                </button>
            </form>
        </div>
    )
}
