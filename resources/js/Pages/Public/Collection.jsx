/**
 * Public collection page (C8). No auth. Brand header, collection name/description, asset grid, download per asset.
 * C11: Public Collection label, clear empty state (read-only, limited to visible assets).
 * D6: Download collection affordance (secondary CTA) — opens panel to create ZIP of all collection assets.
 */
import { useState } from 'react'
import { DocumentIcon, ArrowDownTrayIcon, XMarkIcon } from '@heroicons/react/24/outline'
import AssetGrid from '../../Components/AssetGrid'

export default function PublicCollection({
    collection = {},
    assets = [],
    public_collection_downloads_enabled: downloadCollectionEnabled = false,
}) {
    const { name, description, brand_name, brand_slug, slug } = collection
    const [downloadPanelOpen, setDownloadPanelOpen] = useState(false)
    const [downloadSubmitting, setDownloadSubmitting] = useState(false)
    const [downloadError, setDownloadError] = useState(null)

    const openDownloadPanel = () => {
        setDownloadError(null)
        setDownloadPanelOpen(true)
    }

    // On-the-fly collection ZIP: form submits in new tab so redirect to zip URL triggers file download there; this tab stays on collection.
    const handleDownloadCollectionSubmit = (e) => {
        e.preventDefault()
        setDownloadError(null)
        setDownloadSubmitting(true)
        e.target.submit()
        setDownloadSubmitting(false)
        setDownloadPanelOpen(false)
    }

    // Handle asset click - for public collections, clicking downloads the asset (tracked server-side, opens in new window)
    const handleAssetClick = (asset) => {
        if (asset.download_url) {
            window.open(asset.download_url, '_blank', 'noopener,noreferrer')
        }
    }

    return (
        <div className="min-h-screen bg-gray-50">
            {/* Brand header — no AppNav */}
            <header className="bg-white border-b border-gray-200">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-sm font-medium text-gray-500">{brand_name || 'Brand'}</p>
                            <div className="mt-1 flex items-center gap-2 flex-wrap">
                                <h1 className="text-2xl font-bold text-gray-900">{name || 'Collection'}</h1>
                                <span
                                    className="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600"
                                    title="This collection is viewable via a shareable link. Access is limited to the assets shown."
                                >
                                    Public collection
                                </span>
                            </div>
                            {description && (
                                <p className="mt-2 text-sm text-gray-600 max-w-2xl">{description}</p>
                            )}
                        </div>
                        {/* D6: Download collection — secondary CTA, top-right */}
                        {downloadCollectionEnabled && assets && assets.length > 0 ? (
                            <button
                                type="button"
                                onClick={openDownloadPanel}
                                className="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                            >
                                <ArrowDownTrayIcon className="mr-2 h-5 w-5 text-gray-500" aria-hidden="true" />
                                Download collection
                            </button>
                        ) : !downloadCollectionEnabled && assets && assets.length > 0 ? (
                            <span
                                className="inline-flex items-center rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed"
                                title="Upgrade to enable public collection downloads"
                            >
                                <ArrowDownTrayIcon className="mr-2 h-5 w-5" aria-hidden="true" />
                                Download collection
                            </span>
                        ) : null}
                    </div>
                </div>
            </header>

            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {!assets || assets.length === 0 ? (
                    <div className="text-center py-12 text-gray-500">
                        <DocumentIcon className="mx-auto h-12 w-12 text-gray-300" aria-hidden="true" />
                        <p className="mt-2 font-medium text-gray-600">No assets to view</p>
                        <p className="mt-1 text-sm text-gray-500 max-w-md mx-auto">
                            This public collection has no visible assets. Access is limited to the assets shown here.
                        </p>
                    </div>
                ) : (
                    <AssetGrid
                        assets={assets}
                        onAssetClick={handleAssetClick}
                        cardSize={220}
                        showInfo={true}
                        selectedAssetId={null}
                        primaryColor="#6366f1"
                    />
                )}
            </main>

            {/* D6: Download collection panel — locked to collection scope, public access, default name/expiration */}
            {downloadPanelOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="Download collection" role="dialog" aria-modal="true">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onClick={() => !downloadSubmitting && setDownloadPanelOpen(false)} />
                        <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div className="absolute right-0 top-0 pr-4 pt-4">
                                <button
                                    type="button"
                                    className="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none"
                                    onClick={() => !downloadSubmitting && setDownloadPanelOpen(false)}
                                    disabled={downloadSubmitting}
                                >
                                    <XMarkIcon className="h-5 w-5" aria-hidden="true" />
                                </button>
                            </div>
                            <div className="sm:flex sm:items-start">
                                <div className="mt-3 w-full text-center sm:mt-0 sm:text-left">
                                    <h3 className="text-base font-semibold leading-6 text-gray-900" id="download-panel-title">
                                        Download collection
                                    </h3>
                                    <p className="mt-1 text-sm text-gray-500">
                                        Download all assets in this collection as a ZIP (generated on the fly; no link is stored).
                                    </p>
                                    <form
                                        onSubmit={handleDownloadCollectionSubmit}
                                        method="post"
                                        action={route('public.collections.download', { brand_slug: brand_slug, collection_slug: slug })}
                                        target="_blank"
                                        className="mt-4 space-y-4"
                                    >
                                        <input type="hidden" name="_token" value={typeof document !== 'undefined' ? (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '') : ''} />
                                        <p className="text-sm text-gray-600">
                                            {assets.length} asset{assets.length !== 1 ? 's' : ''} will be included.
                                        </p>
                                        {downloadError && (
                                            <p className="text-sm text-red-600">{downloadError}</p>
                                        )}
                                        <div className="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                            <button
                                                type="submit"
                                                disabled={downloadSubmitting}
                                                className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 sm:col-start-2"
                                            >
                                                {downloadSubmitting ? 'Preparing…' : 'Download collection'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setDownloadPanelOpen(false)}
                                                disabled={downloadSubmitting}
                                                className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:col-start-1 sm:mt-0 disabled:opacity-50"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}
