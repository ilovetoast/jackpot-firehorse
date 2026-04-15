import { useState, useEffect } from 'react'
import { Link } from '@inertiajs/react'
import {
    SparklesIcon, Cog6ToothIcon, PencilSquareIcon,
    LinkIcon, ClipboardDocumentIcon, CheckIcon, PhotoIcon,
} from '@heroicons/react/24/outline'

const STATUS_STYLES = {
    active: 'bg-emerald-100 text-emerald-800',
    draft: 'bg-amber-100 text-amber-800',
    completed: 'bg-blue-100 text-blue-800',
    archived: 'bg-gray-100 text-gray-500',
}

const ROLE_LABELS = {
    primary: 'Primary',
    secondary: 'Secondary',
    accent: 'Accent',
    display: 'Display',
    body: 'Body',
}

const PUBLIC_TOOLTIP = 'Viewable via a shareable link. Collections do not grant access to assets outside this view.'

function StatusBadge({ status }) {
    const cls = STATUS_STYLES[status] || STATUS_STYLES.draft
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize ${cls}`}>
            {status}
        </span>
    )
}

function useCampaignFonts(fonts) {
    useEffect(() => {
        if (!Array.isArray(fonts) || fonts.length === 0) return
        fonts.forEach((font) => {
            const name = typeof font === 'string' ? font : font?.name
            if (!name) return
            const source = typeof font === 'string' ? 'unknown' : (font?.source || 'unknown')
            const fileUrls = (typeof font === 'object' && Array.isArray(font?.file_urls)) ? font.file_urls : []
            const safeId = name.replace(/\s+/g, '-').toLowerCase()
            if (source === 'google' || (source === 'unknown' && fileUrls.length === 0)) {
                const id = `gf-campaign-${safeId}`
                if (document.getElementById(id)) return
                const link = document.createElement('link')
                link.id = id
                link.rel = 'stylesheet'
                link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(name)}:wght@300;400;500;600;700&display=swap`
                document.head.appendChild(link)
            } else if (fileUrls.length > 0) {
                const id = `cf-campaign-${safeId}`
                if (document.getElementById(id)) return
                const faces = fileUrls.map((url) => {
                    const weight = /bold/i.test(url) ? '700' : /medium/i.test(url) ? '500' : /light/i.test(url) ? '300' : '400'
                    const format = /\.woff2/i.test(url) ? 'woff2' : /\.woff/i.test(url) ? 'woff' : /\.ttf/i.test(url) ? 'truetype' : /\.otf/i.test(url) ? 'opentype' : 'truetype'
                    return `@font-face { font-family: '${name}'; src: url('${url}') format('${format}'); font-weight: ${weight}; font-style: normal; font-display: swap; }`
                }).join('\n')
                const style = document.createElement('style')
                style.id = id
                style.textContent = faces
                document.head.appendChild(style)
            }
        })
    }, [fonts])
}

function getContrastText(hex) {
    if (!hex) return '#ffffff'
    const c = hex.replace('#', '')
    const r = parseInt(c.substr(0, 2), 16)
    const g = parseInt(c.substr(2, 2), 16)
    const b = parseInt(c.substr(4, 2), 16)
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.55 ? '#1f2937' : '#ffffff'
}

