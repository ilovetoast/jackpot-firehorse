/**
 * Collections Sidebar (C4 read-only; C5 add Create button).
 * C11: Public indicator and asset count signals (read-only; no permission implication).
 * Lists collections for the current brand; selection is URL-driven (?collection=id).
 */
import { router } from '@inertiajs/react'
import { RectangleStackIcon, PlusIcon, GlobeAltIcon, UserGroupIcon, SparklesIcon } from '@heroicons/react/24/outline'

export default function CollectionsSidebar({
    collections = [],
    selectedCollectionId = null,
    sidebarColor = '#1f2937',
    /** Overview-style gradient; when set, overrides solid `sidebarColor` unless `transparentBackground`. */
    sidebarBackdropCss = null,
    textColor = '#ffffff',
    activeBgColor = null, // Accent-based highlight when provided
    activeTextColor = null, // Contrast text for selected item (when using full accent bg)
    hoverBgColor = null,
    canCreateCollection = false,
    onCreateCollection = null,
    publicCollectionsEnabled = false,
    /** Landing grid: no collection selected — show through cinematic backdrop (desktop row). */
    transparentBackground = false,
}) {
    const onCinematic = Boolean(sidebarBackdropCss && String(sidebarBackdropCss).trim() !== '')
    const effectiveTextColor = onCinematic ? '#ffffff' : textColor
    const isLight = effectiveTextColor === '#000000'
    const onDarkBackdrop = transparentBackground
    const listTextColor = onDarkBackdrop && isLight ? '#ffffff' : effectiveTextColor
    const mutedStyle = onDarkBackdrop
        ? { color: 'rgba(255, 255, 255, 0.55)' }
        : { color: isLight ? 'rgba(0, 0, 0, 0.6)' : 'rgba(255, 255, 255, 0.6)' }
    const buttonBg = onDarkBackdrop
        ? 'bg-white/15 hover:bg-white/25'
        : isLight
          ? 'bg-black/10 hover:bg-black/15'
          : 'bg-white/20 hover:bg-white/30'
    const itemActiveBg = activeBgColor || (isLight ? 'rgba(0, 0, 0, 0.1)' : 'rgba(255, 255, 255, 0.2)')
    const itemHoverBg = onDarkBackdrop
        ? 'rgba(255, 255, 255, 0.1)'
        : hoverBgColor || (isLight ? 'rgba(0, 0, 0, 0.05)' : 'rgba(255, 255, 255, 0.1)')
    const buttonText = onDarkBackdrop ? 'text-white' : isLight ? 'text-gray-900' : 'text-white'

    const handleSelectCollection = (id) => {
        router.get('/app/collections', { collection: id }, { preserveState: false, preserveScroll: true })
    }

    return (
        <div
            className="relative flex flex-col w-72 h-full flex-shrink-0"
            style={
                transparentBackground
                    ? { backgroundColor: 'transparent' }
                    : onCinematic
                      ? { background: sidebarBackdropCss, backgroundColor: '#0B0B0D' }
                      : { backgroundColor: sidebarColor }
            }
        >
            <div className="flex-1 flex flex-col pt-5 pb-4 overflow-y-auto">
                <nav className="mt-5 flex-1 px-2 space-y-1">
                    <div className="px-3 py-2">
                        <div className="flex items-center justify-between px-3">
                            <h3 className="text-xs font-semibold uppercase tracking-wider" style={mutedStyle}>
                                Collections
                            </h3>
                            {canCreateCollection && onCreateCollection && (
                                <button
                                    type="button"
                                    onClick={onCreateCollection}
                                    className={`rounded p-1.5 ${buttonBg} ${buttonText} focus:outline-none focus:ring-2 focus:ring-white/50`}
                                    title="Create collection"
                                >
                                    <PlusIcon className="h-4 w-4" />
                                </button>
                            )}
                        </div>
                        <div className="mt-2 space-y-1">
                            {collections.length === 0 ? (
                                <div className="px-3 py-2 text-sm" style={mutedStyle}>
                                    No collections yet
                                </div>
                            ) : (
                                collections.map((c) => {
                                    const isActive = selectedCollectionId != null && c.id === selectedCollectionId
                                    const showPublic = publicCollectionsEnabled && !!c.is_public
                                    const showExternalGuests = !!c.allows_external_guests
                                    const showCampaign = !!c.has_campaign
                                    const count = typeof c.assets_count === 'number' ? c.assets_count : null
                                    const itemTextColor = isActive && activeTextColor ? activeTextColor : listTextColor
                                    return (
                                        <button
                                            key={c.id}
                                            type="button"
                                            onClick={() => handleSelectCollection(c.id)}
                                            className="w-full text-left px-3 py-2 rounded-md text-sm font-medium flex items-center gap-2"
                                            style={{
                                                backgroundColor: isActive ? itemActiveBg : 'transparent',
                                                color: itemTextColor,
                                            }}
                                            onMouseEnter={(e) => {
                                                if (!isActive) {
                                                    e.currentTarget.style.backgroundColor = itemHoverBg
                                                    e.currentTarget.style.color = listTextColor
                                                }
                                            }}
                                            onMouseLeave={(e) => {
                                                if (!isActive) {
                                                    e.currentTarget.style.backgroundColor = 'transparent'
                                                    e.currentTarget.style.color = listTextColor
                                                }
                                            }}
                                        >
                                            <RectangleStackIcon className="h-4 w-4 flex-shrink-0" style={{ color: itemTextColor }} />
                                            <span className="truncate flex-1 min-w-0">{c.name}</span>
                                            {showPublic && (
                                                <GlobeAltIcon
                                                    className="h-4 w-4 flex-shrink-0 opacity-80"
                                                    style={{ color: itemTextColor }}
                                                    title="Public collection — viewable via shareable link"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {showExternalGuests && (
                                                <UserGroupIcon
                                                    className="h-4 w-4 flex-shrink-0 opacity-80"
                                                    style={{ color: itemTextColor }}
                                                    title="External guests allowed — collection-only access by email"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {showCampaign && (
                                                <SparklesIcon
                                                    className="h-3.5 w-3.5 flex-shrink-0 opacity-70"
                                                    style={{ color: itemTextColor }}
                                                    title="Campaign identity configured"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {count !== null && (
                                                <span className="flex-shrink-0 text-xs opacity-80" style={{ color: itemTextColor }} aria-label={`${count} assets`}>
                                                    {count}
                                                </span>
                                            )}
                                        </button>
                                    )
                                })
                            )}
                        </div>
                    </div>
                </nav>
            </div>
        </div>
    )
}
