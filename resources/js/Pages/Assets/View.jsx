/**
 * Single-asset view page. Used when opening an asset in a new tab (e.g. from CollectionOnlyView).
 * Supports collection_only back link and download.
 */
import { useEffect, useState } from 'react'
import { usePage } from '@inertiajs/react'
import { Link } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'

export default function AssetView({ asset }) {
    const { auth } = usePage().props
    const isImage = asset?.mime_type?.startsWith('image/')
    const isVideo = asset?.mime_type?.startsWith('video/')
    const isPdf = (asset?.mime_type || '').toLowerCase().includes('pdf')
    const [pdfPage, setPdfPage] = useState(1)
    const [pdfPageUrl, setPdfPageUrl] = useState(null)
    const [pdfPageCount, setPdfPageCount] = useState(Number(asset?.pdf_page_count || 1))
    const [pdfLoading, setPdfLoading] = useState(false)
    const [pdfError, setPdfError] = useState(null)
    const [fullExtractionLoading, setFullExtractionLoading] = useState(false)
    const [fullExtractionRequested, setFullExtractionRequested] = useState(false)
    const [fullExtractionMessage, setFullExtractionMessage] = useState(null)
    const tenantRole = String(auth?.tenant_role || auth?.user?.tenant_role || '').toLowerCase()
    const canRequestFullPdfExtraction = tenantRole === 'owner' || tenantRole === 'admin'

    const fetchPdfPage = async (targetPage, attempt = 0) => {
        if (!asset?.id) return
        setPdfLoading(true)
        setPdfError(null)
        try {
            const response = await window.axios.get(`/app/assets/${asset.id}/pdf-page/${targetPage}`, {
                headers: { Accept: 'application/json' },
            })
            const payload = response?.data || {}

            if (payload.page_count != null) {
                setPdfPageCount(Number(payload.page_count))
            }

            if (payload.status === 'ready' && payload.url) {
                setPdfPageUrl(payload.url)
                setPdfLoading(false)
                return
            }

            if (payload.status === 'processing' && attempt < 20) {
                setTimeout(() => fetchPdfPage(targetPage, attempt + 1), Number(payload.poll_after_ms || 1200))
                return
            }

            setPdfLoading(false)
            setPdfError(payload.message || 'Unable to load PDF preview.')
        } catch (error) {
            setPdfLoading(false)
            setPdfError(error?.response?.data?.message || 'Unable to load PDF preview.')
        }
    }

    useEffect(() => {
        if (!isPdf || !asset?.id) return
        setPdfPage(1)
        setPdfPageUrl(null)
        setFullExtractionLoading(false)
        setFullExtractionRequested(false)
        setFullExtractionMessage(null)
        fetchPdfPage(1)
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [asset?.id, isPdf])

    const requestFullExtraction = async () => {
        if (!isPdf || !asset?.id || !canRequestFullPdfExtraction || fullExtractionLoading) return

        setFullExtractionLoading(true)
        try {
            const response = await window.axios.post(
                `/app/assets/${asset.id}/pdf-pages/full-extraction`,
                {},
                { headers: { Accept: 'application/json' } }
            )
            setFullExtractionRequested(true)
            setFullExtractionMessage(response?.data?.message || 'Full PDF extraction queued.')
        } catch (error) {
            setFullExtractionMessage(error?.response?.data?.message || 'Unable to queue full PDF extraction.')
        } finally {
            setFullExtractionLoading(false)
        }
    }

    return (
        <div className="min-h-screen bg-gray-100">
            <AppHead title="Asset" />
            <AppNav brand={auth?.activeBrand} tenant={null} />

            <div className="max-w-5xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                {asset?.collection_only && asset?.collection && (
                    <Link
                        href={route('collection-invite.landing', { collection: asset.collection.id })}
                        className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4"
                    >
                        ← Back to collection
                    </Link>
                )}

                <div className="bg-white rounded-lg shadow overflow-hidden">
                    <div className="p-4 border-b border-gray-200 flex items-center justify-between flex-wrap gap-2">
                        <h1 className="text-lg font-semibold text-gray-900 truncate">
                            {asset?.title || asset?.original_filename || 'Asset'}
                        </h1>
                        {asset?.download_url && (
                            <a
                                href={asset.download_url}
                                className="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                            >
                                Download
                            </a>
                        )}
                    </div>

                    <div className="p-4 flex flex-col justify-center items-center min-h-[400px] bg-gray-50 gap-4">
                        {!asset ? (
                            <p className="text-gray-500">Asset not found.</p>
                        ) : (
                            <>
                                {isPdf && (
                                    <div className="w-full max-w-4xl">
                                        <div className="bg-white rounded border border-gray-200 min-h-[420px] flex items-center justify-center">
                                            {pdfPageUrl ? (
                                                <img
                                                    src={pdfPageUrl}
                                                    alt={`PDF page ${pdfPage}`}
                                                    className="max-w-full max-h-[60vh] w-auto h-auto object-contain"
                                                />
                                            ) : (
                                                <div className="text-center text-gray-500 text-sm px-4">
                                                    {pdfLoading ? `Rendering page ${pdfPage}...` : 'Preparing PDF preview...'}
                                                    {pdfError && <div className="mt-2 text-amber-600">{pdfError}</div>}
                                                </div>
                                            )}
                                        </div>
                                        <div className="mt-3 flex items-center justify-between">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    const next = Math.max(1, pdfPage - 1)
                                                    setPdfPage(next)
                                                    fetchPdfPage(next)
                                                }}
                                                disabled={pdfPage <= 1 || pdfLoading}
                                                className="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded text-xs font-medium text-gray-700 bg-white disabled:opacity-50"
                                            >
                                                Previous
                                            </button>
                                            <div className="text-xs text-gray-600">
                                                Page {pdfPage} of {Math.max(1, pdfPageCount)}
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    const maxPage = Math.max(1, pdfPageCount)
                                                    const next = Math.min(maxPage, pdfPage + 1)
                                                    setPdfPage(next)
                                                    fetchPdfPage(next)
                                                }}
                                                disabled={pdfPage >= Math.max(1, pdfPageCount) || pdfLoading}
                                                className="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded text-xs font-medium text-gray-700 bg-white disabled:opacity-50"
                                            >
                                                Next
                                            </button>
                                        </div>
                                        {canRequestFullPdfExtraction && Math.max(1, pdfPageCount) > 1 && (
                                            <div className="mt-2 flex items-center justify-between rounded border border-gray-200 bg-white px-3 py-2">
                                                <p className="text-xs text-gray-500">Need all pages rendered for AI ingestion?</p>
                                                <button
                                                    type="button"
                                                    onClick={requestFullExtraction}
                                                    disabled={fullExtractionLoading || fullExtractionRequested}
                                                    className="inline-flex items-center rounded border border-indigo-300 px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-50"
                                                >
                                                    {fullExtractionLoading
                                                        ? 'Queueing...'
                                                        : fullExtractionRequested
                                                            ? 'Queued'
                                                            : 'Render all pages'}
                                                </button>
                                            </div>
                                        )}
                                        {fullExtractionMessage && (
                                            <p className={`mt-2 text-xs ${fullExtractionRequested ? 'text-green-700' : 'text-amber-700'}`}>
                                                {fullExtractionMessage}
                                            </p>
                                        )}
                                    </div>
                                )}
                                {/* Always show thumbnail when available (any file type) */}
                                {!isPdf && asset.thumbnail_url && (
                                    <div className="flex-shrink-0 max-w-full max-h-[60vh] flex justify-center">
                                        <img
                                            src={asset.thumbnail_url}
                                            alt={asset.title || asset.original_filename || 'Asset thumbnail'}
                                            className="max-w-full max-h-[60vh] w-auto h-auto object-contain rounded border border-gray-200 shadow-sm"
                                        />
                                    </div>
                                )}
                                {/* Full preview for images (thumbnail already shown above if no larger preview); message for others */}
                                {isPdf ? null : isImage && asset.thumbnail_url ? null : isVideo ? (
                                    <p className="text-gray-500 text-center">
                                        Video preview not available in this view. Use Download to get the file.
                                    </p>
                                ) : !asset.thumbnail_url ? (
                                    <div className="text-center text-gray-500">
                                        <p className="mb-2">No thumbnail available.</p>
                                        {asset.download_url && (
                                            <a
                                                href={asset.download_url}
                                                className="text-indigo-600 hover:text-indigo-800 font-medium"
                                            >
                                                Download file
                                            </a>
                                        )}
                                    </div>
                                ) : (
                                    <div className="text-center text-gray-500">
                                        <p className="mb-2">Preview not available for this file type.</p>
                                        {asset.download_url && (
                                            <a
                                                href={asset.download_url}
                                                className="text-indigo-600 hover:text-indigo-800 font-medium"
                                            >
                                                Download file
                                            </a>
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </div>
        </div>
    )
}
