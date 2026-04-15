/**
 * C10: Inline bar above collection grid: collection name + Edit + count + Public toggle.
 * Compact single-row treatment matching the campaign identity banner style.
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
    primaryColor = null,
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
        const onSuccess = () => { setCopied(true); setTimeout(() => setCopied(false), 2000) }
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
        <div className="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-2.5 shadow-sm">
            <h2 className="text-base font-semibold text-gray-900 truncate">{collection.name}</h2>

            {canUpdateCollection && onEditClick && (
                <button
                    type="button"
                    onClick={onEditClick}
                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                    title="Edit collection"
                >
                    <PencilSquareIcon className="h-3.5 w-3.5" aria-hidden="true" />
                    Edit
                </button>
            )}

            {typeof assetCount === 'number' && (
                <span className="text-xs text-gray-500">
                    {assetCount === 0 ? 'No assets' : `${assetCount} asset${assetCount === 1 ? '' : 's'}`}
                </span>
            )}

            {publicCollectionsEnabled && (
                <div className="flex items-center gap-2.5 ml-auto">
                    <div className="flex items-center gap-1.5" title={PUBLIC_TOOLTIP}>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={!!collection.is_public}
                            aria-describedby="public-desc"
                            disabled={updating}
                            onClick={() => handleTogglePublic(!collection.is_public)}
                            className={`relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed ${
                                collection.is_public && !primaryColor ? 'bg-indigo-600' : collection.is_public ? '' : 'bg-gray-200'
                            }`}
                            style={collection.is_public && primaryColor ? { backgroundColor: primaryColor } : undefined}
                        >
                            <span className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${collection.is_public ? 'translate-x-4' : 'translate-x-0'}`} />
                        </button>
                        <span id="public-desc" className="text-xs font-medium text-gray-600" title={PUBLIC_TOOLTIP}>Public</span>
                    </div>
                    {collection.is_public && publicUrl && (
                        <div className="flex items-center gap-1 rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5">
                            <LinkIcon className="h-3.5 w-3.5 text-gray-400 flex-shrink-0" aria-hidden="true" />
                            <span className="text-[11px] text-gray-500 truncate max-w-[160px]" title={publicUrl}>
                                {publicUrl.replace(/^https?:\/\//, '')}
                            </span>
                            <button
                                type="button"
                                onClick={copyPublicLink}
                                className="p-0.5 rounded hover:bg-gray-200 text-gray-400 hover:text-gray-600"
                                title={copied ? 'Copied!' : 'Copy link'}
                            >
                                {copied ? <CheckIcon className="h-3.5 w-3.5 text-green-600" /> : <ClipboardDocumentIcon className="h-3.5 w-3.5" />}
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    )
}
