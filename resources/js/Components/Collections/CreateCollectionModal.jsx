/**
 * Create Collection Modal (C5/C7 scaffold).
 * Fields: name (required), description (optional), visibility (brand/restricted/private).
 * is_public not exposed. Submit → POST /app/collections.
 */
import { useState } from 'react'
import { XMarkIcon } from '@heroicons/react/24/outline'

export default function CreateCollectionModal({ open, onClose, onCreated }) {
    const [name, setName] = useState('')
    const [description, setDescription] = useState('')
    const [visibility, setVisibility] = useState('brand')
    const [submitting, setSubmitting] = useState(false)
    const [error, setError] = useState(null)

    const handleSubmit = async (e) => {
        e.preventDefault()
        setError(null)
        if (!name.trim()) {
            setError('Name is required.')
            return
        }
        setSubmitting(true)
        try {
            const response = await window.axios.post('/app/collections', {
                name: name.trim(),
                description: description.trim() || null,
                visibility: visibility || 'brand',
            }, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            const data = response.data
            if (data?.collection?.id) {
                onCreated?.(data.collection)
                setName('')
                setDescription('')
                setVisibility('brand')
                onClose()
            }
        } catch (err) {
            const msg = err.response?.data?.errors?.name?.[0]
                ?? err.response?.data?.message
                ?? 'Failed to create collection.'
            setError(msg)
        } finally {
            setSubmitting(false)
        }
    }

    const handleClose = () => {
        if (!submitting) {
            setError(null)
            setName('')
            setDescription('')
            setVisibility('brand')
            onClose()
        }
    }

    if (!open) return null

    return (
        <div className="fixed inset-0 z-[100] overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div className="fixed inset-0 bg-gray-500/75 transition-opacity" aria-hidden="true" onClick={handleClose} />
                <div className="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                    <div className="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-lg font-semibold text-gray-900" id="modal-title">Create collection</h3>
                            <button
                                type="button"
                                onClick={handleClose}
                                disabled={submitting}
                                className="rounded-md text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <form onSubmit={handleSubmit}>
                            {error && (
                                <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                                    {error}
                                </div>
                            )}
                            <div className="space-y-4">
                                <div>
                                    <label htmlFor="collection-name" className="block text-sm font-medium text-gray-700">
                                        Name <span className="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="collection-name"
                                        type="text"
                                        required
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm"
                                        placeholder="e.g. Campaign assets"
                                        autoFocus
                                        disabled={submitting}
                                    />
                                </div>
                                <div>
                                    <label htmlFor="collection-description" className="block text-sm font-medium text-gray-700">
                                        Description <span className="text-gray-400">(optional)</span>
                                    </label>
                                    <textarea
                                        id="collection-description"
                                        rows={3}
                                        value={description}
                                        onChange={(e) => setDescription(e.target.value)}
                                        className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm"
                                        placeholder="Brief description of this collection"
                                        disabled={submitting}
                                    />
                                </div>
                                <div>
                                    <label htmlFor="collection-visibility" className="block text-sm font-medium text-gray-700">
                                        Visibility
                                    </label>
                                    <select
                                        id="collection-visibility"
                                        value={visibility}
                                        onChange={(e) => setVisibility(e.target.value)}
                                        disabled={submitting}
                                        className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm"
                                    >
                                        <option value="brand">Brand — anyone in the brand can view</option>
                                        <option value="restricted">Restricted — only invited members</option>
                                        <option value="private">Private — only you and invited members</option>
                                    </select>
                                </div>
                            </div>
                            <div className="mt-6 flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={handleClose}
                                    disabled={submitting}
                                    className="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting || !name.trim()}
                                    className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                >
                                    {submitting ? 'Creating…' : 'Create'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    )
}
