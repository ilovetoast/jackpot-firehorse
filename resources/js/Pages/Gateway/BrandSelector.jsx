import { router, usePage } from '@inertiajs/react'
import { useState, useMemo, useEffect, useRef } from 'react'
import { refreshCsrfTokenFromServer } from '../../utils/csrf'
import BrandIconUnified from '../../Components/BrandIconUnified'

// ─────────────────────────────────────────────────────────────────────────────
//  WorkspacePill — restrained type badge
// ─────────────────────────────────────────────────────────────────────────────
const PILL_CFG = {
    recent:   { label: 'Recent',   cls: 'text-amber-300/70 border-amber-300/25' },
    agency:   { label: 'Agency',   cls: 'text-violet-300/60 border-violet-300/20' },
    client:   { label: 'Client',   cls: 'text-sky-300/50 border-sky-300/15' },
    default:  { label: 'Default',  cls: 'text-white/35 border-white/10' },
    internal: { label: 'Internal', cls: 'text-white/35 border-white/10' },
}

function WorkspacePill({ type }) {
    const c = PILL_CFG[type]
    if (!c) return null
    return (
        <span className={`inline-block text-[9px] font-semibold uppercase tracking-[0.18em] border rounded px-1.5 py-[2px] leading-none ${c.cls}`}>
            {c.label}
        </span>
    )
}

// ─────────────────────────────────────────────────────────────────────────────
//  WorkspaceCard — horizontal rectangle, ~280px × 100px
// ─────────────────────────────────────────────────────────────────────────────
function WorkspaceCard({ brand, onClick, onHoverStart, onHoverEnd, disabled, pill, stretch = false, compact = false }) {
    const primary = brand?.primary_color || '#94a3b8'
    const widthCls  = stretch ? 'w-full' : compact ? 'w-[210px] sm:w-[232px]' : 'w-[260px] sm:w-[288px]'
    const heightCls = compact ? 'min-h-[78px] px-3 py-2.5' : 'min-h-[96px] px-4 py-3.5'
    const iconSize  = compact ? 'sm' : 'md'

    return (
        <button
            type="button"
            aria-label={`Open ${brand.name}`}
            onClick={() => !disabled && onClick?.(brand)}
            onMouseEnter={() => !disabled && onHoverStart?.(brand)}
            onMouseLeave={() => onHoverEnd?.()}
            onFocus={() => !disabled && onHoverStart?.(brand)}
            onBlur={() => onHoverEnd?.()}
            disabled={disabled}
            className={[
                'group relative flex items-center gap-3 text-left overflow-hidden flex-shrink-0',
                'rounded-xl border',
                'transition-[transform,box-shadow,border-color,background-color] duration-300 ease-out',
                widthCls, heightCls,
                disabled
                    ? 'border-white/[0.04] bg-white/[0.02] cursor-not-allowed opacity-40'
                    : 'border-white/[0.09] bg-white/[0.04] hover:border-white/20 hover:bg-white/[0.07] hover:-translate-y-[2px] hover:shadow-[0_12px_40px_rgba(0,0,0,0.55)]',
            ].join(' ')}
        >
            <div className={`shrink-0 ${disabled ? 'grayscale opacity-50' : ''}`}>
                <BrandIconUnified brand={brand} size={iconSize} palette="brand" />
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                    <span className={`font-semibold leading-snug truncate ${compact ? 'text-xs' : 'text-sm'} ${disabled ? 'text-white/25' : 'text-white/95'}`}>
                        {brand.name}
                    </span>
                </div>
                {pill && <div className="mt-1.5"><WorkspacePill type={pill} /></div>}
                {brand.is_disabled && (
                    <p className="mt-1 text-[10px] font-semibold uppercase tracking-wide text-amber-400/65">Plan limit</p>
                )}
            </div>

            {!disabled && (
                <div
                    className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-700 pointer-events-none"
                    style={{ background: `radial-gradient(ellipse 100% 80% at 5% 50%, ${primary}22 0%, transparent 65%)` }}
                />
            )}
        </button>
    )
}

