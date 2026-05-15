import { router, usePage } from '@inertiajs/react'
import { useState, useMemo } from 'react'
import { refreshCsrfTokenFromServer } from '../../utils/csrf'
import BrandIconUnified from '../../Components/BrandIconUnified'
import { getBrandLogoForSurface } from '../../utils/brandLogo'

function BrandLogo({ brand, disabled, compact = false }) {
    const [imgError, setImgError] = useState(false)
    /** Gateway is a dark cinematic surface — prefer the dark variant, fall back to primary. */
    const logoSrc = getBrandLogoForSurface(brand, 'dark')
    const imgClass = compact
        ? 'h-7 w-auto max-w-[104px] sm:max-w-[120px] object-contain transition-all'
        : 'h-9 w-auto max-w-[140px] sm:h-10 sm:max-w-full object-contain transition-all'

    if (logoSrc && !imgError) {
        return (
            <img
                src={logoSrc}
                alt=""
                className={`${imgClass} ${disabled ? 'grayscale opacity-40' : ''}`}
                onError={() => setImgError(true)}
            />
        )
    }

    return (
        <div className={disabled ? 'grayscale opacity-40' : ''}>
            <BrandIconUnified brand={brand} size={compact ? 'md' : 'lg'} />
        </div>
    )
}

/**
 * @param {'page' | 'modal'} [variant='page'] — `modal`: single-column list and compact headings for BrandSwitchModal.
 */
