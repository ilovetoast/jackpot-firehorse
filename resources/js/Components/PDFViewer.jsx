import { useEffect, useRef, useState } from 'react'
import { ArrowPathIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/outline'

function buildPageEndpoint(asset, page) {
    const template = asset?.pdf_page_api_endpoint
    if (template && template.includes('__PAGE__')) {
        return template.replace('__PAGE__', String(page))
    }

    return `/app/assets/${asset?.id}/pdf-page/${page}`
}

export default function PDFViewer({ asset }) {
    const totalPages = Math.max(1, Number(asset?.pdf_page_count || 1))
    const [currentPage, setCurrentPage] = useState(1)
    const [pageCache, setPageCache] = useState({})
    const [loadingPage, setLoadingPage] = useState(1)
    const [processingPage, setProcessingPage] = useState(null)
    const [error, setError] = useState(null)
    const [isFading, setIsFading] = useState(false)
    const retryTimersRef = useRef(new Map())

    const clearRetryTimers = () => {
        retryTimersRef.current.forEach((timerId) => clearTimeout(timerId))
        retryTimersRef.current.clear()
    }

    const fetchPage = async (page, attempt = 0) => {
        if (!asset?.id || page < 1 || page > totalPages) return

        if (pageCache[page]) {
            setLoadingPage(null)
            setProcessingPage(null)
            return
        }

        setLoadingPage(page)
        setError(null)

        try {
            const endpoint = buildPageEndpoint(asset, page)
            const response = await fetch(endpoint, {
                headers: { Accept: 'application/json' },
            })

            const payload = await response.json()

            if (payload?.status === 'ready' && payload?.url) {
                setPageCache((prev) => ({ ...prev, [page]: payload.url }))
                setLoadingPage(null)
                setProcessingPage(null)
                retryTimersRef.current.delete(page)
                return
            }

            if (payload?.status === 'processing') {
                setProcessingPage(page)

                if (attempt < 8) {
                    const nextDelayMs = Math.min(2000, 700 + (attempt * 200))
                    const timerId = setTimeout(() => fetchPage(page, attempt + 1), nextDelayMs)
                    retryTimersRef.current.set(page, timerId)
                }

                return
            }

            setError(payload?.message || 'Unable to load PDF page.')
            setLoadingPage(null)
            setProcessingPage(null)
        } catch (e) {
            setError('Unable to load PDF page.')
            setLoadingPage(null)
            setProcessingPage(null)
        }
    }

    useEffect(() => {
        clearRetryTimers()

        setCurrentPage(1)
        setError(null)
        setLoadingPage(1)
        setProcessingPage(null)
        setPageCache(asset?.first_page_url ? { 1: asset.first_page_url } : {})

        return () => clearRetryTimers()
    }, [asset?.id, asset?.first_page_url])

    useEffect(() => {
        fetchPage(currentPage)

        if (currentPage < totalPages) {
            fetchPage(currentPage + 1)
        }
    }, [currentPage, totalPages, asset?.id])

    useEffect(() => {
        setIsFading(true)
        const timerId = setTimeout(() => setIsFading(false), 120)
        return () => clearTimeout(timerId)
    }, [currentPage])

    const currentUrl = pageCache[currentPage] || (currentPage === 1 ? asset?.first_page_url : null)
    const isBusy = loadingPage === currentPage || processingPage === currentPage

    return (
        <div className="w-full h-full flex flex-col">
            <div className="mb-4 flex items-center justify-center gap-3">
                <button
                    type="button"
                    onClick={() => setCurrentPage((prev) => Math.max(1, prev - 1))}
                    disabled={currentPage <= 1}
                    className="inline-flex items-center rounded-full border border-white/25 bg-white/10 p-2 text-white hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-40"
                    aria-label="Previous page"
                >
                    <ChevronLeftIcon className="h-5 w-5" />
                </button>

                <span className="text-sm font-medium text-white/90">
                    Page {currentPage} of {totalPages}
                </span>

                <button
                    type="button"
                    onClick={() => setCurrentPage((prev) => Math.min(totalPages, prev + 1))}
                    disabled={currentPage >= totalPages}
                    className="inline-flex items-center rounded-full border border-white/25 bg-white/10 p-2 text-white hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-40"
                    aria-label="Next page"
                >
                    <ChevronRightIcon className="h-5 w-5" />
                </button>
            </div>

            <div className="relative flex-1 flex items-center justify-center overflow-hidden">
                {!currentUrl && (
                    <div className="h-[70vh] w-[85%] max-w-5xl rounded-lg bg-gradient-to-r from-gray-700/40 via-gray-600/40 to-gray-700/40 animate-pulse" />
                )}

                {currentUrl && (
                    <img
                        key={`${asset?.id}-${currentPage}`}
                        src={currentUrl}
                        alt={`PDF page ${currentPage}`}
                        className={`max-h-full max-w-full object-contain transition-opacity duration-200 ${isFading ? 'opacity-50' : 'opacity-100'}`}
                    />
                )}

                {isBusy && (
                    <div className="absolute inset-0 flex items-center justify-center bg-black/20">
                        <div className="inline-flex items-center gap-2 rounded-md bg-black/50 px-3 py-2 text-sm text-white">
                            <ArrowPathIcon className="h-4 w-4 animate-spin" />
                            {processingPage === currentPage ? 'Rendering page…' : 'Loading page…'}
                        </div>
                    </div>
                )}

                {error && (
                    <div className="absolute bottom-4 rounded-md border border-red-300/50 bg-red-500/20 px-3 py-2 text-sm text-red-100">
                        {error}
                    </div>
                )}
            </div>

            {/* Future enhancement: "continuous scroll mode" can virtualize sequential page rendering here. */}
        </div>
    )
}
