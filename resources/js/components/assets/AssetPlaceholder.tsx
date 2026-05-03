/**
 * Jackpot-branded DAM grid placeholder: seeded symbol mosaic, CSS-only motion, file-type center mark.
 * Used by {@link ../../Components/MosaicProcessingPlaceholder.jsx}.
 */
import { memo, useMemo, type CSSProperties, type ReactNode } from 'react'
import {
    ArchiveBoxIcon,
    CameraIcon,
    CubeIcon,
    DocumentIcon,
    MusicalNoteIcon,
    PhotoIcon,
    VideoCameraIcon,
} from '@heroicons/react/24/outline'
const JP_FALLBACK = '#6366f1'

function sanitizeHexColor(value: unknown, fallback = JP_FALLBACK): string {
    const raw = String(value ?? '').trim()
    if (!raw) return fallback
    let s = raw.startsWith('#') ? raw.slice(1) : raw
    if (!/^[0-9a-fA-F]{3}$/.test(s) && !/^[0-9a-fA-F]{6}$/.test(s)) {
        return fallback
    }
    if (s.length === 3) {
        s = s[0] + s[0] + s[1] + s[1] + s[2] + s[2]
    }
    return `#${s.toLowerCase()}`
}

export const JP_PART_SYMBOLS = [
    '/jp-parts/seven-slot.svg',
    '/jp-parts/cherry-slot.svg',
    '/jp-parts/diamond-slot.svg',
    '/jp-parts/t-slot.svg',
    '/jp-parts/o-slot.svg',
    '/jp-parts/p-slot.svg',
    '/jp-parts/k-slot.svg',
    '/jp-parts/c-slot.svg',
    '/jp-parts/slot-j.svg',
    '/jp-parts/slot-a.svg',
] as const

export type AssetPlaceholderFileType = 'image' | 'video' | 'pdf' | 'zip' | 'audio' | '3d' | 'raw' | 'unknown'

export type AssetPlaceholderProps = {
    status: 'processing' | 'ready' | 'unavailable' | 'failed'
    fileType?: AssetPlaceholderFileType
    brandColor?: string
    /** Primary footer line; required for meaningful processing UX (callers pass headline). */
    label?: string
    /** Secondary footer line (e.g. helper copy). */
    footerSubtext?: string
    seed?: string | number
    /** Optional center override (e.g. video play glyph). */
    centerSlot?: ReactNode
    /** Shown for unavailable (e.g. PDF, ZIP). */
    extensionLabel?: string
    /** Top-right pill (short badge). */
    pill?: { short: string; tone: 'danger' | 'warning' | 'processing' | 'neutral' }
    className?: string
}

function hashSeed(input: string | number | undefined): number {
    const str = input == null ? 'jackpot' : String(input)
    let h = 2166136261 >>> 0
    for (let i = 0; i < str.length; i++) {
        h ^= str.charCodeAt(i)
        h = Math.imul(h, 16777619) >>> 0
    }
    return h >>> 0
}

/** Deterministic 0..1 — do not use Math.random(). */
function mulberry32(seed: number): () => number {
    let a = seed >>> 0
    return () => {
        a += 0x6d2b79f5
        let t = a
        t = Math.imul(t ^ (t >>> 15), t | 1)
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296
    }
}

function hexToRgb(hex: string): { r: number; g: number; b: number } {
    const raw = hex.replace('#', '').trim()
    const v = raw.length === 3 ? raw.split('').map((c) => c + c).join('') : raw
    if (v.length !== 6) return { r: 99, g: 102, b: 241 }
    return {
        r: parseInt(v.slice(0, 2), 16),
        g: parseInt(v.slice(2, 4), 16),
        b: parseInt(v.slice(4, 6), 16),
    }
}

function rgbToHsl(r: number, g: number, b: number): { h: number; s: number; l: number } {
    r /= 255
    g /= 255
    b /= 255
    const max = Math.max(r, g, b)
    const min = Math.min(r, g, b)
    const d = max - min
    const l = (max + min) / 2
    let h = 0
    let s = 0
    if (d > 1e-6) {
        s = l > 0.5 ? d / (2 - max - min) : d / (max + min)
        switch (max) {
            case r:
                h = ((g - b) / d + (g < b ? 6 : 0)) / 6
                break
            case g:
                h = ((b - r) / d + 2) / 6
                break
            default:
                h = ((r - g) / d + 4) / 6
        }
    }
    return { h: h * 360, s: s * 100, l: l * 100 }
}

function buildBrandShades(hex: string): string[] {
    const safe = sanitizeHexColor(hex, JP_FALLBACK)
    const { r, g, b } = hexToRgb(safe)
    const { h, s, l } = rgbToHsl(r, g, b)
    const shades: string[] = []
    for (let i = 0; i < 8; i++) {
        const dh = (i % 3) * 4 - 4
        const dl = -6 - i * 2.2
        const ds = -4 + (i % 2) * 3
        const hh = (h + dh + 360) % 360
        const ss = Math.min(52, Math.max(28, s + ds))
        const ll = Math.min(24, Math.max(8, l + dl))
        shades.push(`hsla(${hh.toFixed(1)}, ${ss.toFixed(1)}%, ${ll.toFixed(1)}%, 0.92)`)
    }
    return shades
}

