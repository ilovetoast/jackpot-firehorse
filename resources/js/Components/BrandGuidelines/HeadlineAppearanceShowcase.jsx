/**
 * Visual micro-previews for headline appearance feature IDs on published brand guidelines.
 */

function PreviewLeadingAccent({ isTextured, primaryColor }) {
    return (
        <div className={`flex items-baseline gap-1 ${isTextured ? 'text-white/90' : 'text-gray-900'}`}>
            <span className="opacity-70" style={{ color: primaryColor }}>
                —
            </span>
            <span className="text-lg font-semibold tracking-tight">Section title</span>
        </div>
    )
}

function PreviewAllCaps({ isTextured }) {
    return (
        <span className={`text-lg font-bold tracking-wide ${isTextured ? 'text-white/90' : 'text-gray-900'}`} style={{ fontVariant: 'all-small-caps' }}>
            DISPLAY HEADLINE
        </span>
    )
}

function PreviewTitleCase({ isTextured }) {
    return <span className={`text-lg font-semibold ${isTextured ? 'text-white/90' : 'text-gray-900'}`}>Title Case Headline</span>
}

function PreviewSentenceCase({ isTextured }) {
    return <span className={`text-lg font-medium ${isTextured ? 'text-white/85' : 'text-gray-800'}`}>Headline written like a sentence.</span>
}

function PreviewDistinctFont({ isTextured }) {
    return (
        <div className="space-y-1">
            <span className={`block text-lg font-bold ${isTextured ? 'text-white/90' : 'text-gray-900'}`} style={{ fontFamily: 'Georgia, serif' }}>
                Display
            </span>
            <span className={`block text-sm ${isTextured ? 'text-white/55' : 'text-gray-600'}`} style={{ fontFamily: 'system-ui, sans-serif' }}>
                Body text stays neutral.
            </span>
        </div>
    )
}

function PreviewContainer({ isTextured, primaryColor }) {
    return (
        <div
            className={`inline-block px-3 py-1.5 rounded-lg text-sm font-semibold ${isTextured ? 'text-white/95' : 'text-gray-900'}`}
            style={{
                border: `1px solid ${isTextured ? 'rgba(255,255,255,0.2)' : 'rgba(0,0,0,0.12)'}`,
                background: isTextured ? `${primaryColor}33` : `${primaryColor}18`,
            }}
        >
            Headline in a container
        </div>
    )
}

function PreviewBorderRule({ isTextured, primaryColor }) {
    return (
        <div>
            <span className={`text-lg font-semibold ${isTextured ? 'text-white/90' : 'text-gray-900'}`}>Underlined headline</span>
            <div className="h-px mt-1 w-32" style={{ backgroundColor: primaryColor }} />
        </div>
    )
}

function PreviewTrackingWide({ isTextured }) {
    return (
        <span className={`text-base font-semibold uppercase ${isTextured ? 'text-white/85' : 'text-gray-800'}`} style={{ letterSpacing: '0.25em' }}>
            Wide
        </span>
    )
}

function PreviewTrackingTight({ isTextured }) {
    return (
        <span className={`text-xl font-bold uppercase ${isTextured ? 'text-white/90' : 'text-gray-900'}`} style={{ letterSpacing: '-0.04em' }}>
            TIGHT
        </span>
    )
}

const PREVIEW_BY_ID = {
    leading_accent: PreviewLeadingAccent,
    all_caps: PreviewAllCaps,
    title_case: PreviewTitleCase,
    sentence_case: PreviewSentenceCase,
    headline_font_distinct: PreviewDistinctFont,
    container_shape: PreviewContainer,
    border_or_rule: PreviewBorderRule,
    tracking_wide: PreviewTrackingWide,
    tracking_tight: PreviewTrackingTight,
}

export default function HeadlineAppearanceShowcase({
    featureIds = [],
    catalog = [],
    primaryColor = '#6366f1',
    isTextured = false,
}) {
    const ids = Array.isArray(featureIds) ? featureIds : []
    if (ids.length === 0) return null

    const byId = Object.fromEntries((catalog || []).map((c) => [c.id, c]))

    return (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-6">
            {ids.map((id) => {
                const meta = byId[id]
                const Preview = PREVIEW_BY_ID[id]
                if (!Preview) return null
                return (
                    <div
                        key={id}
                        className={`rounded-xl p-4 border ${isTextured ? 'border-white/10 bg-white/[0.04]' : 'border-gray-200 bg-white/80'}`}
                    >
                        <p className={`text-[10px] font-semibold uppercase tracking-wider mb-3 ${isTextured ? 'text-white/45' : 'text-gray-400'}`}>
                            {meta?.label || id}
                        </p>
                        <div className="min-h-[3.5rem] flex items-center">
                            <Preview isTextured={isTextured} primaryColor={primaryColor} />
                        </div>
                        {meta?.description && (
                            <p className={`mt-2 text-xs leading-snug ${isTextured ? 'text-white/40' : 'text-gray-500'}`}>{meta.description}</p>
                        )}
                    </div>
                )
            })}
        </div>
    )
}
