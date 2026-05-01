/**
 * Collection header bar: name, Edit, asset count, share link status + copy (when configured).
 */
import { useState } from 'react'
import { LinkIcon, ClipboardDocumentIcon, CheckIcon, PencilSquareIcon, LockClosedIcon } from '@heroicons/react/24/outline'

const SHARE_TOOLTIP = 'Password-protected link for clients and partners. Does not grant access to your library or other brands.'

export default function CollectionPublicBar({
    collection = null,
    publicCollectionsEnabled = false,
    billingUpgradeUrl = '/app/billing',
    onPublicChange = null,
    onShareLinkConfigure = null,
    onPlanUpgradeRequest = null,
    assetCount = null,
    canUpdateCollection = false,
    onEditClick = null,
    primaryColor = null,
}) {
    const [updating, setUpdating] = useState(false)
    const [copied, setCopied] = useState(false)

    if (!collection) return null

    const shareUrl = collection.public_share_url || null
    const shared = !!collection.is_public
    const needsSetup = !!collection.needs_share_password_setup

    const putIsPublic = async (checked) => {
        if (!collection?.id) return
        setUpdating(true)
        try {
            const res = await fetch(`/app/collections/${collection.id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
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

    const handleSwitchClick = () => {
        if (updating) return

        if (!publicCollectionsEnabled) {
            onPlanUpgradeRequest?.()
            if (billingUpgradeUrl) {
                window.location.assign(billingUpgradeUrl)
            }
            return
        }

        if (needsSetup || !shared) {
            onShareLinkConfigure?.()
            return
        }

        if (
            !window.confirm(
                'Turn off the share link? People with the link will no longer be able to open this collection until you turn it on again and set a password.'
            )
        ) {
            return
        }
        putIsPublic(false)
    }

    const copyShareUrl = () => {
        if (!shareUrl) return
        const onSuccess = () => {
            setCopied(true)
            setTimeout(() => setCopied(false), 2000)
        }
        const onFailure = () => {
            try {
                const input = document.createElement('textarea')
                input.value = shareUrl
                input.style.position = 'fixed'
                input.style.opacity = '0'
                document.body.appendChild(input)
                input.select()
                input.setSelectionRange(0, shareUrl.length)
                const ok = document.execCommand('copy')
                document.body.removeChild(input)
                if (ok) onSuccess()
            } catch (e) {
                console.warn('[CollectionPublicBar] Copy failed', e)
            }
        }
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(shareUrl).then(onSuccess).catch(onFailure)
        } else {
            onFailure()
        }
    }

    const switchAriaChecked = publicCollectionsEnabled && shared && !needsSetup

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

            <div className="flex items-center gap-2.5 ml-auto" title={SHARE_TOOLTIP}>
                <div className="flex items-center gap-1.5">
                    <button
                        type="button"
                        role="switch"
                        aria-checked={switchAriaChecked}
                        aria-describedby="share-desc"
                        disabled={updating}
                        onClick={handleSwitchClick}
                        className={`relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed ${
                            switchAriaChecked && !primaryColor ? 'bg-indigo-600' : switchAriaChecked ? '' : 'bg-gray-200'
                        }`}
                        style={switchAriaChecked && primaryColor ? { backgroundColor: primaryColor } : undefined}
                    >
                        <span
                            className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${switchAriaChecked ? 'translate-x-4' : 'translate-x-0'}`}
                        />
                    </button>
                    <span id="share-desc" className="text-xs font-medium text-gray-600">
                        {!publicCollectionsEnabled
                            ? 'Share link'
                            : needsSetup
                              ? 'Password setup required'
                              : shared
                                ? 'Shared'
                                : 'Share link'}
                    </span>
                    {shared && !needsSetup ? (
                        <LockClosedIcon className="h-3.5 w-3.5 text-gray-400" title="Password-protected" aria-hidden="true" />
                    ) : null}
                </div>
                {publicCollectionsEnabled && shared && shareUrl && !needsSetup ? (
                    <div className="flex items-center gap-1 rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5">
                        <LinkIcon className="h-3.5 w-3.5 text-gray-400 flex-shrink-0" aria-hidden="true" />
                        <span className="text-[11px] text-gray-500 truncate max-w-[160px]" title={shareUrl}>
                            {shareUrl.replace(/^https?:\/\//, '')}
                        </span>
                        <button
                            type="button"
                            onClick={copyShareUrl}
                            className="p-0.5 rounded hover:bg-gray-200 text-gray-400 hover:text-gray-600"
                            title={copied ? 'Copied!' : 'Copy link'}
                        >
                            {copied ? <CheckIcon className="h-3.5 w-3.5 text-green-600" /> : <ClipboardDocumentIcon className="h-3.5 w-3.5" />}
                        </button>
                    </div>
                ) : null}
            </div>
        </div>
    )
}
