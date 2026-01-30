/**
 * C11.1: Minimal edit surface for collection (name, description, public toggle).
 * Uses existing PUT /app/collections/{collection}. Policy-guarded; Save/Cancel only.
 */
import { useState, useEffect } from 'react'
import { XMarkIcon } from '@heroicons/react/24/outline'

const PUBLIC_TOOLTIP = 'Viewable via a shareable link. Collections do not grant access to assets outside this view.'

export default function EditCollectionModal({
    open,
    collection = null,
    publicCollectionsEnabled = false,
    onClose,
    onSaved,
}) {
    const [name, setName] = useState('')
    const [description, setDescription] = useState('')
    const [visibility, setVisibility] = useState('brand')
    const [isPublic, setIsPublic] = useState(false)
    const [submitting, setSubmitting] = useState(false)
    const [error, setError] = useState(null)

    useEffect(() => {
        if (open && collection) {
            setName(collection.name ?? '')
            setDescription(collection.description ?? '')
            setVisibility(collection.visibility ?? 'brand')
            setIsPublic(!!collection.is_public)
            setError(null)
        }
    }, [open, collection])

    const handleSubmit = async (e) => {
        e.preventDefault()
        setError(null)
        if (!collection?.id) return
        const trimmedName = name.trim()
        if (!trimmedName) {
            setError('Name is required.')
            return
        }
        setSubmitting(true)
        try {
            const body = {
                name: trimmedName,
                description: description.trim() || null,
                visibility: visibility || 'brand',
            }
            if (publicCollectionsEnabled) {
                body.is_public = isPublic
            }
            const response = await fetch(`/app/collections/${collection.id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            })
            const data = await response.json().catch(() => ({}))
            if (response.ok && data?.collection) {
                onSaved?.(data.collection)
                onClose()
                return
            }
            const msg = data?.errors?.name?.[0] ?? data?.message ?? 'Failed to update collection.'
            setError(msg)
        } catch (err) {
            setError(err?.message ?? 'Failed to update collection.')
        } finally {
            setSubmitting(false)
        }
    }

    const handleClose = () => {
        if (!submitting) {
            setError(null)
            onClose()
        }
    }

    if (!open) return null

    return (
        <div className="fixed inset-0 z-[80] overflow-y-auto" aria-labelledby="edit-modal-title" role="dialog" aria-modal="true">
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div className="fixed inset-0 bg-gray-500/75 transition-opacity z-[80]" aria-hidden="true" onClick={handleClose} />
                <div className="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg z-[81]">
                    <div className="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-lg font-semibold text-gray-900" id="edit-modal-title">Edit collection</h3>
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
                                    <label htmlFor="edit-collection-name" className="block text-sm font-medium text-gray-700">
                                        Name <span className="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="edit-collection-name"
                                        type="text"
                                        required
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm"
                                        placeholder="Collection name"
                                        disabled={submitting}
                                    />
                                </div>
                                <div>
                                    <label htmlFor="edit-collection-description" className="block text-sm font-medium text-gray-700">
                                        Description <span className="text-gray-400">(optional)</span>
                                    </label>
                                    <textarea
                                        id="edit-collection-description"
                                        rows={3}
                                        value={description}
                                        onChange={(e) => setDescription(e.target.value)}
                                        className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:text-sm"
                                        placeholder="Brief description"
                                        disabled={submitting}
                                    />
                                </div>
                                <div>
                                    <label htmlFor="edit-collection-visibility" className="block text-sm font-medium text-gray-700">
                                        Visibility
                                    </label>
                                    <select
                                        id="edit-collection-visibility"
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
                                {publicCollectionsEnabled && (
                                    <div className="flex items-center gap-2" title={PUBLIC_TOOLTIP}>
                                        <button
                                            type="button"
                                            role="switch"
                                            aria-checked={isPublic}
                                            disabled={submitting}
                                            onClick={() => setIsPublic((v) => !v)}
                                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed ${
                                                isPublic ? 'bg-indigo-600' : 'bg-gray-200'
                                            }`}
                                        >
                                            <span
                                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                                    isPublic ? 'translate-x-5' : 'translate-x-1'
                                                }`}
                                            />
                                        </button>
                                        <span className="text-sm font-medium text-gray-700" title={PUBLIC_TOOLTIP}>
                                            Public
                                        </span>
                                    </div>
                                )}
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
                                    {submitting ? 'Saving…' : 'Save'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    )
}