function PublicToggle({ collection, publicCollectionsEnabled, onPublicChange, primaryColor }) {
    const [updating, setUpdating] = useState(false)
    const [copied, setCopied] = useState(false)
    if (!publicCollectionsEnabled) return null

    const brandSlug = collection.brand_slug ?? ''
    const publicUrl = collection.slug && brandSlug
        ? `${typeof window !== 'undefined' ? window.location.origin : ''}/b/${brandSlug}/collections/${collection.slug}`
        : null

    const handleToggle = async (checked) => {
        if (updating || !collection?.id) return
        setUpdating(true)
        try {
            const res = await fetch(`/app/collections/${collection.id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json', Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ is_public: !!checked }),
            })
            if (res.ok && onPublicChange) onPublicChange()
        } finally { setUpdating(false) }
    }

    const copyLink = () => {
        if (!publicUrl) return
        const onSuccess = () => { setCopied(true); setTimeout(() => setCopied(false), 2000) }
        const onFailure = () => {
            try {
                const input = document.createElement('textarea')
                input.value = publicUrl
                input.style.position = 'fixed'
                input.style.opacity = '0'
                document.body.appendChild(input)
                input.select()
                input.setSelectionRange(0, publicUrl.length)
                const ok = document.execCommand('copy')
                document.body.removeChild(input)
                if (ok) onSuccess()
            } catch (e) { /* silent */ }
        }
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(publicUrl).then(onSuccess).catch(onFailure)
        } else { onFailure() }
    }

    return (
        <div className="flex items-center gap-2">
            <div className="flex items-center gap-1.5" title={PUBLIC_TOOLTIP}>
                <button
                    type="button" role="switch" aria-checked={!!collection.is_public}
                    disabled={updating}
                    onClick={() => handleToggle(!collection.is_public)}
                    className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed ${
                        collection.is_public && !primaryColor ? 'bg-indigo-600' : collection.is_public ? '' : 'bg-gray-200'
                    }`}
                    style={collection.is_public && primaryColor ? { backgroundColor: primaryColor } : undefined}
                >
                    <span className={`pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${collection.is_public ? 'translate-x-4' : 'translate-x-0'}`} />
                </button>
                <span className="text-xs font-medium text-gray-500">Public</span>
            </div>
            {collection.is_public && publicUrl && (
                <div className="flex items-center gap-1 rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5">
                    <LinkIcon className="h-3.5 w-3.5 text-gray-400 shrink-0" />
                    <span className="text-[11px] text-gray-500 truncate max-w-[140px]" title={publicUrl}>
                        {publicUrl.replace(/^https?:\/\//, '')}
                    </span>
                    <button type="button" onClick={copyLink} className="p-0.5 rounded hover:bg-gray-200 text-gray-400 hover:text-gray-600" title={copied ? 'Copied!' : 'Copy link'}>
                        {copied ? <CheckIcon className="h-3.5 w-3.5 text-green-600" /> : <ClipboardDocumentIcon className="h-3.5 w-3.5" />}
                    </button>
                </div>
            )}
        </div>
    )
}

function ColorTile({ color, label, height = 'h-14', className = '', style = {} }) {
    const [copied, setCopied] = useState(false)
    const textColor = getContrastText(color)
    const handleCopy = () => {
        if (!color) return
        const onSuccess = () => { setCopied(true); setTimeout(() => setCopied(false), 1200) }
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(color).then(onSuccess).catch(() => {})
        } else {
            onSuccess()
        }
    }
    return (
        <button
            type="button"
            onClick={handleCopy}
            className={`${height} rounded-lg relative overflow-hidden border border-black/5 text-left cursor-pointer transition-transform hover:scale-[1.02] active:scale-[0.98] ${className}`}
            style={{ backgroundColor: color, ...style }}
            title={`Click to copy ${color}`}
        >
            <span className="absolute top-1.5 left-2 text-[7px] font-bold uppercase tracking-wider leading-none" style={{ color: textColor, opacity: 0.55 }}>
                {copied ? 'Copied!' : label}
            </span>
            <span className="absolute bottom-1.5 left-2 text-[9px] font-mono leading-none" style={{ color: textColor, opacity: 0.7 }}>
                {color}
            </span>
        </button>
    )
}

export default function CampaignIdentityBanner({
    campaignSummary,
    collectionId,
    canUpdateCollection = false,
    collection = null,
    publicCollectionsEnabled = false,
    assetCount = null,
    onEditClick = null,
    onPublicChange = null,
    primaryColor = null,
    brandColors = {},
}) {
    const hasCampaign = !!campaignSummary
    const fonts = campaignSummary?.fonts
    useCampaignFonts(fonts)

    if (!hasCampaign && !canUpdateCollection) return null

    if (!hasCampaign) {
        return (
            <div className="mb-4 flex items-center gap-2.5 rounded-lg border border-dashed border-gray-300 bg-white/60 px-4 py-2.5">
                <SparklesIcon className="h-4 w-4 text-gray-400 shrink-0" />
                <span className="text-sm text-gray-500">No campaign identity</span>
                <Link href={`/app/collections/${collectionId}/campaign`} className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                    Set up campaign
                </Link>
            </div>
        )
    }

    const {
        campaign_name, campaign_status, campaign_goal, campaign_description,
        palette, accent_colors, primary_font, featured_image_url,
    } = campaignSummary

    const fontsList = Array.isArray(fonts) ? fonts : []
    const paletteColors = Array.isArray(palette) ? palette : []
    const accentList = Array.isArray(accent_colors) ? accent_colors : []
    const allColors = [...paletteColors, ...accentList].slice(0, 6)
    const hasColors = allColors.length > 0
    const hasFonts = fontsList.length > 0 || !!primary_font
    const hasGoal = !!(campaign_goal || campaign_description)
    const hasFeaturedImage = !!featured_image_url

    const primaryCampaignFont = fontsList.length > 0
        ? (typeof fontsList[0] === 'string' ? fontsList[0] : fontsList[0]?.name)
        : primary_font
    const titleFontFamily = primaryCampaignFont
        ? `'${primaryCampaignFont}', system-ui, sans-serif`
        : undefined

    const collectionName = collection?.name

    const fontsForDisplay = fontsList.length > 0
        ? fontsList.map((f) => (typeof f === 'string' ? { name: f, role: 'primary', source: 'unknown', file_urls: [] } : { file_urls: [], ...f }))
        : (primary_font ? [{ name: primary_font, role: 'primary', source: 'unknown', file_urls: [] }] : [])

    return (
        <div className="mb-4 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            {/* Top bar: collection controls */}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-5 py-2 border-b border-gray-100 bg-gray-50/60">
                <h2 className="text-sm font-semibold text-gray-900 truncate">{collectionName}</h2>
                {canUpdateCollection && onEditClick && (
                    <button type="button" onClick={onEditClick} className="inline-flex items-center gap-1 text-xs font-medium text-gray-400 hover:text-gray-700 transition-colors" title="Edit collection">
                        <PencilSquareIcon className="h-3.5 w-3.5" />
                        Edit
                    </button>
                )}
                {typeof assetCount === 'number' && (
                    <span className="text-xs text-gray-400">
                        {assetCount === 0 ? 'No assets' : `${assetCount} asset${assetCount === 1 ? '' : 's'}`}
                    </span>
                )}
                <div className="ml-auto flex items-center gap-3">
                    {collection && (
                        <PublicToggle collection={collection} publicCollectionsEnabled={publicCollectionsEnabled} onPublicChange={onPublicChange} primaryColor={primaryColor} />
                    )}
                </div>
            </div>

            {/* Campaign body — featured image LEFT, content RIGHT */}
            <div className="flex min-h-[180px]">
                {/* Left: featured image */}
                <div className="w-[min(280px,40vw)] sm:w-[300px] md:w-[340px] shrink-0 relative bg-gray-100">
                    {hasFeaturedImage ? (
                        <img
                            src={featured_image_url}
                            alt={`${campaign_name} campaign`}
                            className="absolute inset-0 w-full h-full object-cover"
                        />
                    ) : (
                        <div className="absolute inset-0 flex flex-col items-center justify-center bg-gradient-to-br from-gray-100 to-gray-200 text-gray-300">
                            <PhotoIcon className="h-8 w-8" />
                            {canUpdateCollection && (
                                <Link
                                    href={`/app/collections/${collectionId}/campaign`}
                                    className="mt-1.5 text-[10px] font-medium text-gray-400 hover:text-indigo-500 transition-colors"
                                >
                                    Add image
                                </Link>
                            )}
                        </div>
                    )}
                </div>

                {/* Right: campaign identity content */}
                <div className="flex-1 min-w-0 px-5 py-4 flex flex-col">
                    {/* Header: status + campaign link */}
                    <div className="flex items-center justify-between gap-2 mb-1">
                        <div className="flex items-center gap-2">
                            <SparklesIcon className="h-3.5 w-3.5 text-violet-500 shrink-0" />
                            <StatusBadge status={campaign_status} />
                        </div>
                        {canUpdateCollection && (
                            <Link
                                href={`/app/collections/${collectionId}/campaign`}
                                className="inline-flex items-center gap-1 text-[11px] font-medium text-gray-400 hover:text-gray-600 transition-colors"
                                title="Edit campaign identity"
                            >
                                <Cog6ToothIcon className="h-3.5 w-3.5" />
                                Edit campaign
                            </Link>
                        )}
                    </div>

                    {/* Campaign name — large, brand guidelines style */}
                    <h3
                        className="text-xl font-bold text-gray-900 leading-tight"
                        style={titleFontFamily ? { fontFamily: titleFontFamily } : undefined}
                    >
                        {campaign_name}
                    </h3>

                    {/* Goal / description */}
                    {hasGoal && (
                        <p className="mt-1 text-[13px] text-gray-500 leading-relaxed line-clamp-2">
                            {campaign_goal || campaign_description}
                        </p>
                    )}

                    {(hasColors || hasFonts || brandColors?.primary) && (
                        <div className="mt-auto pt-3 space-y-4">
                            {/* Row: campaign palette (left) + campaign typeface (right); labels above each column */}
                            {(hasColors || hasFonts) && (
                                <div className="flex flex-wrap gap-x-10 gap-y-4 items-start">
                                    {hasColors && (
                                        <div className="flex flex-col gap-1.5 shrink-0 w-full sm:w-auto">
                                            <span className="text-[8px] font-semibold uppercase tracking-widest text-gray-500">
                                                Campaign palette
                                            </span>
                                            <div className="flex flex-wrap items-end gap-1.5">
                                                <ColorTile
                                                    color={allColors[0]}
                                                    label={paletteColors.length > 0 ? 'Primary' : 'Accent'}
                                                    className="shrink-0 w-full max-w-[420px] sm:w-[min(360px,calc(100vw-20rem))] md:w-[380px]"
                                                    height="h-[80px]"
                                                />
                                                {allColors.length > 1 && allColors.slice(1).map((color, i) => {
                                                    const idx = i + 1
                                                    const isAccent = idx >= paletteColors.length
                                                    return (
                                                        <ColorTile
                                                            key={idx}
                                                            color={color}
                                                            label={isAccent ? 'Accent' : `#${idx + 1}`}
                                                            className="w-[62px]"
                                                            height="h-[62px]"
                                                        />
                                                    )
                                                })}
                                            </div>
                                        </div>
                                    )}
                                    {hasFonts && (
                                        <div className="flex flex-col gap-1.5 min-w-0">
                                            <span className="text-[8px] font-semibold uppercase tracking-widest text-gray-500">
                                                Campaign typeface
                                            </span>
                                            <div className="flex flex-wrap gap-2">
                                                {fontsForDisplay.map((fontObj, i) => {
                                                    const name = fontObj.name || 'Unknown'
                                                    const role = fontObj.role || 'primary'
                                                    return (
                                                        <div
                                                            key={`typeface-${i}`}
                                                            className="h-[72px] min-w-[132px] w-[min(200px,40vw)] max-w-full shrink-0 rounded-lg border border-gray-200 bg-gray-50 flex flex-col justify-between px-2 py-1.5"
                                                        >
                                                            <span className="text-[7px] font-bold uppercase tracking-wider text-gray-400 leading-none">
                                                                {ROLE_LABELS[role] || role}
                                                            </span>
                                                            <span
                                                                className="text-xs font-semibold text-gray-800 truncate leading-tight"
                                                                style={{ fontFamily: `'${name}', system-ui, sans-serif` }}
                                                            >
                                                                {name}
                                                            </span>
                                                            <span
                                                                className="text-sm text-gray-300 leading-none"
                                                                style={{ fontFamily: `'${name}', system-ui, sans-serif` }}
                                                            >
                                                                Aa
                                                            </span>
                                                        </div>
                                                    )
                                                })}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}

                            {brandColors?.primary && (
                                <div className="flex flex-col gap-1.5">
                                    <span className="text-[8px] font-semibold uppercase tracking-widest text-gray-500">
                                        Brand palette
                                    </span>
                                    <div className="flex flex-wrap items-end gap-1.5">
                                        <ColorTile color={brandColors.primary} label="Primary" className="w-[62px]" height="h-[62px]" />
                                        {brandColors.secondary && (
                                            <ColorTile color={brandColors.secondary} label="Secondary" className="w-[62px]" height="h-[62px]" />
                                        )}
                                        {brandColors.accent && (
                                            <ColorTile color={brandColors.accent} label="Accent" className="w-[62px]" height="h-[62px]" />
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    )
}