// ─────────────────────────────────────────────────────────────────────────────
//  GatewayHeroWorkspace — "Continue where you left off"
// ─────────────────────────────────────────────────────────────────────────────
function GatewayHeroWorkspace({ brand, onClick, onHoverStart, onHoverEnd, processing }) {
    const primary   = brand?.primary_color || '#7c3aed'
    const secondary = brand?.secondary_color || brand?.accent_color || primary

    return (
        <div className="w-full max-w-sm mx-auto">
            <p className="text-center text-[10px] font-semibold uppercase tracking-[0.24em] text-white/28 mb-3">
                Continue where you left off
            </p>
            <button
                type="button"
                aria-label={`Continue in ${brand.name}`}
                onClick={() => !processing && onClick?.(brand)}
                onMouseEnter={() => onHoverStart?.(brand)}
                onMouseLeave={() => onHoverEnd?.()}
                onFocus={() => onHoverStart?.(brand)}
                onBlur={() => onHoverEnd?.()}
                disabled={processing}
                className="group relative w-full flex items-center gap-4 rounded-2xl border border-white/[0.13] bg-white/[0.05]
                    px-5 py-4 text-left overflow-hidden
                    hover:border-white/25 hover:bg-white/[0.08] hover:-translate-y-0.5
                    transition-[transform,box-shadow,border-color,background-color] duration-300 ease-out"
            >
                <div className="shrink-0">
                    <BrandIconUnified brand={brand} size="xl" palette="brand" />
                </div>

                <div className="min-w-0 flex-1">
                    <div className="mb-1"><WorkspacePill type="recent" /></div>
                    <h3 className="text-base font-semibold text-white/95 leading-snug truncate">{brand.name}</h3>
                    {brand.tenant_name && (
                        <p className="text-xs text-white/38 truncate mt-0.5">{brand.tenant_name}</p>
                    )}
                </div>

                <div className="shrink-0 text-white/18 group-hover:text-white/55 transition-colors duration-300">
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </div>

                {/* Top accent line */}
                <div
                    className="absolute top-0 left-0 right-0 h-px opacity-50 group-hover:opacity-90 transition-opacity duration-300 pointer-events-none"
                    style={{ background: `linear-gradient(90deg, transparent 0%, ${primary}aa 45%, ${secondary}66 70%, transparent 100%)` }}
                />
                {/* Ambient glow */}
                <div
                    className="absolute inset-0 opacity-[0.1] group-hover:opacity-[0.22] transition-opacity duration-500 pointer-events-none"
                    style={{ background: `radial-gradient(ellipse 70% 120% at 10% 50%, ${primary} 0%, transparent 65%)` }}
                />
            </button>
        </div>
    )
}

// ─────────────────────────────────────────────────────────────────────────────
//  WorkspaceLane — one tenant row with horizontal scroll
// ─────────────────────────────────────────────────────────────────────────────
function WorkspaceLane({ group, onCardClick, onHoverStart, onHoverEnd, processing, compact }) {
    const isAgencyLane = group.type === 'agency'

    return (
        <div className="w-full">
            {/* Lane header */}
            <div className="flex items-center gap-2 mb-3">
                {group.sectionLabel && (
                    <span className={`text-[10px] font-semibold uppercase tracking-[0.2em] leading-none ${
                        isAgencyLane ? 'text-violet-400/60' : 'text-white/28'
                    }`}>
                        {group.sectionLabel}
                    </span>
                )}
                {group.sectionLabel && group.tenantName && (
                    <span className="text-white/20 text-[11px]">•</span>
                )}
                {group.tenantName && (
                    <span className="text-sm font-semibold text-white/65 tracking-tight leading-none">
                        {group.tenantName}
                    </span>
                )}
                <div className="flex-1 h-px bg-white/[0.06] ml-1" />
            </div>

            {/* Horizontally scrollable card row */}
            <div className="overflow-x-auto no-scrollbar">
                <div className="flex gap-2.5 pb-1">
                    {group.brands.map((brand) => (
                        <WorkspaceCard
                            key={brand.id}
                            brand={brand}
                            onClick={onCardClick}
                            onHoverStart={onHoverStart}
                            onHoverEnd={onHoverEnd}
                            disabled={processing || brand.is_disabled}
                            pill={brand.is_default ? 'default' : undefined}
                            compact={compact}
                        />
                    ))}
                </div>
            </div>
        </div>
    )
}

