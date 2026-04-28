/**
 * Cinematic Overview — processing strip. Uses **brand** atmosphere (not Jackpot purple surfaces).
 * @see brandWorkspaceTokens.js (CINEMATIC vs WORKBENCH)
 */
import { usePage } from '@inertiajs/react'
import { hexToRgba } from '../../utils/colorUtils'

function safeBrandHex(brand) {
    const raw = brand?.primary_color
    if (!raw || typeof raw !== 'string') return null
    const t = raw.trim()
    if (!t) return null
    if (t.startsWith('#') && (t.length === 7 || t.length === 4)) return t
    if (/^[0-9a-fA-F]{6}$/i.test(t)) return `#${t}`
    if (/^[0-9a-fA-F]{3}$/i.test(t)) return `#${t}`
    return null
}

export default function InsightsLoading() {
    const brand = usePage().props?.auth?.activeBrand
    const hex = safeBrandHex(brand)
    const wash = hex ? hexToRgba(hex, 0.1) : 'rgba(255, 255, 255, 0.06)'
    const ping = hex ? hexToRgba(hex, 0.28) : 'rgba(255, 255, 255, 0.2)'
    const dot = hex || '#ffffff'
    const dotGlow = hex ? hexToRgba(hex, 0.45) : 'rgba(255, 255, 255, 0.35)'

    return (
        <div className="relative rounded-2xl bg-white/5 border border-white/10 p-6 overflow-hidden">
            <div
                className="absolute inset-0 animate-pulse bg-gradient-to-r via-transparent to-transparent"
                style={{
                    backgroundImage: `linear-gradient(to right, ${wash}, transparent, ${wash})`,
                }}
            />

            <div className="relative">
                <div className="mb-4 flex items-center" aria-hidden>
                    <span className="relative inline-flex h-3 w-3">
                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full" style={{ backgroundColor: ping }} />
                        <span
                            className="relative inline-flex h-3 w-3 rounded-full ring-2 ring-white/20"
                            style={{
                                backgroundColor: dot,
                                boxShadow: `0 0 12px ${dotGlow}`,
                            }}
                        />
                    </span>
                </div>

                <div className="text-white font-medium">Building insights...</div>

                <div className="text-white/60 text-sm mt-1">
                    Analyzing your assets and brand patterns
                </div>
            </div>
        </div>
    )
}
