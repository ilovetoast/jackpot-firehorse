/**
 * Unified agency context picker for the main nav (replaces separate CLIENT strip dropdown).
 * Data: auth.agency_context_picker — built server-side only for agency-context sessions.
 */
import { useMemo, useState } from 'react'
import { Link } from '@inertiajs/react'
import { CheckIcon } from '@heroicons/react/20/solid'
import BrandIconUnified from '../BrandIconUnified'
import { switchCompanyWorkspace } from '../../utils/workspaceCompanySwitch'

function brandRowForIcon(item) {
    return {
        id: item.brand_id,
        name: item.brand_name,
        logo_path: item.logo_url || null,
        primary_color: item.primary_color || null,
        icon_style: 'subtle',
    }
}

export default function AgencyContextNavPicker({
    agencyPicker,
    activeBrand,
    textColor,
    isDarkNavChrome,
    logoFilterStyle,
    navLogoSrc,
    useDedicatedVariantLogo,
    brandName,
    logoError,
    setLogoError,
}) {
    const [menuOpen, setMenuOpen] = useState(false)
    const [search, setSearch] = useState('')

    const filteredGroups = useMemo(() => {
        const q = search.trim().toLowerCase()
        if (!q) {
            return agencyPicker.groups || []
        }
        return (agencyPicker.groups || [])
            .map((g) => ({
                ...g,
                items: (g.items || []).filter((it) => {
                    const bn = String(it.brand_name || '').toLowerCase()
                    const tn = String(it.tenant_name || '').toLowerCase()
                    return bn.includes(q) || tn.includes(q)
                }),
            }))
            .filter((g) => (g.items || []).length > 0)
    }, [agencyPicker.groups, search])

    const openContext = (item) => {
        if (item.is_active) {
            setMenuOpen(false)
            return
        }
        const redirect =
            typeof window !== 'undefined'
                ? `${window.location.pathname}${window.location.search || ''}`
                : '/app/overview'
        setMenuOpen(false)
        switchCompanyWorkspace({
            companyId: item.tenant_id,
            brandId: item.brand_id,
            redirect,
        })
    }

    const buttonRing = isDarkNavChrome
        ? 'hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-white/35 focus-visible:ring-offset-0'
        : 'hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2'

    const onAgencyWorkspace =
        agencyPicker.active_tenant_id === agencyPicker.agency_tenant_id

    /** Brand create always uses the current session tenant — spell that out for agency users. */
    const addBrandTargetName = agencyPicker.active_tenant_name?.trim() || 'this company'
    const addBrandIsAgencyWorkspace =
        Number(agencyPicker.active_tenant_id) === Number(agencyPicker.agency_tenant_id)
    const addBrandScopeHint = addBrandIsAgencyWorkspace ? 'Your agency' : 'Managed client'
    const addBrandAriaLabel = `Create a new brand for ${addBrandTargetName} (${addBrandScopeHint})`

    const hasLogo = Boolean(navLogoSrc && !logoError)
    const contextDescription = [
        'Switch brand or workspace',
        brandName,
        !onAgencyWorkspace && agencyPicker.active_tenant_name?.trim()
            ? `Client: ${agencyPicker.active_tenant_name.trim()}`
            : null,
    ]
        .filter(Boolean)
        .join(' — ')
    const contextButtonTitle = hasLogo ? contextDescription : 'Switch brand or workspace'

    if (!agencyPicker.has_multiple_contexts) {
        return (
            <div className="flex min-w-0 max-w-full items-center gap-2.5 py-2">
                {navLogoSrc && !logoError ? (
                    <img
                        src={navLogoSrc}
                        alt={brandName}
                        className="h-12 w-auto max-w-full object-contain object-left"
                        style={useDedicatedVariantLogo ? undefined : logoFilterStyle}
                        onError={() => setLogoError(true)}
                    />
                ) : (
                    <>
                        <BrandIconUnified brand={activeBrand} size="lg" />
                        <span
                            className="min-w-0 truncate font-semibold leading-snug"
                            style={{
                                fontSize: 'clamp(0.8rem, 1.5vw, 1rem)',
                                maxWidth: '10rem',
                                color: textColor || 'inherit',
                            }}
                        >
                            {brandName}
                        </span>
                    </>
                )}
            </div>
        )
    }

    return (
        <div className="relative flex min-w-0 max-w-full items-center">
            <button
                type="button"
                onClick={() => setMenuOpen(!menuOpen)}
                aria-expanded={menuOpen}
                aria-haspopup="true"
                aria-label={contextDescription}
                className={`flex min-w-0 max-w-full items-center gap-2 rounded-md px-2 py-2 text-sm font-medium transition-colors focus:outline-none sm:px-3 ${buttonRing}`}
                style={{ color: textColor }}
                title={contextButtonTitle}
            >
                {hasLogo ? (
                    <img
                        src={navLogoSrc}
                        alt={brandName}
                        className="h-12 w-auto max-w-full shrink-0 object-contain object-left"
                        style={useDedicatedVariantLogo ? undefined : logoFilterStyle}
                        onError={() => setLogoError(true)}
                    />
                ) : (
                    <BrandIconUnified brand={activeBrand} size="lg" />
                )}
                {/** Logo wordmark carries the brand (and often company) name — never stack duplicate text beside it. */}
                {!hasLogo && (
                    <div className="flex min-w-0 flex-1 flex-col items-start text-left leading-tight">
                        <span className="w-full truncate font-semibold">{brandName}</span>
                        {!onAgencyWorkspace && (
                            <span
                                className="w-full truncate text-[10px] font-normal opacity-70"
                                title={agencyPicker.active_tenant_name}
                            >
                                {agencyPicker.active_tenant_name}
                            </span>
                        )}
                    </div>
                )}
                <svg
                    className={`h-5 w-5 shrink-0 transition-transform ${menuOpen ? 'rotate-180' : ''}`}
                    style={{ color: textColor === '#ffffff' ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.7)' }}
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        fillRule="evenodd"
                        d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                        clipRule="evenodd"
                    />
                </svg>
            </button>

            {menuOpen && (
                <>
                    <div className="fixed inset-0 z-[100]" onClick={() => setMenuOpen(false)} aria-hidden="true" />
                    <div
                        className="absolute left-0 top-full z-[101] mt-2 flex w-[min(100vw-1.5rem,22rem)] max-h-[min(calc(100dvh-9rem),30rem)] flex-col overflow-hidden origin-top-left rounded-xl bg-white shadow-lg ring-1 ring-black/5 focus:outline-none sm:max-h-[min(calc(100dvh-8rem),36rem)] sm:w-[min(100vw-2rem,24rem)] lg:max-h-[min(calc(100dvh-7rem),42rem)]"
                        onClick={(e) => e.stopPropagation()}
                        role="menu"
                    >
                        <div className="shrink-0 border-b border-gray-100 px-3 pb-2 pt-2">
                            <label className="sr-only" htmlFor="agency-context-search">
                                Search brands or clients
                            </label>
                            <input
                                id="agency-context-search"
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search brands or clients..."
                                className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                autoComplete="off"
                            />
                        </div>
                        <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto py-1 scrollbar-thin">
                            {filteredGroups.length === 0 ? (
                                <div className="px-4 py-6 text-center text-sm text-gray-500">No matches</div>
                            ) : (
                                filteredGroups.map((group, gi) => (
                                    <div key={`${group.type}-${group.tenant_id}-${gi}`} className="mb-1">
                                        {group.section_label ? (
                                            <div className="px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                                                {group.section_label}
                                            </div>
                                        ) : null}
                                        {(group.type === 'agency' || group.type === 'client') && group.tenant_name ? (
                                            <div className="px-4 pb-0.5 pt-1 text-xs font-semibold text-gray-600">
                                                {group.tenant_name}
                                            </div>
                                        ) : null}
                                        {(group.items || []).map((item) => (
                                            <button
                                                key={`${item.tenant_id}-${item.brand_id}`}
                                                type="button"
                                                role="menuitem"
                                                onClick={() => openContext(item)}
                                                className={`flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm ${
                                                    item.is_active
                                                        ? 'bg-indigo-50 text-indigo-900'
                                                        : 'text-gray-800 hover:bg-gray-50'
                                                }`}
                                            >
                                                <BrandIconUnified brand={brandRowForIcon(item)} size="md" />
                                                <span className="min-w-0 flex-1 truncate font-medium">{item.brand_name}</span>
                                                {item.is_active ? (
                                                    <CheckIcon className="h-4 w-4 shrink-0 text-indigo-600" aria-hidden />
                                                ) : null}
                                            </button>
                                        ))}
                                    </div>
                                ))
                            )}
                        </div>
                        {agencyPicker.can_add_brand_for_active_tenant ? (
                            <div className="shrink-0 border-t border-gray-100 bg-white">
                                <Link
                                    href={agencyPicker.add_brand_url || '/app/brands/create'}
                                    className="flex items-center gap-3 px-4 py-3 text-sm transition-colors hover:bg-gray-50"
                                    onClick={() => setMenuOpen(false)}
                                    aria-label={addBrandAriaLabel}
                                >
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border-2 border-dashed border-gray-300">
                                        <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
                                        </svg>
                                    </div>
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-medium text-gray-900">New brand</span>
                                        <span className="mt-0.5 block truncate text-[11px] leading-snug text-gray-500">
                                            For <span className="font-medium text-gray-600">{addBrandTargetName}</span>
                                            <span className="text-gray-400"> · </span>
                                            <span className="font-semibold uppercase tracking-wide text-[10px] text-indigo-600/90">
                                                {addBrandScopeHint}
                                            </span>
                                        </span>
                                    </span>
                                </Link>
                            </div>
                        ) : null}
                    </div>
                </>
            )}
        </div>
    )
}
