import { Link } from '@inertiajs/react'

/**
 * ActivityStrip — horizontal scroll of recent assets, activity, AI suggestions preview.
 * Fade edges (mask gradient), lazy load images.
 */
export default function ActivityStrip({
    recentAssets = [],
    recentActivity = [],
    aiSuggestionsPreview = null,
}) {
    const hasContent = recentAssets.length > 0 || recentActivity.length > 0 || aiSuggestionsPreview

    if (!hasContent) return null

    return (
        <div className="mt-8 animate-fadeInUp-d4">
            <div className="relative -mx-6 lg:-mx-12">
                {/* Fade edges */}
                <div className="absolute left-0 top-0 bottom-0 w-8 bg-gradient-to-r from-[#0B0B0D] to-transparent z-10 pointer-events-none" />
                <div className="absolute right-0 top-0 bottom-0 w-8 bg-gradient-to-l from-[#0B0B0D] to-transparent z-10 pointer-events-none" />

                <div className="overflow-x-auto overflow-y-hidden scrollbar-hide px-6 lg:px-12 py-2 flex gap-3" style={{ scrollbarWidth: 'none', msOverflowStyle: 'none' }}>
                    {recentAssets.slice(0, 8).map((asset) => (
                        <Link
                            key={asset.id}
                            href={`/app/assets?asset=${asset.id}`}
                            className="flex-shrink-0 w-16 h-16 rounded-lg overflow-hidden ring-1 ring-white/10 hover:ring-white/25 transition-all duration-200 hover:scale-[1.04] bg-white/5"
                        >
                            {asset.thumbnail_url || asset.final_thumbnail_url || asset.preview_thumbnail_url ? (
                                <img
                                    src={asset.thumbnail_url || asset.final_thumbnail_url || asset.preview_thumbnail_url}
                                    alt={asset.title || 'Asset'}
                                    className="w-full h-full object-cover"
                                    loading="lazy"
                                />
                            ) : (
                                <div className="w-full h-full flex items-center justify-center text-white/30 text-xs">
                                    {(asset.title || '?').charAt(0)}
                                </div>
                            )}
                        </Link>
                    ))}
                    {aiSuggestionsPreview && aiSuggestionsPreview.total > 0 && (
                        <Link
                            href="/app/insights?tab=suggestions"
                            className="flex-shrink-0 flex items-center gap-2 px-4 py-2 rounded-lg bg-white/[0.06] ring-1 ring-white/[0.08] hover:bg-white/[0.12] hover:ring-white/[0.16] transition-all duration-200 hover:scale-[1.02]"
                        >
                            <span className="text-white/60 text-xs">AI</span>
                            <span className="text-sm font-medium text-white/90">
                                {aiSuggestionsPreview.total} to review
                            </span>
                        </Link>
                    )}
                </div>
            </div>
        </div>
    )
}