type SymbolLayout = {
    key: string
    src: string
    leftPct: number
    topPct: number
    sizePct: number
    rotateDeg: number
    opacity: number
    delayMs: number
    shade: string
    scale: number
}

const SYMBOL_COUNT = 24

function buildSymbolLayout(seedKey: string | number | undefined): SymbolLayout[] {
    const seed0 = hashSeed(seedKey)
    const rnd = mulberry32(seed0)
    const layouts: SymbolLayout[] = []
    const n = JP_PART_SYMBOLS.length
    for (let i = 0; i < SYMBOL_COUNT; i++) {
        const src = JP_PART_SYMBOLS[Math.floor(rnd() * n)] ?? JP_PART_SYMBOLS[0]
        layouts.push({
            key: `${seed0}-sym-${i}`,
            src,
            leftPct: rnd() * 78 + 2,
            topPct: rnd() * 78 + 2,
            sizePct: 8 + rnd() * 6,
            rotateDeg: (rnd() - 0.5) * 34,
            opacity: 0.05 + rnd() * 0.07,
            delayMs: Math.floor(rnd() * 2400),
            shade: '', // filled in useMemo with shades[i%8]
            scale: 0.82 + rnd() * 0.38,
        })
    }
    return layouts
}

function inferFileTypeFromMimeExt(mime: string, ext: string): AssetPlaceholderFileType {
    const m = mime.toLowerCase()
    const e = ext.toLowerCase().replace(/^\./, '')
    if (m.startsWith('video/')) return 'video'
    if (m.startsWith('audio/')) return 'audio'
    if (m === 'application/pdf' || e === 'pdf') return 'pdf'
    if (m.includes('zip') || e === 'zip' || e === 'rar' || e === '7z') return 'zip'
    if (['glb', 'gltf', 'obj', 'fbx', 'stl', 'usdz'].includes(e)) return '3d'
    if (
        ['cr2', 'cr3', 'nef', 'arw', 'raf', 'orf', 'rw2', 'dng', 'raw', 'srw', 'pef', '3fr'].includes(e) ||
        m.includes('x-raw') ||
        m.includes('dng')
    ) {
        return 'raw'
    }
    if (m.startsWith('image/')) return 'image'
    return 'unknown'
}

export function inferAssetPlaceholderFileType(asset: {
    mime_type?: string | null
    file_extension?: string | null
    original_filename?: string | null
} | null): AssetPlaceholderFileType {
    if (!asset) return 'unknown'
    const mime = String(asset.mime_type || '')
    const ext =
        String(asset.file_extension || '')
            .replace(/^\./, '')
            .toLowerCase() ||
        String(asset.original_filename || '')
            .split('.')
            .pop()
            ?.toLowerCase() ||
        ''
    return inferFileTypeFromMimeExt(mime, ext)
}

function CenterGlyph({ fileType }: { fileType: AssetPlaceholderFileType }) {
    const cls = 'h-11 w-11 text-white/90 drop-shadow-[0_2px_12px_rgba(0,0,0,0.45)]'
    switch (fileType) {
        case 'image':
            return <PhotoIcon className={cls} aria-hidden />
        case 'video':
            return <VideoCameraIcon className={cls} aria-hidden />
        case 'pdf':
            return <DocumentIcon className={cls} aria-hidden />
        case 'zip':
            return <ArchiveBoxIcon className={cls} aria-hidden />
        case 'audio':
            return <MusicalNoteIcon className={cls} aria-hidden />
        case '3d':
            return <CubeIcon className={cls} aria-hidden />
        case 'raw':
            return <CameraIcon className={cls} aria-hidden />
        default:
            return <DocumentIcon className={cls} aria-hidden />
    }
}

function pillClass(tone: 'danger' | 'warning' | 'processing' | 'neutral') {
    switch (tone) {
        case 'danger':
            return 'bg-red-600/95 text-white'
        case 'warning':
            return 'bg-amber-400/95 text-amber-950'
        case 'processing':
            return 'bg-white/14 text-white/95 backdrop-blur-[2px]'
        default:
            return 'bg-white/12 text-white/90 backdrop-blur-[2px]'
    }
}

function ringClassForStatus(status: AssetPlaceholderProps['status']) {
    if (status === 'failed') return 'ring-1 ring-red-400/35'
    if (status === 'unavailable') return 'ring-1 ring-amber-300/30'
    return 'ring-1 ring-white/12'
}