// ─────────────────────────────────────────────────────────────────────────────
//  WorkspaceQuickSwitch — ⌘K search
// ─────────────────────────────────────────────────────────────────────────────
function WorkspaceQuickSwitch({ allBrands, onSelect }) {
    const [open, setOpen] = useState(false)
    const [query, setQuery] = useState('')
    const inputRef = useRef(null)

    const results = useMemo(() => {
        if (!query.trim()) return allBrands.slice(0, 10)
        const q = query.toLowerCase()
        return allBrands
            .filter(b =>
                String(b.name ?? '').toLowerCase().includes(q) ||
                String(b.tenant_name ?? '').toLowerCase().includes(q),
            )
            .slice(0, 10)
    }, [query, allBrands])

    useEffect(() => {
        if (open) setTimeout(() => inputRef.current?.focus(), 40)
        else setQuery('')
    }, [open])

    useEffect(() => {
        const handler = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); setOpen(v => !v) }
            if (e.key === 'Escape') setOpen(false)
        }
        document.addEventListener('keydown', handler)
        return () => document.removeEventListener('keydown', handler)
    }, [])

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="flex items-center gap-1.5 rounded-lg border border-white/[0.08] bg-white/[0.03]
                    px-3 py-1.5 text-[11px] text-white/35
                    hover:border-white/[0.16] hover:bg-white/[0.06] hover:text-white/60
                    transition-all duration-200"
            >
                <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <span>Search workspaces</span>
                <kbd className="ml-0.5 font-mono text-[10px] text-white/18">⌘K</kbd>
            </button>

            {open && (
                <>
                    <div
                        className="fixed inset-0 z-40 bg-black/65 backdrop-blur-sm"
                        onClick={() => setOpen(false)}
                        aria-hidden
                    />
                    <div className="fixed inset-0 z-50 flex items-start justify-center pt-[18vh] px-4 pointer-events-none">
                        <div className="relative w-full max-w-md overflow-hidden rounded-2xl border border-white/[0.13] bg-[#0d0b16] shadow-2xl pointer-events-auto">
                            <div className="flex items-center gap-3 border-b border-white/[0.07] px-4 py-3.5">
                                <svg className="h-4 w-4 shrink-0 text-white/35" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                </svg>
                                <input
                                    ref={inputRef}
                                    type="text"
                                    value={query}
                                    onChange={e => setQuery(e.target.value)}
                                    placeholder="Search workspaces…"
                                    className="flex-1 bg-transparent text-sm text-white placeholder:text-white/25 outline-none"
                                />
                                <kbd className="rounded border border-white/[0.1] px-1.5 py-0.5 font-mono text-[11px] text-white/20">esc</kbd>
                            </div>

                            <div className="max-h-72 overflow-y-auto py-2">
                                {results.length === 0 ? (
                                    <p className="px-4 py-6 text-center text-sm text-white/30">No workspaces found</p>
                                ) : results.map((brand) => (
                                    <button
                                        key={brand.id}
                                        type="button"
                                        onClick={() => { onSelect(brand); setOpen(false) }}
                                        className="group w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-white/[0.05] transition-colors"
                                    >
                                        <BrandIconUnified brand={brand} size="sm" palette="brand" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium text-white/85">{brand.name}</p>
                                            {brand.tenant_name && (
                                                <p className="truncate text-[11px] text-white/35">{brand.tenant_name}</p>
                                            )}
                                        </div>
                                        <svg className="h-3.5 w-3.5 shrink-0 text-white/18 group-hover:text-white/50 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                        </svg>
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                </>
            )}
        </>
    )
}

// ─────────────────────────────────────────────────────────────────────────────
//  Layout mode detection
//  'single'     — one tenant, responsive grid
//  'lanes'      — multi-tenant, horizontal lanes
//  'enterprise' — many brands, compact lanes + search prominent
// ─────────────────────────────────────────────────────────────────────────────
function detectLayoutMode(groups, totalBrands, isAllWorkspaces) {
    if (!isAllWorkspaces || groups.length <= 1) return 'single'
    if (totalBrands > 16 || groups.length > 5) return 'enterprise'
    return 'lanes'
}

// ─────────────────────────────────────────────────────────────────────────────
//  Helpers
// ─────────────────────────────────────────────────────────────────────────────
function filterHiddenBrand(rows, activeBrandId) {
    if (activeBrandId == null) return rows
    const id = Number(activeBrandId)
    return rows.filter((b) => Number(b.id) !== id)
}

// ─────────────────────────────────────────────────────────────────────────────
//  BrandSelector — main export
//
//  Three adaptive layout modes:
//    MODE 1 — single tenant  → centred responsive brand grid
//    MODE 2 — agency / multi → horizontal tenant lanes (primary design)
//    MODE 3 — enterprise     → compact lanes + ⌘K search
// ─────────────────────────────────────────────────────────────────────────────
export default function BrandSelector({
    brands,
    brandPickerGroups = null,
    tenant,
    brandPickerScope = 'tenant',
    tenantMemberWithoutBrands = false,
    variant = 'page',
    activeBrandId = null,
    onAmbientHover,
}) {
    const { theme } = usePage().props
    const [processing, setProcessing] = useState(false)

    useEffect(() => () => onAmbientHover?.(null), [onAmbientHover])

    const isPage        = variant === 'page'
    const list          = Array.isArray(brands) ? brands : []
    const isEmpty       = list.length === 0
    const isAllWorkspaces  = brandPickerScope === 'all_workspaces'
    const isAgencyGrouped  = Array.isArray(brandPickerGroups) && brandPickerGroups.length > 0

    // Hero brand — the last-active workspace shown at the top ("Continue where you left off").
    // Only meaningful in all_workspaces mode; single-tenant pickers don't need it.
    const activeBrand = useMemo(() => {
        if (activeBrandId == null || !isAllWorkspaces) return null
        return list.find(b => Number(b.id) === Number(activeBrandId)) ?? null
    }, [list, activeBrandId, isAllWorkspaces])

    const displayBrands = useMemo(() => filterHiddenBrand(list, activeBrandId), [list, activeBrandId])
    const isDisplayEmpty = displayBrands.length === 0
    const hasDisabledBrands = displayBrands.some(b => b.is_disabled)

    // Build groups — prefer pre-computed agency groups, otherwise derive from flat brand list
    const groups = useMemo(() => {
        if (isAgencyGrouped) {
            return brandPickerGroups
                .map(g => ({
                    sectionLabel: g.section_label ?? null,
                    type:         g.type ?? 'company',
                    tenantId:     Number(g.tenant_id ?? 0),
                    tenantName:   String(g.tenant_name ?? 'Company'),
                    brands:       filterHiddenBrand(Array.isArray(g.brands) ? g.brands : [], activeBrandId),
                }))
                .filter(g => g.brands.length > 0)
        }

        const map = new Map()
        for (const b of displayBrands) {
            const tid   = Number(b.tenant_id ?? tenant?.id ?? 0)
            const tname = String(b.tenant_name ?? tenant?.name ?? 'Company')
            if (!map.has(tid)) {
                map.set(tid, { sectionLabel: null, type: 'company', tenantId: tid, tenantName: tname, brands: [] })
            }
            map.get(tid).brands.push(b)
        }
        const grouped = [...map.values()].map(g => ({
            ...g,
            brands: [...g.brands].sort((a, b) =>
                String(a.name ?? '').localeCompare(String(b.name ?? ''), undefined, { sensitivity: 'base' }),
            ),
        }))
        grouped.sort((a, b) =>
            a.tenantName.localeCompare(b.tenantName, undefined, { sensitivity: 'base' }),
        )
        return grouped
    }, [brandPickerGroups, displayBrands, tenant, isAgencyGrouped, activeBrandId])

    const layoutMode  = detectLayoutMode(groups, displayBrands.length, isAllWorkspaces)
    const compact   = layoutMode === 'enterprise'
    const showHero  = Boolean(activeBrand) && !isDisplayEmpty && isPage

    // ── Event handlers ────────────────────────────────────────────────────────
    const handleSelect = async (brand) => {
        if (processing || brand.is_disabled) return
        setProcessing(true)
        try { await refreshCsrfTokenFromServer() } catch { /* ok */ }
        router.post('/gateway/select-brand', { brand_id: brand.id }, {
            onFinish: () => setProcessing(false),
        })
    }

    const handleHoverStart = (brand) => {
        if (!isPage || !onAmbientHover) return
        onAmbientHover({
            primary:   brand.primary_color  || '#94a3b8',
            secondary: brand.secondary_color || brand.accent_color || brand.primary_color || '#94a3b8',
        })
    }

    const handleHoverEnd = () => {
        if (!isPage || !onAmbientHover) return
        onAmbientHover(null)
    }

    // ── Copy ──────────────────────────────────────────────────────────────────
    const headingTitle = isAllWorkspaces
        ? 'Your workspaces'
        : (tenant?.name || theme?.name || 'Select brand')

    const headingSubtitle = (() => {
        if (isEmpty && tenantMemberWithoutBrands) return null
        if (isAllWorkspaces && isAgencyGrouped)   return 'Agency workspace and managed clients are grouped below.'
        if (isAllWorkspaces && groups.length > 1) return 'Companies are grouped below. Choose a brand to enter.'
        if (isAllWorkspaces)                       return 'Choose a brand to enter.'
        return null
    })()

    // ── Modal/compact variant ─────────────────────────────────────────────────
    if (!isPage) {
        return (
            <div className="w-full space-y-1">
                {displayBrands.map(brand => (
                    <button
                        key={brand.id}
                        type="button"
                        onClick={() => handleSelect(brand)}
                        disabled={processing || brand.is_disabled}
                        className="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/[0.06] transition-colors text-left disabled:opacity-40"
                    >
                        <BrandIconUnified brand={brand} size="sm" palette="brand" />
                        <span className="text-sm font-medium text-white/85 truncate">{brand.name}</span>
                    </button>
                ))}
            </div>
        )
    }

    // ── Page layout ───────────────────────────────────────────────────────────
    const containerMax = layoutMode === 'single' ? 'max-w-2xl' : 'w-full max-w-5xl'

    return (
        <div className={`w-full animate-fade-in ${containerMax}`} style={{ animationDuration: '500ms' }}>
            {/* ── Heading ─────────────────────────────────────────────────── */}
            <div className={`text-center ${showHero || layoutMode !== 'single' ? 'mb-5 sm:mb-6' : 'mb-8 sm:mb-10'}`}>
                <h1 className={`font-display font-semibold tracking-tight leading-tight text-white/95 mb-1.5 ${
                    layoutMode === 'single' ? 'text-3xl sm:text-4xl md:text-5xl' : 'text-2xl sm:text-3xl'
                }`}>
                    {headingTitle}
                </h1>
                {headingSubtitle && (
                    <p className="text-sm text-white/40 max-w-lg mx-auto leading-relaxed">{headingSubtitle}</p>
                )}
            </div>

            {/* ── Empty / error states ─────────────────────────────────────── */}
            {isEmpty && tenantMemberWithoutBrands && (
                <div className="mb-8 max-w-lg mx-auto rounded-xl border border-white/10 bg-white/[0.04] px-5 py-5 text-sm leading-relaxed">
                    <p className="text-white/90 font-medium">
                        You&apos;re a member of{' '}
                        <span className="text-white">{tenant?.name || 'this company'}</span>
                        , but no brands are assigned to your account yet.
                    </p>
                    <p className="mt-2.5 text-white/50">
                        Ask whoever manages your team to open{' '}
                        <strong className="text-white/70">Company → Team</strong>{' '}
                        and assign you to the right brand(s).
                    </p>
                </div>
            )}

            {isEmpty && !tenantMemberWithoutBrands && (
                <p className="mb-8 text-center text-sm text-white/38">
                    No brands are available right now.
                </p>
            )}

            {hasDisabledBrands && (
                <div className="mb-4 px-4 py-2.5 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-200/75 text-xs text-center">
                    Some brands are unavailable on the current plan.
                </div>
            )}

            {/* ── Hero workspace ───────────────────────────────────────────── */}
            {showHero && (
                <div className="mb-7 sm:mb-9">
                    <GatewayHeroWorkspace
                        brand={activeBrand}
                        onClick={handleSelect}
                        onHoverStart={handleHoverStart}
                        onHoverEnd={handleHoverEnd}
                        processing={processing}
                    />
                </div>
            )}

            {/* ── "All workspaces" divider label when hero is shown ────────── */}
            {!isDisplayEmpty && showHero && (
                <div className="mb-4 sm:mb-5">
                    <span className="text-[10px] font-semibold uppercase tracking-[0.22em] text-white/22">
                        All workspaces
                    </span>
                </div>
            )}

            {/* ── Main layout ──────────────────────────────────────────────── */}
            {!isDisplayEmpty && (
                layoutMode === 'single' ? (
                    /* MODE 1 — single-tenant responsive grid */
                    <div className="space-y-4">
                        {groups.map((group, gi) => (
                            <div key={`${group.tenantId}-${gi}`}>
                                {isAllWorkspaces && group.tenantName && (
                                    <div className="flex items-center gap-2 mb-3">
                                        <span className="text-xs font-semibold text-white/50 tracking-tight">
                                            {group.tenantName}
                                        </span>
                                        <div className="flex-1 h-px bg-white/[0.06]" />
                                    </div>
                                )}
                                <div className="grid grid-cols-1 min-[420px]:grid-cols-2 lg:grid-cols-3 gap-3">
                                    {group.brands.map(brand => (
                                        <WorkspaceCard
                                            key={brand.id}
                                            brand={brand}
                                            onClick={handleSelect}
                                            onHoverStart={handleHoverStart}
                                            onHoverEnd={handleHoverEnd}
                                            disabled={processing || brand.is_disabled}
                                            pill={brand.is_default ? 'default' : undefined}
                                            stretch
                                        />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    /* MODE 2 & 3 — horizontal tenant lanes */
                    <div className="space-y-5 sm:space-y-7">
                        {groups.map((group, gi) => (
                            <WorkspaceLane
                                key={`${group.type}-${group.tenantId}-${gi}`}
                                group={group}
                                onCardClick={handleSelect}
                                onHoverStart={handleHoverStart}
                                onHoverEnd={handleHoverEnd}
                                processing={processing}
                                compact={compact}
                            />
                        ))}
                    </div>
                )
            )}
        </div>
    )
}
