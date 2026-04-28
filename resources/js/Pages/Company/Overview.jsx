import { router } from '@inertiajs/react'
import { usePermission } from '../../hooks/usePermission'
import { Link } from '@inertiajs/react'
import { switchCompanyWorkspace } from '../../utils/workspaceCompanySwitch'
import { useState, useRef, useEffect } from 'react'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import AppNav from '../../Components/AppNav'
import BrandAvatar from '../../Components/BrandAvatar'
import ConfirmDialog from '../../Components/ConfirmDialog'
import CompanyTabs from '../../Components/Company/CompanyTabs'
import ScopeBanner from '../../Components/Company/ScopeBanner'
import {
    CompanyCommandHero,
    CompanyControlPrimaryCta,
} from '../../Components/Company/CompanyControlCenterShell'
import { isUnlimitedCount, isUnlimitedStorageMB } from '../../utils/planLimitDisplay'
import {
    formatAiCreditsSubtext,
    formatThumbnailEnhancementSubtext,
    isUnifiedAiCreditsPayload,
} from '../../utils/aiCreditsUsageDisplay'
import DashboardLinksRow from '../../Components/DashboardLinksRow'
import {
    ChevronRightIcon,
    PencilSquareIcon,
    TrashIcon,
    EllipsisVerticalIcon,
    PlusIcon,
    BuildingOffice2Icon,
} from '@heroicons/react/24/outline'

