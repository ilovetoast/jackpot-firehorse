/**
 * Public collection page (C8). No auth. Cinematic layout with centered hero.
 * C11: Public Collection label, clear empty state (read-only, limited to visible assets).
 * D6: Download collection affordance — opens panel to create ZIP of all collection assets.
 * Branding: Logo, accent/primary colors, background visuals from Brand Settings > Public Pages.
 * Layout: Centered logo + copy + CTA; blurred background image with gradients; tiles directly over background.
 */
import { useState } from 'react'
import { DocumentIcon, ArrowDownTrayIcon, XMarkIcon } from '@heroicons/react/24/outline'
import AssetGrid from '../../Components/AssetGrid'

export default function PublicCollection({
    collection = {},
    assets = [],
    public_collection_downloads_enabled: downloadCollectionEnabled = false,
    branding_options = {},
}) {
    const { name, description, brand_name, brand_slug, slug } = collection
    const accentColor = branding_options?.accent_color || branding_options?.primary_color || '#4F46E5'
    const primaryColor = branding_options?.primary_color || accentColor
    const logoUrl = branding_options?.logo_url || null
    const backgroundImageUrl = branding_options?.background_image_url || null
    const themeDark = branding_options?.theme_dark ?? false
    const hasBackground = !!backgroundImageUrl

    const [downloadPanelOpen, setDownloadPanelOpen] = useState(false)
    const [downloadSubmitting, setDownloadSubmitting] = useState(false)
    const [downloadError, setDownloadError] = useState(null)

    const openDownloadPanel = () => {
        setDownloadError(null)
        setDownloadPanelOpen(true)
    }

    const handleDownloadCollectionSubmit = (e) => {
        e.preventDefault()
        setDownloadError(null)
        setDownloadSubmitting(true)
        e.target.submit()
        setDownloadSubmitting(false)
        setDownloadPanelOpen(false)
    }

    const handleAssetClick = (asset) => {
        if (asset.download_url) {
            window.open(asset.download_url, '_blank', 'noopener,noreferrer')
        }
    }

    // Base: white or black based on brand identity
    const baseBg = themeDark ? '#0a0a0a' : '#ffffff'
    // Gradient from top: fades the background image into the base
    const gradientFrom = themeDark ? 'rgba(10,10,10,0.3)' : 'rgba(255,255,255,0.4)'
    const gradientTo = themeDark ? 'rgba(10,10,10,0.95)' : 'rgba(255,255,255,0.95)'
    const textColor = themeDark ? 'text-white' : 'text-gray-900'
    const textMuted = themeDark ? 'text-white/80' : 'text-gray-600'
    const textMutedLight = themeDark ? 'text-white/60' : 'text-gray-500'

    return (
        <div
            className="min-h-screen relative"
            style={{ backgroundColor: baseBg, '--accent': accentColor }}
        >
            {/* Blurred background image — opaque and fading into base */}
            {hasBackground && (
                <>
                    <div
                        className="fixed inset-0 bg-cover bg-center bg-no-repeat blur-2xl scale-105"
                        style={{
                            backgroundImage: `url(${backgroundImageUrl})`,
                            opacity: 0.5,
                        }}
                        aria-hidden
                    />
                    <div
                        className="fixed inset-0"
                        style={{
                            background: `linear-gradient(to bottom, ${gradientFrom} 0%, transparent 25%, transparent 60%, ${gradientTo} 100%)`,
                        }}
                        aria-hidden
                    />
                </>
            )}

            {/* Centered hero: logo, copy, download button */}
            <section className="relative z-10 pt-12 pb-8 px-4 flex flex-col items-center text-center">
                {logoUrl && (
                    <div className="mb-6">
                        <img
                            src={logoUrl}
                            alt=""
                            className="h-16 w-auto object-contain mx-auto max-h-24"
                            onError={(e) => { e.target.style.display = 'none' }}
                        />
                    </div>
                )}
                <p className={`text-sm font-medium ${textMuted}`}>{brand_name || 'Brand'}</p>
                <h1 className={`mt-1 text-3xl font-bold ${textColor}`}>{name || 'Collection'}</h1>
                <span
                    className="mt-2 inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium text-white"
                    style={{ backgroundColor: primaryColor }}
                    title="This collection is viewable via a shareable link."
                >
                    Public collection
                </span>
                {description && (
                    <p className={`mt-3 text-sm max-w-xl mx-auto ${textMuted}`}>{description}</p>
                )}
                {downloadCollectionEnabled && assets && assets.length > 0 ? (
                    <button
                        type="button"
                        onClick={openDownloadPanel}
                        className="mt-6 inline-flex items-center rounded-lg px-6 py-3 text-base font-semibold text-white shadow-lg hover:opacity-90 transition-opacity"
                        style={{ backgroundColor: accentColor }}
                    >
                        <ArrowDownTrayIcon className="mr-2 h-5 w-5" aria-hidden="true" />
                        Download collection
                    </button>
                ) : !downloadCollectionEnabled && assets && assets.length > 0 ? (
                    <span
                        className={`mt-6 inline-flex items-center rounded-lg px-6 py-3 text-base font-medium ${textMutedLight} cursor-not-allowed border border-current`}
                        title="Upgrade to enable public collection downloads"
                    >
                        <ArrowDownTrayIcon className="mr-2 h-5 w-5" aria-hidden="true" />
                        Download collection
                    </span>
                ) : null}
            </section>

            {/* Asset grid — no container background; tiles sit directly over the page */}
            <main className="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-16">
                {!assets || assets.length === 0 ? (
                    <div className={`text-center py-16 ${textMuted}`}>
                        <DocumentIcon className={`mx-auto h-14 w-14 ${textMutedLight}`} aria-hidden="true" />
                        <p className={`mt-3 font-medium ${textColor}`}>No assets to view</p>
                        <p className={`mt-1 text-sm max-w-md mx-auto ${textMuted}`}>
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
                        primaryColor={accentColor}
                        cardVariant={hasBackground ? 'cinematic' : 'default'}
                    />
                )}
            </main>

            {/* Download collection panel */}
            {downloadPanelOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="Download collection" role="dialog" aria-modal="true">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity" aria-hidden="true" onClick={() => !downloadSubmitting && setDownloadPanelOpen(false)} />
                        <div className="relative transform overflow-hidden rounded-xl bg-white px-4 pb-4 pt-5 text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div className="absolute right-0 top-0 pr-4 pt-4">
                                <button
                                    type="button"
                                    className="rounded-md text-gray-400 hover:text-gray-600 focus:outline-none"
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
                                                className="inline-flex w-full justify-center rounded-lg px-4 py-3 text-sm font-semibold text-white shadow-md hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50 sm:col-start-2"
                                                style={{ backgroundColor: accentColor }}
                                            >
                                                {downloadSubmitting ? 'Preparing…' : 'Download collection'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setDownloadPanelOpen(false)}
                                                disabled={downloadSubmitting}
                                                className="mt-3 inline-flex w-full justify-center rounded-lg bg-white px-4 py-3 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:col-start-1 sm:mt-0 disabled:opacity-50"
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
