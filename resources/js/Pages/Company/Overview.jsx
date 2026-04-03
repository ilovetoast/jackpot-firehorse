import { usePage, router } from '@inertiajs/react'
import { Link } from '@inertiajs/react'
import { switchCompanyWorkspace } from '../../utils/workspaceCompanySwitch'
import { useState, useRef, useEffect } from 'react'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import AppNav from '../../Components/AppNav'
import BrandAvatar from '../../Components/BrandAvatar'
import ConfirmDialog from '../../Components/ConfirmDialog'
import CompanyTabs from '../../Components/Company/CompanyTabs'
import { isUnlimitedCount, isUnlimitedStorageMB } from '../../utils/planLimitDisplay'
import DashboardLinksRow from '../../Components/DashboardLinksRow'
import {
    FolderIcon,
    CloudArrowDownIcon,
    ServerIcon,
    ArrowUpIcon,
    ArrowDownIcon,
    SparklesIcon,
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
    const { auth, currentWorkspace } = usePage().props
    const workspaceTitle = currentWorkspace?.name || auth?.activeCompany?.name || tenant?.name
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

    const formatChange = (change, isPositive) => (
        <span className={`text-sm font-medium ${isPositive ? 'text-green-600' : 'text-red-600'}`}>
            {change >= 0 ? '+' : ''}{change.toFixed(2)}%
        </span>
    )

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

    const StatCard = ({ icon: Icon, title, value, change, subtext, limit, formatValue = (v) => v }) => (
        <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
            <div className="flex items-center">
                <div className="flex-shrink-0">
                    <Icon className="h-6 w-6 text-gray-400" aria-hidden="true" />
                </div>
                <div className="ml-5 w-0 flex-1">
                    <dt className="text-sm font-medium text-gray-500 truncate">{title}</dt>
                    <dd className="mt-1 flex items-baseline">
                        <span className="text-2xl font-semibold tracking-tight text-gray-900">
                            {formatValue(value)}
                        </span>
                        {change !== undefined && change !== 0 && (
                            <span className="ml-2 flex items-baseline text-sm font-semibold">
                                {change >= 0 ? (
                                    <ArrowUpIcon className="h-4 w-4 text-green-500 mr-0.5" aria-hidden="true" />
                                ) : (
                                    <ArrowDownIcon className="h-4 w-4 text-red-500 mr-0.5" aria-hidden="true" />
                                )}
                                {formatChange(change, change >= 0)}
                            </span>
                        )}
                    </dd>
                    {subtext && <p className="mt-1 text-xs text-gray-500">{subtext}</p>}
                </div>
            </div>
        </div>
    )

    return (
        <div className="min-h-screen flex flex-col bg-gray-50">
            <AppHead title="Company" />
            <AppNav brand={activeBrand} tenant={tenant} />

            <main className="flex-1">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8 flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between lg:gap-8">
                        <div className="min-w-0 flex-1">
                            {workspaceTitle && (
                                <p className="text-sm font-semibold text-gray-500">{workspaceTitle}</p>
                            )}
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">Company Overview</h1>
                                {plan?.name && (
                                    <span className="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-800 ring-1 ring-indigo-100">
                                        {plan.name} Plan
                                    </span>
                                )}
                            </div>
                            <p className="mt-2 text-sm text-gray-600">{tenant?.name} organization</p>
                        </div>
                        <div className="flex w-full shrink-0 flex-col gap-4 sm:flex-row sm:items-end sm:justify-end lg:w-auto lg:max-w-md">
                            <DashboardLinksRow
                                links={dashLinks}
                                variant="light"
                                className="sm:text-right"
                            />
                            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 pt-4 sm:border-t-0 sm:pt-0">
                                {show_agency_incubate && (
                                    <Link
                                        href="/app/agency/dashboard?tab=clients"
                                        className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-800 shadow-sm hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-400"
                                        title="Start a new client company from your agency dashboard"
                                    >
                                        <BuildingOffice2Icon className="h-4 w-4 shrink-0 text-gray-500" aria-hidden />
                                        Incubate
                                    </Link>
                                )}
                                {canCreateBrand && (
                                    <Link
                                        href="/app/brands/create"
                                        className="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                        title="Add a brand to this company"
                                    >
                                        <PlusIcon className="h-4 w-4" />
                                        Create Brand
                                    </Link>
                                )}
                            </div>
                        </div>
                    </div>

                <CompanyTabs showAgencyTab={false} />

                {/* Company-wide metrics */}
                <div className="mb-8">
                    <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Company Totals</h2>
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <StatCard
                            icon={FolderIcon}
                            title="Total Assets"
                            value={companyStats?.total_assets?.value ?? 0}
                            change={companyStats?.total_assets?.change}
                            formatValue={(v) => v.toLocaleString()}
                        />
                        <StatCard
                            icon={ServerIcon}
                            title="Storage"
                            value={companyStats?.storage_mb?.value ?? 0}
                            change={companyStats?.storage_mb?.change}
                            subtext={companyStats?.storage_mb?.limit
                                ? formatStorageWithLimit(companyStats.storage_mb.value, companyStats.storage_mb.limit)
                                : formatStorage(companyStats?.storage_mb?.value ?? 0) + ' used'}
                            formatValue={formatStorage}
                        />
                        <StatCard
                            icon={CloudArrowDownIcon}
                            title="Download Links (this month)"
                            value={companyStats?.download_links?.value ?? 0}
                            change={companyStats?.download_links?.change}
                            subtext={companyStats?.download_links?.limit
                                ? formatDownloadsWithLimit(companyStats.download_links.value, companyStats.download_links.limit)
                                : null}
                            formatValue={(v) => v.toLocaleString()}
                        />
                        {ai_usage && (
                            <>
                                <StatCard
                                    icon={SparklesIcon}
                                    title="AI Tagging"
                                    value={ai_usage.tagging.usage}
                                    subtext={ai_usage.tagging.is_unlimited
                                        ? 'Unlimited'
                                        : `${ai_usage.tagging.remaining ?? 0} remaining this month`}
                                    formatValue={(v) => ai_usage.tagging.is_unlimited ? `${v.toLocaleString()}` : `${v.toLocaleString()} of ${ai_usage.tagging.cap.toLocaleString()}`}
                                />
                                <StatCard
                                    icon={SparklesIcon}
                                    title="AI Suggestions"
                                    value={ai_usage.suggestions.usage}
                                    subtext={ai_usage.suggestions.is_unlimited
                                        ? 'Unlimited'
                                        : `${ai_usage.suggestions.remaining ?? 0} remaining this month`}
                                    formatValue={(v) => ai_usage.suggestions.is_unlimited ? `${v.toLocaleString()}` : `${v.toLocaleString()} of ${ai_usage.suggestions.cap.toLocaleString()}`}
                                />
                            </>
                        )}
                    </div>
                </div>

                {/* Brands list with metrics and switch */}
                <div>
                    <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Brands</h2>
                    <p className="text-sm text-gray-500 mb-6">
                        Select a brand to switch context, or view its overview.
                    </p>
                    <div className="space-y-4">
                        {brands?.map((brand) => (
                            <div
                                key={brand.id}
                                className={`rounded-lg bg-white shadow-sm ring-1 transition-colors ${
                                    brand.is_disabled ? 'ring-gray-200 opacity-60' : 'ring-gray-200 hover:ring-gray-300'
                                }`}
                            >
                                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 p-6">
                                    <div className="flex items-center gap-4 min-w-0">
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
                                                <h3 className="text-lg font-semibold text-gray-900 truncate">
                                                    {brand.name}
                                                </h3>
                                                {brand.is_default && (
                                                    <span className="inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">
                                                        Default
                                                    </span>
                                                )}
                                                {brand.is_disabled && (
                                                    <span className="inline-flex rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">
                                                        Plan Limit
                                                    </span>
                                                )}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-4 text-sm text-gray-500">
                                                <span>{brand.stats?.total_assets?.value?.toLocaleString() ?? 0} assets</span>
                                                <span>{formatStorage(brand.stats?.storage_mb?.value ?? 0)}</span>
                                                <span>{brand.stats?.download_links?.value?.toLocaleString() ?? 0} downloads this month</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-3 flex-shrink-0 flex-wrap">
                                        {/* Primary actions: Switch + Brand Overview */}
                                        {!brand.is_disabled && (
                                            <div className="flex items-center gap-2">
                                                {activeBrand?.id !== brand.id ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleSwitchBrand(brand.id)}
                                                        className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                                    >
                                                        Switch to this brand
                                                    </button>
                                                ) : (
                                                    <span className="inline-flex items-center rounded-md bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700">
                                                        Current brand
                                                    </span>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => handleBrandOverview(brand.id)}
                                                    className="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                >
                                                    Brand Overview
                                                    <ChevronRightIcon className="ml-1 h-4 w-4" />
                                                </button>
                                            </div>
                                        )}
                                        {/* Secondary actions: Edit + Delete in dropdown */}
                                        {canManageBrands && (!brand.is_disabled || !brand.is_default) && (
                                            <div
                                                ref={actionsOpen === brand.id ? actionsRef : null}
                                                className="relative"
                                            >
                                                <button
                                                    type="button"
                                                    onClick={() => setActionsOpen(actionsOpen === brand.id ? null : brand.id)}
                                                    className="inline-flex items-center justify-center rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                                    title="More actions"
                                                    aria-expanded={actionsOpen === brand.id}
                                                >
                                                    <EllipsisVerticalIcon className="h-5 w-5" />
                                                </button>
                                                {actionsOpen === brand.id && (
                                                    <div className="absolute right-0 top-full z-10 mt-1 w-48 rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5">
                                                        {!brand.is_disabled && (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleEditBrand(brand.id)}
                                                                className="flex w-full items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 text-left"
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
                        ))}
                    </div>
                </div>

                {agency_managed_brands?.length > 0 && (
                    <div className="mt-10">
                        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">
                            Agency managed brands
                        </h2>
                        <p className="text-sm text-gray-500 mb-6">
                            Brands at client companies linked to your agency. Open a workspace to work in that company context.
                        </p>
                        <div className="space-y-4">
                            {agency_managed_brands.map((row) => {
                                const brand = row.brand
                                const key = `${row.client_tenant_id}-${brand.id}`
                                return (
                                    <div
                                        key={key}
                                        className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200 transition-colors hover:ring-gray-300"
                                    >
                                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 p-6">
                                            <div className="flex items-center gap-4 min-w-0">
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
                                                        <h3 className="text-lg font-semibold text-gray-900 truncate">
                                                            {brand.name}
                                                        </h3>
                                                        {brand.is_default && (
                                                            <span className="inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">
                                                                Default
                                                            </span>
                                                        )}
                                                        <span className="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                                            {row.client_name}
                                                        </span>
                                                    </div>
                                                    <div className="mt-2 flex flex-wrap gap-4 text-sm text-gray-500">
                                                        <span>{brand.stats?.total_assets?.value?.toLocaleString() ?? 0} assets</span>
                                                        <span>{formatStorage(brand.stats?.storage_mb?.value ?? 0)}</span>
                                                        <span>
                                                            {brand.stats?.download_links?.value?.toLocaleString() ?? 0} downloads this month
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2 flex-shrink-0 flex-wrap">
                                                <button
                                                    type="button"
                                                    onClick={() => handleOpenClientManagedBrand(row.client_tenant_id, brand.id)}
                                                    className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                                >
                                                    Switch to this brand
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleOpenClientManagedBrand(row.client_tenant_id, brand.id)}
                                                    className="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                >
                                                    Brand Overview
                                                    <ChevronRightIcon className="ml-1 h-4 w-4" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                )
                            })}
                        </div>
                    </div>
                )}
                </div>
            </main>

            <AppFooter />
            <ConfirmDialog
                open={deleteConfirm.open}
                onClose={() => setDeleteConfirm({ open: false, brandId: null, brandName: '' })}
                onConfirm={confirmDelete}
                title="Delete Brand"
                message={`Are you sure you want to delete "${deleteConfirm.brandName}"?`}
                variant="danger"
                confirmText="Delete"
            />
        </div>
    )
}
