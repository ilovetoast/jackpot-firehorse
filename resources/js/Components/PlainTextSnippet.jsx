import { useEffect, useState } from 'react'
import { isPlaintextRegistryAsset } from '../utils/isPlaintextAsset'

/**
 * Fetches the first chunk of a .txt / .csv asset (authenticated JSON) and renders it in a scrollable <pre>.
 */
export default function PlainTextSnippet({ asset, className = '' }) {
    const [content, setContent] = useState(null)
    const [truncated, setTruncated] = useState(false)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)

    useEffect(() => {
        if (!isPlaintextRegistryAsset(asset)) {
            setContent(null)
            setError(null)
            return
        }
        let cancelled = false
        setLoading(true)
        setError(null)
        const url =
            typeof route === 'function'
                ? route('assets.text-snippet', { asset: asset.id })
                : `/app/assets/${asset.id}/text-snippet`
        fetch(url, {
            method: 'GET',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`)
                }
                return res.json()
            })
            .then((data) => {
                if (cancelled) return
                setContent(typeof data.content === 'string' ? data.content : '')
                setTruncated(Boolean(data.truncated))
                setLoading(false)
            })
            .catch((e) => {
                if (cancelled) return
                setError(e?.message || 'Failed to load text')
                setLoading(false)
            })
        return () => {
            cancelled = true
        }
    }, [asset?.id, asset?.mime_type, asset?.original_filename])

    if (!isPlaintextRegistryAsset(asset)) {
        return null
    }

    return (
        <div className={`mt-3 ${className}`.trim()}>
            <p className="mb-1 text-xs font-medium text-gray-600">Text preview</p>
            {loading ? <p className="text-xs text-gray-500">Loading…</p> : null}
            {error ? <p className="text-xs text-red-600">{error}</p> : null}
            {content != null && !loading ? (
                <>
                    <pre
                        className="max-h-64 overflow-auto rounded-md border border-gray-200 bg-white p-3 text-left text-xs leading-relaxed text-gray-900 whitespace-pre-wrap break-words font-mono"
                        tabIndex={0}
                    >
                        {content}
                    </pre>
                    {truncated ? (
                        <p className="mt-1 text-[11px] text-gray-500">Showing the beginning of the file only. Download for the full document.</p>
                    ) : null}
                </>
            ) : null}
        </div>
    )
}
