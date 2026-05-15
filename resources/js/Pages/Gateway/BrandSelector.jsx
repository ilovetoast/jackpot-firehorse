import { router, usePage } from '@inertiajs/react'
import { useState, useMemo } from 'react'
import { refreshCsrfTokenFromServer } from '../../utils/csrf'
import BrandIconUnified from '../../Components/BrandIconUnified'
import { getBrandLogoForSurface } from '../../utils/brandLogo'

function BrandLogo({ brand, disabled }) {
    const [imgError, setImgError] = useState(false)
    /** Gateway is a dark cinematic surface — prefer the dark variant, fall back to primary. */
    const logoSrc = getBrandLogoForSurface(brand, 'dark')

    if (logoSrc && !imgError) {
        return (
            <img
                src={logoSrc}
                alt={brand.name}
                className={`h-10 w-auto max-w-full object-contain transition-all ${disabled ? 'grayscale opacity-40' : ''}`}
                onError={() => setImgError(true)}
            />
        )
    }

    return (
        <div className={disabled ? 'grayscale opacity-40' : ''}>
            <BrandIconUnified brand={brand} size="lg" />
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

    const containerMax = !isPage ? 'max-w-none' : isAllWorkspaces ? 'max-w-4xl' : 'max-w-lg'
    const gridClass =
        isPage && isAllWorkspaces && list.length > 4
            ? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3'
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
            <div className={`grid gap-4 ${gridClass}`}>
                {list.map((brand) => {
                    const color = brand.primary_color || theme?.colors?.primary || '#7c3aed'
                    const hasLogo = !!(brand.logo_path || brand.logo_dark_path)
                    const isDisabled = brand.is_disabled

                    return (
                        <button
                            key={brand.id}
                            type="button"
                            onClick={() => handleSelect(brand)}
                            disabled={processing || isDisabled}
                            className={`group relative overflow-hidden rounded-xl border p-6 text-left transition-all duration-200 ${
                                isDisabled
                                    ? 'border-white/[0.04] bg-white/[0.01] cursor-not-allowed'
                                    : 'border-white/[0.08] bg-white/[0.03] hover:scale-[1.02] hover:border-white/[0.16] hover:bg-white/10'
                            } disabled:opacity-50`}
                        >
                            <div className="flex flex-col items-start gap-4">
                                <BrandLogo brand={brand} disabled={isDisabled} />
                                {isAllWorkspaces && brand.tenant_name && (
                                    <p className="text-[11px] font-medium uppercase tracking-wider text-white/40">
                                        {brand.tenant_name}
                                    </p>
                                )}
                                {!hasLogo && (
                                    <h2 className={`text-lg font-medium ${isDisabled ? 'text-white/30' : 'text-white'}`}>
                                        {brand.name}
                                    </h2>
                                )}
                                <div className="flex items-center gap-2">
                                    {brand.is_default && !isDisabled && (
                                        <span className="text-[10px] uppercase tracking-widest text-white/30 inline-block">
                                            Default
                                        </span>
                                    )}
                                    {isDisabled && (
                                        <span className="inline-flex items-center gap-1 text-[10px] uppercase tracking-widest text-amber-400/70">
                                            <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                            Plan limit
                                        </span>
                                    )}
                                </div>
                            </div>

                            {!isDisabled && (
                                <>
                                    <div
                                        className="absolute top-0 left-0 right-0 h-[2px] opacity-0 group-hover:opacity-100 transition-opacity duration-500"
                                        style={{ backgroundColor: color }}
                                    />
                                    <div
                                        className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"
                                        style={{
                                            background: `radial-gradient(circle at 50% 100%, ${color}12 0%, transparent 70%)`,
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

