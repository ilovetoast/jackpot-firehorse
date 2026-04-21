import { useState, useRef, useEffect, useCallback } from 'react'
import { usePage, Head, Link } from '@inertiajs/react'
import PortalLayout from './PortalLayout'
import LogoMark from '@/Components/Brand/LogoMark'
import AssetLightbox from './AssetLightbox'
import {
    effectiveFocalPointFromAsset,
    focalPointObjectPositionStyle,
    mergeImageStyle,
} from '../../utils/guidelinesFocalPoint'

export default function PublicPortalIndex() {
    const { brand, theme, collections, recentAssets, portalConfig } = usePage().props
    const [lightboxAsset, setLightboxAsset] = useState(null)
    const [scrollY, setScrollY] = useState(0)
    const heroRef = useRef(null)

    const handleScroll = useCallback(() => {
        setScrollY(window.scrollY)
    }, [])

    useEffect(() => {
        window.addEventListener('scroll', handleScroll, { passive: true })
        return () => window.removeEventListener('scroll', handleScroll)
    }, [handleScroll])

    const parallaxOffset = Math.min(scrollY * 0.08, 60)

    return (
        <PortalLayout>
            <Head title={`${theme.name} — Brand Portal`}>
                {portalConfig.noindex && <meta name="robots" content="noindex, nofollow" />}
            </Head>

            {/* Hero */}
            <section
                ref={heroRef}
                className="relative pt-24 pb-20 px-6 sm:px-10 text-center overflow-hidden"
                style={{ transform: `translateY(${-parallaxOffset * 0.5}px)` }}
            >
                <div className="max-w-3xl mx-auto">
                    <div className="flex justify-center mb-8">
                        <LogoMark
                            name={theme.name}
                            logo={theme.logo}
                            size="lg"
                        />
                    </div>

                    <h1 className="text-4xl md:text-5xl font-semibold tracking-tight leading-tight text-white/95">
                        {theme.name}
                    </h1>

                    {theme.tagline && (
                        <p className="mt-4 text-lg text-white/50 max-w-xl mx-auto leading-relaxed">
                            {theme.tagline}
                        </p>
                    )}

                    {(portalConfig.showCollections || portalConfig.showAssets) && (
                        <div className="mt-8 flex items-center justify-center gap-6 text-xs text-white/30">
                            {portalConfig.showCollections && (
                                <span>{collections.length} {collections.length === 1 ? 'collection' : 'collections'}</span>
                            )}
                            {portalConfig.showCollections && portalConfig.showAssets && (
                                <span className="w-px h-3 bg-white/10" />
                            )}
                            {portalConfig.showAssets && (
                                <span>{recentAssets.length} assets</span>
                            )}
                        </div>
                    )}
                </div>
            </section>

            {/* Collections */}
            {portalConfig.showCollections && (
                <section
                    className="px-6 sm:px-10 pb-16"
                    style={{ transform: `translateY(${-parallaxOffset * 0.15}px)` }}
                >
                    <h2 className="text-xl font-semibold text-white/90 mb-6">Collections</h2>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        {collections.map((c) => (
                            <Link
                                key={c.id}
                                href={`/collections/${c.id}`}
                                className="group relative overflow-hidden rounded-xl bg-white/[0.04] border border-white/[0.08] hover:border-white/[0.16] hover:bg-white/[0.07] transition-all duration-200 hover:scale-[1.01]"
                            >
                                {c.cover_url ? (
                                    <div className="aspect-[16/9] overflow-hidden">
                                        <img
                                            src={c.cover_url}
                                            alt={c.name}
                                            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                            loading="lazy"
                                        />
                                    </div>
                                ) : (
                                    <div
                                        className="aspect-[16/9] flex items-center justify-center"
                                        style={{ background: `linear-gradient(135deg, ${theme.colors.primary}22, ${theme.colors.secondary}22)` }}
                                    >
                                        <svg className="w-10 h-10 text-white/20" fill="none" viewBox="0 0 24 24" strokeWidth="1" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                                        </svg>
                                    </div>
                                )}

                                <div className="p-4">
                                    <h3 className="text-sm font-semibold text-white/90">{c.name}</h3>
                                    {c.description && (
                                        <p className="text-xs text-white/40 mt-1 line-clamp-2">{c.description}</p>
                                    )}
                                    <p className="text-xs text-white/30 mt-2">
                                        {c.asset_count} {c.asset_count === 1 ? 'asset' : 'assets'}
                                    </p>
                                </div>
                            </Link>
                        ))}
                    </div>
                </section>
            )}

            {/* Recent Assets */}
            {portalConfig.showAssets && (
                <section className="px-6 sm:px-10 pb-20">
                    <h2 className="text-xl font-semibold text-white/90 mb-6">Recent Assets</h2>
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        {recentAssets.map((asset) => (
                            <button
                                key={asset.id}
                                type="button"
                                onClick={() => setLightboxAsset(asset)}
                                className="group relative overflow-hidden rounded-lg bg-white/[0.04] border border-white/[0.06] hover:border-white/[0.14] transition-all duration-200 text-left cursor-pointer"
                            >
                                {asset.thumbnail_url ? (
                                    <div className="aspect-square overflow-hidden">
                                        <img
                                            src={asset.thumbnail_url}
                                            alt={asset.title}
                                            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                            loading="lazy"
                                            style={mergeImageStyle(
                                                undefined,
                                                focalPointObjectPositionStyle(effectiveFocalPointFromAsset(asset)),
                                            )}
                                        />
                                    </div>
                                ) : (
                                    <div className="aspect-square flex items-center justify-center bg-white/[0.02]">
                                        <svg className="w-8 h-8 text-white/15" fill="none" viewBox="0 0 24 24" strokeWidth="1" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                        </svg>
                                    </div>
                                )}
                                <div className="p-2.5">
                                    <p className="text-xs text-white/70 truncate font-medium">
                                        {asset.title}
                                    </p>
                                </div>
                            </button>
                        ))}
                    </div>
                </section>
            )}

            {/* Empty state */}
            {!portalConfig.showCollections && !portalConfig.showAssets && (
                <section className="px-6 sm:px-10 pb-20 text-center">
                    <div className="py-24">
                        <div
                            className="mx-auto h-20 w-20 rounded-2xl flex items-center justify-center mb-8"
                            style={{ background: `linear-gradient(135deg, ${theme.colors.primary}33, ${theme.colors.secondary}33)` }}
                        >
                            <svg className="w-10 h-10 text-white/30" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                            </svg>
                        </div>
                        <h3 className="text-xl font-semibold text-white/60">
                            {brand?.name || theme.name} hasn't published any content yet
                        </h3>
                        <p className="text-sm text-white/35 mt-3 max-w-sm mx-auto leading-relaxed">
                            Collections and assets will appear here once they're made public.
                        </p>
                    </div>
                </section>
            )}

            {/* Lightbox */}
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
