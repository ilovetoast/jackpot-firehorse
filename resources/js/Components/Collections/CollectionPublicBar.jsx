/**
 * C10: Bar above collection grid: collection name + Public toggle (when feature enabled).
 * C11: Asset count signal, tooltips (Public meaning / no access grant), copy confirmation polish.
 */
import { useState } from 'react'
import { LinkIcon, ClipboardDocumentIcon, CheckIcon, PencilSquareIcon } from '@heroicons/react/24/outline'

const PUBLIC_TOOLTIP = 'Viewable via a shareable link. Collections do not grant access to assets outside this view.'

export default function CollectionPublicBar({
    collection = null,
    publicCollectionsEnabled = false,
    onPublicChange = null,
    assetCount = null,
    canUpdateCollection = false,
    onEditClick = null,
    primaryColor = null, // Brand primary for toggle when active
}) {
    const [updating, setUpdating] = useState(false)
    const [copied, setCopied] = useState(false)

    if (!collection) return null

    const brandSlug = collection.brand_slug ?? ''
    const publicUrl = collection.slug && brandSlug
        ? `${typeof window !== 'undefined' ? window.location.origin : ''}/b/${brandSlug}/collections/${collection.slug}`
        : null

    const handleTogglePublic = async (checked) => {
        if (!publicCollectionsEnabled || updating || !collection?.id) return
        setUpdating(true)
        try {
            const res = await fetch(`/app/collections/${collection.id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ is_public: !!checked }),
            })
            if (res.ok && onPublicChange) onPublicChange()
        } finally {
            setUpdating(false)
        }
    }

    const copyPublicLink = () => {
        if (!publicUrl) return
        const onSuccess = () => {
            setCopied(true)
            setTimeout(() => setCopied(false), 2000)
        }
        const onFailure = () => {
            try {
                const input = document.createElement('textarea')
                input.value = publicUrl
                input.style.position = 'fixed'
                input.style.opacity = '0'
                document.body.appendChild(input)
                input.select()
                input.setSelectionRange(0, publicUrl.length)
                const ok = document.execCommand('copy')
                document.body.removeChild(input)
                if (ok) onSuccess()
            } catch (e) {
                console.warn('[CollectionPublicBar] Copy failed', e)
            }
        }
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(publicUrl).then(onSuccess).catch(onFailure)
        } else {
            onFailure()
        }
    }

    return (
        <div className="mb-4 flex flex-wrap items-center gap-4 rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
            <h2 className="text-lg font-semibold text-gray-900 truncate">{collection.name}</h2>
            {canUpdateCollection && onEditClick && (
                <button
                    type="button"
                    onClick={onEditClick}
                    className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    title="Edit collection"
                >
                    <PencilSquareIcon className="h-4 w-4" aria-hidden="true" />
                    Edit
                </button>
            )}
            {typeof assetCount === 'number' && (
                <span className="text-sm text-gray-500" aria-label={`${assetCount} assets`}>
                    {assetCount === 0 ? 'No assets' : `${assetCount} asset${assetCount === 1 ? '' : 's'}`}
                </span>
            )}
            {publicCollectionsEnabled && (
                <div className="flex items-center gap-3">
                    <div className="flex items-center gap-2" title={PUBLIC_TOOLTIP}>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={!!collection.is_public}
                            aria-describedby="public-desc"
                            disabled={updating}
                            onClick={() => handleTogglePublic(!collection.is_public)}
                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed ${
                                collection.is_public && !primaryColor ? 'bg-indigo-600' : collection.is_public ? '' : 'bg-gray-200'
                            }`}
                            style={collection.is_public && primaryColor ? { backgroundColor: primaryColor } : undefined}
                        >
                            <span
                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                    collection.is_public ? 'translate-x-5' : 'translate-x-1'
                                }`}
                            />
                        </button>
                        <span id="public-desc" className="text-sm font-medium text-gray-700" title={PUBLIC_TOOLTIP}>
                            Public
                        </span>
                    </div>
                    {collection.is_public && publicUrl && (
                        <div className="flex items-center gap-1 rounded border border-gray-200 bg-gray-50 px-2 py-1">
                            <LinkIcon className="h-4 w-4 text-gray-500 flex-shrink-0" aria-hidden="true" />
                            <span className="text-xs text-gray-600 truncate max-w-[200px] sm:max-w-xs" title={publicUrl}>
                                {publicUrl.replace(/^https?:\/\//, '')}
                            </span>
                            <button
                                type="button"
                                onClick={copyPublicLink}
                                className="p-1 rounded hover:bg-gray-200 text-gray-500 hover:text-gray-700"
                                title={copied ? 'Copied!' : 'Copy link'}
                                aria-label={copied ? 'Link copied' : 'Copy public link'}
                            >
                                {copied ? (
                                    <CheckIcon className="h-4 w-4 text-green-600" aria-hidden="true" />
                                ) : (
                                    <ClipboardDocumentIcon className="h-4 w-4" aria-hidden="true" />
                                )}
                            </button>
                            {copied && (
                                <span className="sr-only" role="status" aria-live="polite">
                                    Link copied to clipboard
                                </span>
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    )
}