export default function CompanyOverview({
    tenant,
    activeBrand,
    plan,
    companyStats,
    brands,
    ai_usage,
    canCreateBrand = false,
    canManageBrands = false,
    agency_managed_brands = [],
    dashboard_links = {},
    show_agency_incubate = false,
}) {
    const [deleteConfirm, setDeleteConfirm] = useState({ open: false, brandId: null, brandName: '' })
    const [actionsOpen, setActionsOpen] = useState(null)
    const actionsRef = useRef(null)
    const { can } = usePermission()
    const dashLinks = dashboard_links && typeof dashboard_links === 'object' ? dashboard_links : {}

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (actionsRef.current && !actionsRef.current.contains(e.target)) {
                setActionsOpen(null)
            }
        }
        if (actionsOpen) {
            document.addEventListener('mousedown', handleClickOutside)
        }
        return () => document.removeEventListener('mousedown', handleClickOutside)
    }, [actionsOpen])

    const formatStorage = (mb) => {
        if (mb < 1) return `${(mb * 1024).toFixed(2)} KB`
        if (mb < 1024) return `${mb.toFixed(2)} MB`
        return `${(mb / 1024).toFixed(2)} GB`
    }

    const formatStorageWithLimit = (currentMB, limitMB) => {
        const current = formatStorage(currentMB)
        if (!limitMB || isUnlimitedStorageMB(limitMB)) return `${current} of Unlimited`
        return `${current} / ${formatStorage(limitMB)}`
    }

    const formatDownloadsWithLimit = (current, limit) => {
        if (!limit || isUnlimitedCount(limit)) return `${current.toLocaleString()} of Unlimited`
        return `${current.toLocaleString()} / ${limit.toLocaleString()}`
    }

    const handleSwitchBrand = (brandId) => {
        router.post(`/app/brands/${brandId}/switch`, {}, {
            preserveScroll: false,
            onSuccess: () => router.visit('/app/overview'),
        })
    }

    const handleBrandOverview = (brandId) => {
        if (activeBrand?.id === brandId) {
            router.visit('/app/overview')
        } else {
            handleSwitchBrand(brandId)
        }
    }

    const handleOpenClientManagedBrand = (clientTenantId, brandId) => {
        switchCompanyWorkspace({
            companyId: clientTenantId,
            brandId,
            redirect: '/app/overview',
        })
    }

    const handleDeleteClick = (brandId, brandName) => {
        setDeleteConfirm({ open: true, brandId, brandName })
    }

    const handleEditBrand = (brandId) => {
        setActionsOpen(null)
        const editUrl = `/app/brands/${brandId}/edit`
        if (activeBrand?.id === brandId) {
            router.visit(editUrl)
        } else {
            router.post(`/app/brands/${brandId}/switch`, {}, {
                preserveScroll: false,
                onSuccess: () => router.visit(editUrl),
            })
        }
    }

    const confirmDelete = () => {
        if (deleteConfirm.brandId) {
            router.delete(`/app/brands/${deleteConfirm.brandId}`, {
                preserveScroll: true,
                onSuccess: () => {
                    setDeleteConfirm({ open: false, brandId: null, brandName: '' })
                },
            })
        }
    }

    const companyName = tenant?.name ?? 'your company'
    const canViewActivityLog = can('activity_logs.view')

    // Hero stat rail: fixed set of 6 columns (shared border + hairline dividers in shell)
    const hasAiPayload = ai_usage && isUnifiedAiCreditsPayload(ai_usage)
    const heroStats = [
        { id: 'brands', label: 'Brands', value: String(brands?.length ?? 0) },
        {
            id: 'assets',
            label: 'Assets',
            value: (companyStats?.total_assets?.value ?? 0).toLocaleString(),
            sub:
                companyStats?.total_assets?.change !== undefined && companyStats.total_assets.change !== 0
                    ? `${companyStats.total_assets.change >= 0 ? '+' : ''}${companyStats.total_assets.change.toFixed(1)}% vs prior period`
                    : undefined,
        },
        {
            id: 'storage',
            label: 'Storage',
            value: formatStorage(companyStats?.storage_mb?.value ?? 0),
            sub: companyStats?.storage_mb?.limit
                ? formatStorageWithLimit(companyStats.storage_mb.value, companyStats.storage_mb.limit)
                : 'In library',
        },
        {
            id: 'downloads',
            label: 'Downloads',
            value: (companyStats?.download_links?.value ?? 0).toLocaleString(),
            sub: companyStats?.download_links?.limit
                ? formatDownloadsWithLimit(companyStats.download_links.value, companyStats.download_links.limit)
                : 'This month',
        },
        {
            id: 'ai',
            label: 'AI credits',
            value: hasAiPayload ? (ai_usage.credits_used ?? 0).toLocaleString() : '—',
            sub: hasAiPayload ? formatAiCreditsSubtext(ai_usage) : 'Open Company admin → Usage',
        },
        {
            id: 'studio',
            label: 'Studio runs',
            value:
                hasAiPayload && ai_usage.thumbnail_enhancement
                    ? (ai_usage.thumbnail_enhancement.count ?? 0).toLocaleString()
                    : '—',
            sub:
                hasAiPayload && ai_usage.thumbnail_enhancement
                    ? formatThumbnailEnhancementSubtext(ai_usage.thumbnail_enhancement)
                    : 'When Studio is used',
        },
    ]

    const BrandRow = ({ brand, isCurrent, onSwitch, onOpenBrand, isDisabled }) => (
        <div
            className={`rounded-lg border bg-white transition-colors ${
                isDisabled
                    ? 'border-slate-200 opacity-50'
                    : isCurrent
                    ? 'border-slate-200/90 ring-1 ring-violet-200/60 shadow-sm'
                    : 'border-slate-200/90 hover:border-slate-300/90'
            }`}
        >
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-4 py-3.5 sm:px-5">
                <div className="flex items-center gap-3.5 min-w-0 sm:gap-4">
                    <div className="flex-shrink-0">
                        <BrandAvatar
                            logoPath={brand.logo_path}
                            iconBgColor={brand.icon_bg_color}
                            name={brand.name}
                            primaryColor={brand.primary_color}
                            size="lg"
                        />
                    </div>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <h3 className={`text-base font-semibold truncate ${isDisabled ? 'text-slate-400' : 'text-slate-900'}`}>
                                {brand.name}
                            </h3>
                            {brand.is_default && (
                                <span className="inline-flex rounded-full border border-slate-200/80 bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">
                                    Default brand
                                </span>
                            )}
                            {isCurrent && !isDisabled && (
                                <span className="inline-flex rounded-full bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-900">
                                    Current workspace
                                </span>
                            )}
                            {isDisabled && (
                                <span className="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800">
                                    Paused
                                </span>
                            )}
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-x-1 text-sm text-slate-500">
                            <span>{brand.stats?.total_assets?.value?.toLocaleString() ?? 0} assets</span>
                            <span aria-hidden="true">&middot;</span>
                            <span>{formatStorage(brand.stats?.storage_mb?.value ?? 0)}</span>
                            <span aria-hidden="true">&middot;</span>
                            <span>{brand.stats?.download_links?.value?.toLocaleString() ?? 0} downloads</span>
                        </div>
                    </div>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0 flex-wrap pl-[52px] sm:pl-0">
                    {!isDisabled && (
                        <>
                            {!isCurrent && (
                                <button
                                    type="button"
                                    onClick={() => onSwitch(brand.id)}
                                    className="inline-flex items-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50"
                                >
                                    Switch
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={() => onOpenBrand(brand.id)}
                                className="inline-flex items-center rounded-md bg-violet-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm ring-1 ring-inset ring-violet-300/20 hover:bg-violet-500"
                            >
                                Open brand
                                <ChevronRightIcon className="ml-1 h-4 w-4" />
                            </button>
                        </>
                    )}
                    {canManageBrands && (!isDisabled || !brand.is_default) && (
                        <div
                            ref={actionsOpen === brand.id ? actionsRef : null}
                            className="relative"
                        >
                            <button
                                type="button"
                                onClick={() => setActionsOpen(actionsOpen === brand.id ? null : brand.id)}
                                className="inline-flex items-center justify-center rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                                title="More actions"
                                aria-expanded={actionsOpen === brand.id}
                            >
                                <EllipsisVerticalIcon className="h-5 w-5" />
                            </button>
                            {actionsOpen === brand.id && (
                                <div className="absolute right-0 top-full z-10 mt-1 w-48 rounded-md bg-white py-1 shadow-lg ring-1 ring-slate-900/5">
                                    {!isDisabled && (
                                        <button
                                            type="button"
                                            onClick={() => handleEditBrand(brand.id)}
                                            className="flex w-full items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left"
                                        >
                                            <PencilSquareIcon className="h-4 w-4" />
                                            Edit brand
                                        </button>
                                    )}
                                    {!brand.is_default && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setActionsOpen(null)
                                                handleDeleteClick(brand.id, brand.name)
                                            }}
                                            className="flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50"
                                        >
                                            <TrashIcon className="h-4 w-4" />
                                            Delete brand
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    )

    return (
        <div className="min-h-screen flex flex-col bg-slate-50">
            <AppHead title="Company dashboard" />
            <AppNav brand={activeBrand} tenant={tenant} />

            <CompanyCommandHero
                companyName={companyName}
                planLabel={plan?.name}
                title="Company dashboard"
                description="Open a brand workspace, jump to people and billing, or add another brand. Your company and every brand are organized here."
                stats={heroStats}
                actions={(
                    <div className="flex w-full flex-col items-stretch gap-3 sm:max-w-sm sm:items-end">
                        <div className="w-full sm:flex sm:max-w-sm sm:justify-end">
                            <DashboardLinksRow links={dashLinks} variant="dark" className="text-right sm:text-left" />
                        </div>
                        <div className="flex w-full flex-col gap-2 min-[400px]:flex-row min-[400px]:flex-wrap min-[400px]:justify-end">
                            {show_agency_incubate && (
                                <Link
                                    href="/app/agency/dashboard?tab=clients"
                                    className="inline-flex w-full min-[400px]:w-auto items-center justify-center gap-1.5 rounded-md border border-white/20 bg-white/5 px-3.5 py-2.5 text-sm font-semibold text-white/95 shadow-sm transition hover:bg-white/10"
                                    title="Start a new client company from your agency dashboard"
                                >
                                    <BuildingOffice2Icon className="h-4 w-4 shrink-0" aria-hidden />
                                    Incubate
                                </Link>
                            )}
                            {canCreateBrand && (
                                <CompanyControlPrimaryCta href="/app/brands/create" title="Add a brand to this company">
                                    <PlusIcon className="h-4 w-4" aria-hidden />
                                    <span className="ml-1.5">Create brand</span>
                                </CompanyControlPrimaryCta>
                            )}
                        </div>
                    </div>
                )}
            />

            <main className="flex-1">
                <div className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                    <div className="space-y-5">
                        <ScopeBanner scope="company" name={companyName} className="shadow-sm" />
                        <CompanyTabs showAgencyTab={false} />
                    </div>

                    <section className="space-y-2.5 pt-1">
                        <h2 className="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">Quick actions</h2>
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <Link
                                href="/app/companies/settings#plan-billing"
                                className="group flex items-center justify-between gap-2 rounded-lg border border-slate-200/90 bg-white px-4 py-3.5 text-sm shadow-sm ring-1 ring-slate-200/50 transition hover:border-violet-200/90 hover:shadow"
                            >
                                <span className="font-medium text-slate-900">Plan &amp; subscription</span>
                                <span className="text-xs font-medium text-violet-700 group-hover:underline">Manage</span>
                            </Link>
                            <Link
                                href="/app/companies/team"
                                className="group flex items-center justify-between gap-2 rounded-lg border border-slate-200/90 bg-white px-4 py-3.5 text-sm shadow-sm ring-1 ring-slate-200/50 transition hover:border-violet-200/90 hover:shadow"
                            >
                                <span className="font-medium text-slate-900">People &amp; invites</span>
                                <span className="text-xs font-medium text-violet-700 group-hover:underline">Open</span>
                            </Link>
                            {canViewActivityLog ? (
                                <Link
                                    href="/app/companies/activity"
                                    className="group flex items-center justify-between gap-2 rounded-lg border border-slate-200/90 bg-white px-4 py-3.5 text-sm shadow-sm ring-1 ring-slate-200/50 transition hover:border-violet-200/90 hover:shadow"
                                >
                                    <span className="font-medium text-slate-900">Activity log</span>
                                    <span className="text-xs font-medium text-violet-700 group-hover:underline">View</span>
                                </Link>
                            ) : null}
                        </div>
                        {ai_usage && isUnifiedAiCreditsPayload(ai_usage) && (ai_usage.is_exceeded || ai_usage.warning_level === 'critical') && (
                            <p className="pt-1 text-xs font-medium text-amber-800/95">
                                AI usage needs attention — open Company admin → Usage to review.
                            </p>
                        )}
                    </section>

                    <section className="border-t border-slate-200/90 pt-7">
                        <h2 className="text-lg font-semibold text-slate-900">Brand directory</h2>
                        <p className="mt-1.5 text-sm text-slate-600">
                            Switch into a brand workspace or open that brand&rsquo;s overview.
                        </p>
                        <ul className="mt-4 space-y-2.5" role="list">
                            {brands?.map((brand) => (
                                <li key={brand.id}>
                                    <BrandRow
                                        brand={brand}
                                        isCurrent={activeBrand?.id === brand.id}
                                        isDisabled={brand.is_disabled}
                                        onSwitch={handleSwitchBrand}
                                        onOpenBrand={handleBrandOverview}
                                    />
                                </li>
                            ))}
                        </ul>
                    </section>

                    {agency_managed_brands?.length > 0 && (
                        <section className="border-t border-slate-200/90 pt-7">
                            <h2 className="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">Agency managed</h2>
                            <p className="mt-2 text-sm text-slate-600">
                                Brands at client companies linked to your agency.
                            </p>
                            <ul className="mt-3 space-y-2.5" role="list">
                                {agency_managed_brands.map((row) => {
                                    const brand = row.brand
                                    const key = `${row.client_tenant_id}-${brand.id}`
                                    return (
                                        <li key={key}>
                                        <div className="rounded-lg border border-slate-200/90 bg-white transition hover:border-slate-300/90">
                                            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-4 py-3.5 sm:px-5">
                                                <div className="flex items-center gap-3.5 min-w-0 sm:gap-4">
                                                    <div className="flex-shrink-0">
                                                        <BrandAvatar
                                                            logoPath={brand.logo_path}
                                                            iconBgColor={brand.icon_bg_color}
                                                            name={brand.name}
                                                            primaryColor={brand.primary_color}
                                                            size="lg"
                                                        />
                                                    </div>
                                                    <div className="min-w-0">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <h3 className="text-base font-semibold text-slate-900 truncate">
                                                                {brand.name}
                                                            </h3>
                                                            {brand.is_default && (
                                                                <span className="inline-flex rounded-full border border-slate-200/80 bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">
                                                                    Default
                                                                </span>
                                                            )}
                                                            <span className="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                                                {row.client_name}
                                                            </span>
                                                        </div>
                                                        <div className="mt-1 flex flex-wrap items-center gap-x-1 text-sm text-slate-500">
                                                            <span>{brand.stats?.total_assets?.value?.toLocaleString() ?? 0} assets</span>
                                                            <span aria-hidden="true">&middot;</span>
                                                            <span>{formatStorage(brand.stats?.storage_mb?.value ?? 0)}</span>
                                                            <span aria-hidden="true">&middot;</span>
                                                            <span>{brand.stats?.download_links?.value?.toLocaleString() ?? 0} downloads</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2 flex-shrink-0 flex-wrap pl-[52px] sm:pl-0">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleOpenClientManagedBrand(row.client_tenant_id, brand.id)}
                                                        className="inline-flex items-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50"
                                                    >
                                                        Switch
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleOpenClientManagedBrand(row.client_tenant_id, brand.id)}
                                                        className="inline-flex items-center rounded-md bg-violet-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm ring-1 ring-inset ring-violet-300/20 hover:bg-violet-500"
                                                    >
                                                        Open brand
                                                        <ChevronRightIcon className="ml-1 h-4 w-4" />
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        </li>
                                    )
                                })}
                            </ul>
                        </section>
                    )}
                </div>
            </main>

            <AppFooter variant="settings" />
            <ConfirmDialog
                open={deleteConfirm.open}
                onClose={() => setDeleteConfirm({ open: false, brandId: null, brandName: '' })}
                onConfirm={confirmDelete}
                title="Delete brand"
                message={`Are you sure you want to delete "${deleteConfirm.brandName}"?`}
                variant="danger"
                confirmText="Delete"
            />
        </div>
    )
}
