import { useState, useEffect, useCallback } from 'react'
import { usePage, Head, Link } from '@inertiajs/react'
import PortalLayout from './PortalLayout'
import AssetLightbox from './AssetLightbox'

export default function PublicPortalCollection() {
    const { brand, theme, collection, assets, noindex } = usePage().props
    const [lightboxAsset, setLightboxAsset] = useState(null)
    const [scrollY, setScrollY] = useState(0)

    const handleScroll = useCallback(() => {
        setScrollY(window.scrollY)
    }, [])

    useEffect(() => {
        window.addEventListener('scroll', handleScroll, { passive: true })
        return () => window.removeEventListener('scroll', handleScroll)
    }, [handleScroll])

    const parallaxOffset = Math.min(scrollY * 0.06, 40)

    return (
        <PortalLayout>
            <Head title={`${collection.name} — ${theme.name}`}>
                {noindex && <meta name="robots" content="noindex, nofollow" />}
            </Head>

            {/* Breadcrumb */}
            <div className="px-6 sm:px-10 pt-4">
                <Link href="/" className="text-xs text-white/40 hover:text-white/60 transition-colors">
                    &larr; Back to portal
                </Link>
            </div>

            {/* Collection header */}
            <section
                className="px-6 sm:px-10 pt-8 pb-10"
                style={{ transform: `translateY(${-parallaxOffset * 0.3}px)` }}
            >
                <h1 className="text-3xl md:text-4xl font-semibold tracking-tight leading-tight text-white/95">
                    {collection.name}
                </h1>
                {collection.description && (
                    <p className="mt-3 text-sm text-white/50 max-w-xl">
                        {collection.description}
                    </p>
                )}
                <p className="mt-3 text-xs text-white/30">
                    {assets.length} {assets.length === 1 ? 'asset' : 'assets'}
                </p>
            </section>

            {/* Asset grid */}
            <section className="px-6 sm:px-10 pb-20">
                {assets.length > 0 ? (
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        {assets.map((asset) => (
                            <button
                                key={asset.id}
                                type="button"
                                onClick={() => setLightboxAsset(asset)}
                                className="group relative overflow-hidden rounded-lg bg-white/[0.04] border border-white/[0.06] hover:border-white/[0.14] transition-all duration-200 hover:scale-[1.01] text-left cursor-pointer"
                            >
                                {asset.thumbnail_url ? (
                                    <div className="aspect-square overflow-hidden">
                                        <img
                                            src={asset.thumbnail_url}
                                            alt={asset.title}
                                            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                            loading="lazy"
                                        />
                                    </div>
                                ) : (
                                    <div className="aspect-square flex items-center justify-center bg-white/[0.02]">
                                        <FileIcon mimeType={asset.mime_type} />
                                    </div>
                                )}
                                <div className="p-2.5">
                                    <p className="text-xs text-white/70 truncate font-medium">
                                        {asset.title}
                                    </p>
                                    <p className="text-[10px] text-white/30 mt-0.5 truncate">
                                        {asset.original_filename}
                                    </p>
                                </div>
                            </button>
                        ))}
                    </div>
                ) : (
                    <div className="py-16 text-center">
                        <p className="text-sm text-white/40">No assets in this collection yet.</p>
                    </div>
                )}
            </section>

            {lightboxAsset && (
                <AssetLightbox
                    asset={lightboxAsset}
                    theme={theme}
                    onClose={() => setLightboxAsset(null)}
                />
            )}
        </PortalLayout>
    )
}

function FileIcon({ mimeType }) {
    const isImage = mimeType?.startsWith('image/')
    const isVideo = mimeType?.startsWith('video/')

    if (isImage) {
        return (
            <svg className="w-8 h-8 text-white/15" fill="none" viewBox="0 0 24 24" strokeWidth="1" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
            </svg>
        )
    }

    if (isVideo) {
        return (
            <svg className="w-8 h-8 text-white/15" fill="none" viewBox="0 0 24 24" strokeWidth="1" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 010 1.972l-11.54 6.347a1.125 1.125 0 01-1.667-.986V5.653z" />
            </svg>
        )
    }

    return (
        <svg className="w-8 h-8 text-white/15" fill="none" viewBox="0 0 24 24" strokeWidth="1" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
        </svg>
    )
}