export default function BrandSelector({
    brands,
    tenant,
    brandPickerScope = 'tenant',
    tenantMemberWithoutBrands = false,
    variant = 'page',
}) {
    const { theme } = usePage().props
    const [processing, setProcessing] = useState(false)

    const isPage = variant === 'page'

    const list = Array.isArray(brands) ? brands : []
    const isEmpty = list.length === 0
    const hasDisabledBrands = list.some((b) => b.is_disabled)
    const isAllWorkspaces = brandPickerScope === 'all_workspaces'
    const uniqueTenantCount = useMemo(() => {
        const ids = new Set(list.map((b) => b.tenant_id).filter(Boolean))
        return ids.size
    }, [list])

    const headingTitle = (() => {
        if (isAllWorkspaces) {
            return 'Your workspaces'
        }
        return tenant?.name || theme?.name || 'Select Brand'
    })()

    const headingSubtitle = (() => {
        if (isEmpty && tenantMemberWithoutBrands) {
            return null
        }
        if (isAllWorkspaces && uniqueTenantCount > 1) {
            return 'Every brand you can open is listed below. Your recent company appears first.'
        }
        if (isAllWorkspaces) {
            return 'Choose a brand to enter'
        }
        return 'Choose a brand to enter'
    })()

    const handleSelect = async (brand) => {
        if (processing || brand.is_disabled) return
        setProcessing(true)
        try {
            await refreshCsrfTokenFromServer()
        } catch {
            /* still attempt */
        }
        router.post('/gateway/select-brand', { brand_id: brand.id }, {
            onFinish: () => setProcessing(false),
        })
    }

    const containerMax = !isPage ? 'max-w-none' : isAllWorkspaces ? 'max-w-6xl xl:max-w-7xl' : 'max-w-lg'
    const gridClass =
        isPage && isAllWorkspaces && list.length > 12
            ? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5'
            : isPage && isAllWorkspaces && list.length > 6
                ? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4'
                : isPage && isAllWorkspaces && list.length > 3
                    ? 'grid-cols-1 min-[480px]:grid-cols-2 lg:grid-cols-3'
                    : isPage
                        ? 'grid-cols-1 sm:grid-cols-2'
                        : 'grid-cols-1'

    return (
        <div className={`w-full animate-fade-in ${containerMax}`} style={{ animationDuration: '500ms' }}>
            <div className={`text-center ${isPage ? 'mb-12' : 'mb-6'}`}>
                <h1
                    className={`font-display font-semibold tracking-tight leading-tight text-white/95 ${
                        isPage ? 'text-4xl md:text-5xl mb-3' : 'text-2xl sm:text-3xl mb-2'
                    }`}
                >
                    {headingTitle}
                </h1>
                {headingSubtitle && (
                    <p className={`text-sm text-white/60 mx-auto ${isPage ? 'mt-2 max-w-md' : 'max-w-md'}`}>
                        {headingSubtitle}
                    </p>
                )}
            </div>

            {isEmpty && tenantMemberWithoutBrands && (
                <div className="mb-10 max-w-lg mx-auto rounded-xl border border-white/10 bg-white/[0.04] px-5 py-5 text-left text-sm leading-relaxed">
                    <p className="text-white/90 font-medium">
                        You&apos;re a member of <span className="text-white">{tenant?.name || 'this company'}</span>, but{' '}
                        <span className="text-white">no brands are assigned</span> to your account yet.
                    </p>
                    <p className="mt-3 text-white/65">
                        Being invited to the company doesn&apos;t always include brand access. A company owner, admin, or brand manager
                        still has to add you to each brand you should work in.
                    </p>
                    <p className="mt-3 text-white/65">
                        <span className="font-medium text-white/80">What to do:</span> ask whoever manages your team to open{' '}
                        <strong className="text-white/85">Company → Team</strong> and assign you to the right brand(s). There isn&apos;t a
                        self‑service request button in the app today—someone with the right permissions has to grant access.
                    </p>
                </div>
            )}

            {isEmpty && !tenantMemberWithoutBrands && (
                <p className="mb-8 text-center text-sm text-white/50 max-w-md mx-auto">
                    No brands are available to select right now.
                </p>
            )}

            {hasDisabledBrands && (
                <div className="mb-6 px-4 py-3 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-200 text-sm text-center">
                    Some brands are unavailable on the current plan. Contact your administrator to upgrade.
                </div>
            )}

            {!isEmpty && (
            <div className={`grid gap-2.5 sm:gap-3 ${gridClass}`}>
                {list.map((brand) => {
                    const color = brand.primary_color || theme?.colors?.primary || '#7c3aed'
                    const isDisabled = brand.is_disabled
                    const useCompactCard = isPage && (isAllWorkspaces || list.length > 4)

                    return (
                        <button
                            key={brand.id}
                            type="button"
                            aria-label={
                                isAllWorkspaces && brand.tenant_name
                                    ? `Open ${brand.name} in ${brand.tenant_name}`
                                    : `Open ${brand.name}`
                            }
                            onClick={() => handleSelect(brand)}
                            disabled={processing || isDisabled}
                            className={`group relative overflow-hidden rounded-lg border text-left transition-colors duration-200 ${
                                useCompactCard ? 'p-3 sm:p-3.5' : 'p-5 sm:p-6'
                            } ${
                                isDisabled
                                    ? 'border-white/[0.04] bg-white/[0.01] cursor-not-allowed'
                                    : 'border-white/[0.08] bg-white/[0.03] hover:border-white/[0.14] hover:bg-white/[0.07]'
                            } disabled:opacity-50`}
                        >
                            <div className="flex items-start gap-2.5 sm:gap-3">
                                <div className="shrink-0 pt-0.5">
                                    <BrandLogo brand={brand} disabled={isDisabled} compact={useCompactCard} />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <h2
                                        className={`font-semibold leading-snug tracking-tight ${
                                            useCompactCard ? 'text-sm' : 'text-base sm:text-lg'
                                        } ${isDisabled ? 'text-white/30' : 'text-white'} truncate`}
                                    >
                                        {brand.name}
                                    </h2>
                                    {isAllWorkspaces && brand.tenant_name && (
                                        <div className="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1">
                                            <span className="max-w-full truncate text-[10px] font-semibold uppercase tracking-wider text-white/38">
                                                {brand.tenant_name}
                                            </span>
                                            {brand.tenant_is_agency ? (
                                                <span className="shrink-0 rounded border border-sky-400/30 bg-sky-500/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-100/95">
                                                    Agency workspace
                                                </span>
                                            ) : null}
                                            {brand.is_default && !isDisabled ? (
                                                <span className="shrink-0 rounded border border-violet-400/25 bg-violet-500/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-100/90">
                                                    Default brand
                                                </span>
                                            ) : null}
                                        </div>
                                    )}
                                    {!isAllWorkspaces && brand.is_default && !isDisabled && (
                                        <p className="mt-1 text-[10px] font-semibold uppercase tracking-wider text-white/35">
                                            Default for this workspace
                                        </p>
                                    )}
                                    {isDisabled && (
                                        <p className="mt-1.5 inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-amber-400/85">
                                            <svg className="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                            Plan limit
                                        </p>
                                    )}
                                </div>
                            </div>

                            {!isDisabled && (
                                <>
                                    <div
                                        className="absolute top-0 left-0 right-0 h-px opacity-0 group-hover:opacity-100 transition-opacity duration-300"
                                        style={{ backgroundColor: color }}
                                    />
                                    <div
                                        className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none"
                                        style={{
                                            background: `radial-gradient(circle at 50% 100%, ${color}10 0%, transparent 72%)`,
                                        }}
                                    />
                                </>
                            )}
                        </button>
                    )
                })}
            </div>
            )}
        </div>
    )
}

