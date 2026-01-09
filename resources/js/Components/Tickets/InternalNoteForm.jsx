import { useForm } from '@inertiajs/react'
import { useState } from 'react'

export default function InternalNoteForm({ ticketId }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        body: '',
        attachments: [],
    })

    const [attachmentFiles, setAttachmentFiles] = useState([])

    const handleSubmit = (e) => {
        e.preventDefault()
        setData('attachments', attachmentFiles)
        post(`/app/admin/support/tickets/${ticketId}/internal-note`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset()
                setAttachmentFiles([])
            },
        })
    }

    const handleFileChange = (e) => {
        const files = Array.from(e.target.files)
        setAttachmentFiles(files)
    }

    return (
        <div className="border border-orange-200 rounded-lg p-4 bg-orange-50">
            <h4 className="text-sm font-semibold text-gray-900 mb-3">Add Internal Note</h4>
            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label htmlFor="body" className="block text-sm font-medium text-gray-700 mb-1">
                        Note <span className="text-red-500">*</span>
                    </label>
                    <textarea
                        id="body"
                        rows={4}
                        maxLength={10000}
                        value={data.body}
                        onChange={(e) => setData('body', e.target.value)}
                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        placeholder="Add an internal note (not visible to tenant)..."
                    />
                    <div className="mt-1 flex justify-between items-center">
                        {errors.body ? (
                            <p className="text-sm text-red-600">{errors.body}</p>
                        ) : (
                            <div></div>
                        )}
                        <p className={`text-xs ${
                            data.body.length > 9500 
                                ? 'text-red-600 font-semibold' 
                                : data.body.length > 8000 
                                ? 'text-orange-600' 
                                : 'text-gray-500'
                        }`}>
                            {data.body.length.toLocaleString()} / 10,000 characters
                        </p>
                    </div>
                </div>

                <div>
                    <label htmlFor="attachments" className="block text-sm font-medium text-gray-700 mb-1">
                        Attachments (Optional)
                    </label>
                    <input
                        type="file"
                        id="attachments"
                        multiple
                        onChange={handleFileChange}
                        className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                    />
                    <p className="mt-1 text-xs text-gray-500">
                        Maximum 5MB per file, up to 3 files
                    </p>
                    {errors.attachments && <p className="mt-1 text-sm text-red-600">{errors.attachments}</p>}
                </div>

                <button
                    type="submit"
                    disabled={processing || !data.body.trim()}
                    className="inline-flex items-center rounded-md bg-orange-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-500 disabled:opacity-50"
                >
                    {processing ? 'Adding...' : 'Add Internal Note'}
                </button>
            </form>
        </div>
    )
}