export const AssetPlaceholder = memo(function AssetPlaceholder({
    status,
    fileType = 'unknown',
    brandColor,
    label,
    footerSubtext,
    seed,
    centerSlot,
    extensionLabel,
    pill,
    className = '',
}: AssetPlaceholderProps) {
    if (status === 'ready') {
        return null
    }

    const brandHex = sanitizeHexColor(brandColor ?? JP_FALLBACK, JP_FALLBACK)
    const shades = useMemo(() => buildBrandShades(brandHex), [brandHex])
    const seedKey = seed ?? 'asset'
    const baseLayouts = useMemo(() => buildSymbolLayout(seedKey), [seedKey])
    const layouts = useMemo(
        () =>
            baseLayouts.map((cell, i) => ({
                ...cell,
                shade: shades[i % shades.length] ?? shades[0],
            })),
        [baseLayouts, shades],
    )

    const processing = status === 'processing'
    const failed = status === 'failed'
    const unavailable = status === 'unavailable'

    const primaryFooter =
        label?.trim() ||
        (processing ? 'Creating preview…' : unavailable ? 'Preview unavailable' : 'Failed to generate preview')

    const gradientStyle = useMemo(
        () =>
            ({
                background: `linear-gradient(155deg, ${shades[4]} 0%, hsla(0,0%,4%,0.97) 48%, hsla(0,0%,2%,1) 100%)`,
            }) satisfies CSSProperties,
        [shades],
    )

    return (
        <div
            className={`jp-mosaic-processing-root jp-asset-processing-placeholder group relative flex h-full w-full min-h-0 flex-col overflow-hidden rounded-[inherit] shadow-[inset_0_1px_0_rgba(255,255,255,0.05)] transition-[filter,opacity] duration-300 hover:brightness-[1.03] ${ringClassForStatus(status)} ${
                failed ? 'saturate-[0.72]' : ''
            } ${className}`.trim()}
            style={gradientStyle}
            role="img"
            aria-label={`${primaryFooter}${footerSubtext ? `. ${footerSubtext}` : ''}`}
        >
            {failed ? (
                <div
                    className="pointer-events-none absolute inset-0 z-[1] rounded-[inherit] bg-rose-950/[0.07]"
                    aria-hidden
                />
            ) : null}

            <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden rounded-[inherit]" aria-hidden>
                {layouts.map((sym) => (
                    <span
                        key={sym.key}
                        className="absolute flex items-center justify-center"
                        style={{
                            left: `${sym.leftPct.toFixed(2)}%`,
                            top: `${sym.topPct.toFixed(2)}%`,
                            width: `${sym.sizePct.toFixed(2)}%`,
                            height: `${sym.sizePct.toFixed(2)}%`,
                            opacity: sym.opacity,
                            transform: `rotate(${sym.rotateDeg.toFixed(2)}deg) scale(${sym.scale.toFixed(3)})`,
                        }}
                    >
                        <span
                            className={`relative block h-full w-full will-change-[filter] ${
                                processing ? 'jp-ph-symbol' : 'jp-ph-symbol-static'
                            }`}
                            style={{
                                ['--jp-ph-delay' as string]: `${sym.delayMs}ms`,
                                background: `radial-gradient(circle at 30% 30%, ${sym.shade}, transparent 65%)`,
                                mixBlendMode: 'screen',
                            }}
                        >
                            <img
                                src={sym.src}
                                alt=""
                                className="h-full w-full object-contain opacity-[0.95]"
                                draggable={false}
                                loading="lazy"
                            />
                        </span>
                    </span>
                ))}
            </div>

            <div
                className="pointer-events-none absolute inset-0 z-[1] rounded-[inherit] bg-gradient-to-b from-white/[0.05] via-transparent to-black/[0.42]"
                aria-hidden
            />

            <div
                className="pointer-events-none absolute inset-0 z-[1] opacity-[0.035] mix-blend-overlay"
                style={{
                    backgroundImage: `url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E")`,
                }}
                aria-hidden
            />

            {pill?.short ? (
                <span
                    className={`pointer-events-none absolute right-2 top-2 z-[4] max-w-[calc(100%-0.75rem)] truncate rounded-md px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide shadow-sm ring-1 ring-black/25 ${pillClass(pill.tone)}`}
                    title={pill.short}
                >
                    {pill.short}
                </span>
            ) : null}

            <div className="relative z-[2] flex min-h-0 flex-1 flex-col items-center justify-center px-2 pb-10 pt-2">
                {centerSlot ? (
                    <div className="flex flex-col items-center gap-2">{centerSlot}</div>
                ) : (
                    <CenterGlyph fileType={fileType} />
                )}
            </div>

            <div className="absolute bottom-0 left-0 right-0 z-[3] flex min-h-[2.25rem] flex-col justify-center border-t border-white/[0.08] bg-black/55 px-2 py-1.5 text-center backdrop-blur-md">
                <p className="text-[10px] font-semibold uppercase leading-tight tracking-wide text-white/95">{primaryFooter}</p>
                {footerSubtext ? (
                    <p className="mt-0.5 line-clamp-2 text-[9px] font-medium leading-snug text-white/65">{footerSubtext}</p>
                ) : null}
                {unavailable && extensionLabel ? (
                    <p className="mt-0.5 font-mono text-[10px] font-semibold uppercase tracking-wider text-white/75">
                        {extensionLabel}
                    </p>
                ) : null}
            </div>
        </div>
    )
})

export default AssetPlaceholder
