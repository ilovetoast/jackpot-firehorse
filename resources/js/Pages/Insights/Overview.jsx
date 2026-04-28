import { useState, useEffect, useCallback, useRef } from 'react'
import { Link, usePage } from '@inertiajs/react'
import InsightsLayout from '../../layouts/InsightsLayout'
import { isUnlimitedCount, isUnlimitedStorageMB } from '../../utils/planLimitDisplay'
import {
    formatAiCreditsSubtext,
    formatAiMonthlyCapAlertFeatures,
    isUnifiedAiCreditsPayload,
} from '../../utils/aiCreditsUsageDisplay'
import PendingAiSuggestionsModal from '../../Components/PendingAiSuggestionsModal'
import CreatorInsights from '../../Components/insights/CreatorInsights'
import StorageInsightPanel from '../../Components/insights/StorageInsightPanel'
import useLogoWhiteBgPreview from '../../utils/useLogoWhiteBgPreview'
import {
    SparklesIcon,
    ChartBarIcon,
    ExclamationTriangleIcon,
    EyeIcon,
    ShieldCheckIcon,
    ArrowRightIcon,
    DocumentTextIcon,
} from '@heroicons/react/24/outline'

function MetadataAnalyticsSkeleton() {
    const bar = (w) => (
        <div className={`h-8 rounded bg-gray-200 animate-pulse ${w}`} aria-hidden />
    )
    return (
        <div className="space-y-6" aria-busy="true" aria-label="Loading metadata summary">
            <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    {[1, 2, 3, 4].map((i) => (
                        <div key={i}>
                            <div className="h-4 w-28 rounded bg-gray-200 animate-pulse mb-2" />
                            {bar('w-20')}
                            <div className="h-3 w-36 rounded bg-gray-100 animate-pulse mt-2" />
                        </div>
                    ))}
                </div>
                <div className="mt-6 pt-6 border-t border-gray-200 space-y-2">
                    <div className="h-4 w-48 rounded bg-gray-200 animate-pulse" />
                    {[1, 2, 3].map((i) => (
                        <div key={i} className="flex justify-between gap-4">
                            <div className="h-4 flex-1 max-w-xs rounded bg-gray-100 animate-pulse" />
                            <div className="h-4 w-10 rounded bg-gray-100 animate-pulse" />
                        </div>
                    ))}
                </div>
            </div>
        </div>
    )
}

export default function AnalyticsOverview({
    stats = {},
    ai_usage = null,
    /** When monthly AI tagging/suggestions cap is reached — brand admins / brand managers only */
    ai_monthly_cap_alert = null,
    plan = {},
    brand_guidelines = {},
    creator_module_enabled = false,
    creator_insights = null,
    storage_insight = null,
}) {
    const [suggestionsModalOpen, setSuggestionsModalOpen] = useState(false)
    const [lazyMeta, setLazyMeta] = useState(null)
    const [metadataLoadError, setMetadataLoadError] = useState(false)
    const metadataMountedRef = useRef(true)

    useEffect(() => {
        metadataMountedRef.current = true
        return () => {
            metadataMountedRef.current = false
        }
    }, [])

    const fetchMetadataAnalytics = useCallback(() => {
        setMetadataLoadError(false)
        setLazyMeta(null)
        const url =
            typeof route === 'function'
                ? route('insights.overview.metadata-analytics')
                : '/app/insights/overview/metadata-analytics'
        fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error('Failed to load metadata analytics')
                }
                return res.json()
            })
            .then((data) => {
                if (!metadataMountedRef.current) {
                    return
                }
                setLazyMeta({
                    metadata_overview: data.metadata_overview ?? {},
                    metadata_coverage: data.metadata_coverage ?? {},
                    ai_effectiveness: data.ai_effectiveness ?? {},
                    rights_risk: data.rights_risk ?? {},
                })
            })
            .catch(() => {
                if (metadataMountedRef.current) {
                    setMetadataLoadError(true)
                }
            })
    }, [])

    useEffect(() => {
        fetchMetadataAnalytics()
    }, [fetchMetadataAnalytics])

    // Deep link: open suggestions modal when ?open=suggestions in URL
    useEffect(() => {
        try {
            const params = new URLSearchParams(window.location.search)
            if (params.get('open') === 'suggestions') {
                setSuggestionsModalOpen(true)
                // Remove open=suggestions from URL without reload
                params.delete('open')
                const cleanSearch = params.toString()
                const cleanUrl = window.location.pathname + (cleanSearch ? `?${cleanSearch}` : '')
                window.history.replaceState({}, '', cleanUrl)
            }
        } catch {
            // ignore
        }
    }, [])
    const formatStorage = (mb) => {
        if (!mb || mb === 0) return '0 MB'
        if (mb < 1) return `${(mb * 1024).toFixed(2)} KB`
        if (mb < 1024) return `${mb.toFixed(2)} MB`
        if (mb >= 1048576) return `${(mb / 1048576).toFixed(2)} TB`
        return `${(mb / 1024).toFixed(2)} GB`
    }

    const formatStorageWithLimit = (currentMB, limitMB) => {
        const current = formatStorage(currentMB)
        if (!limitMB || isUnlimitedStorageMB(limitMB)) return `${current} of Unlimited`
        return `${current} / ${formatStorage(limitMB)}`
    }

    const formatDownloadsWithLimit = (current, limit) => {
        if (!limit || isUnlimitedCount(limit)) return `${(current ?? 0).toLocaleString()} of Unlimited`
        return `${(current ?? 0).toLocaleString()} / ${limit.toLocaleString()}`
    }

    /** Single rail cell — label / value / optional subline (workbench stats rail) */
    const WorkbenchStatCell = ({ label, value, subline }) => (
        <div className="flex min-h-[4.75rem] flex-col justify-center p-4 sm:min-h-[5rem] sm:px-5 sm:py-4">
            <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</p>
            <p className="mt-0.5 text-lg font-semibold tabular-nums tracking-tight text-slate-900 sm:text-[1.35rem] sm:leading-tight">
                {value}
            </p>
            {subline ? <p className="mt-0.5 text-xs leading-snug text-slate-500">{subline}</p> : null}
        </div>
    )

    const metadataLoading = lazyMeta === null && !metadataLoadError
    const overview = lazyMeta?.metadata_overview ?? {}
    const coverage = lazyMeta?.metadata_coverage ?? {}
    const ai_effectiveness = lazyMeta?.ai_effectiveness ?? {}
    const rights_risk = lazyMeta?.rights_risk ?? {}
    const lowestCoverage = coverage?.lowest_coverage_fields?.slice(0, 5) ?? []

    const { auth } = usePage().props
    const activeBrand = auth?.activeBrand
    const logoSrc = activeBrand?.logo_path || null
    const logoDarkSrc = activeBrand?.logo_dark_path || null
    const { showRiskBanner: logoWhiteRisk, loadingAnalysis: logoAnalysisLoading } = useLogoWhiteBgPreview(logoSrc, logoDarkSrc)
    const brandSettingsUrl = brand_guidelines?.brand_settings_url || (activeBrand?.id ? `/app/brands/${activeBrand.id}/edit` : null)

    const g = brand_guidelines || {}
    const dnaReady = Boolean(g.dna_ready)
    const hasPublished = Boolean(g.has_published_guidelines)
    const scoringOn = Boolean(g.scoring_enabled)
    const showAiCredits = Boolean(ai_usage && isUnifiedAiCreditsPayload(ai_usage))

    return (
        <InsightsLayout title="Insights Overview" activeSection="overview">
            <div className="space-y-8 animate-fadeInUp-d1">
                {ai_monthly_cap_alert?.features?.length > 0 && (
                    <section
                        className="rounded-xl border border-amber-300 bg-amber-50 p-4 sm:p-5"
                        role="status"
                        aria-live="polite"
                    >
                        <div className="flex gap-3">
                            <ExclamationTriangleIcon className="h-6 w-6 flex-shrink-0 text-amber-600" aria-hidden />
                            <div className="min-w-0">
                                <h2 className="text-base font-semibold text-amber-900">Monthly AI limit reached</h2>
                                <p className="mt-1 text-sm text-amber-950/90">
                                    Your{' '}
                                    <span className="font-medium">{formatAiMonthlyCapAlertFeatures(ai_monthly_cap_alert.features)}</span>{' '}
                                    has been used. AI features that consume credits may be paused until usage resets or you upgrade.
                                </p>
                                {ai_monthly_cap_alert.reset_hint && (
                                    <p className="mt-2 text-sm text-amber-900/80">{ai_monthly_cap_alert.reset_hint}</p>
                                )}
                            </div>
                        </div>
                    </section>
                )}
                {/* Brand guidelines — product intelligence module when active (violet); amber only when setup needed */}
                <section
                    className={`rounded-xl border p-4 sm:p-5 ${
                        dnaReady
                            ? 'border-violet-200/90 bg-gradient-to-br from-violet-50/50 via-white to-slate-50/80 shadow-sm ring-1 ring-violet-500/[0.08]'
                            : 'border-amber-200/90 bg-amber-50/90'
                    }`}
                    aria-labelledby="insights-brand-guidelines-heading"
                >
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex gap-3 min-w-0">
                            <div
                                className={`flex-shrink-0 rounded-lg p-2.5 ${
                                    dnaReady ? 'bg-violet-100/90 ring-1 ring-violet-200/60' : 'bg-amber-100'
                                }`}
                            >
                                <DocumentTextIcon
                                    className={`h-6 w-6 ${dnaReady ? 'text-violet-700' : 'text-amber-800'}`}
                                    aria-hidden
                                />
                            </div>
                            <div className="min-w-0">
                                <h2
                                    id="insights-brand-guidelines-heading"
                                    className="text-base font-semibold text-slate-900"
                                >
                                    {dnaReady ? 'Brand guidelines are active' : 'Set up brand guidelines for full Insights'}
                                </h2>
                                <p className="mt-1.5 text-sm text-slate-600 leading-relaxed">
                                    {dnaReady ? (
                                        <>
                                            Your published brand DNA powers{' '}
                                            <span className="font-medium text-slate-800">brand scoring</span>,{' '}
                                            <span className="font-medium text-slate-800">generative AI</span> (tagging
                                            &amp; suggestions), and alignment features. Keep guidelines up to date as
                                            your brand evolves.
                                        </>
                                    ) : (
                                        <>
                                            <span className="font-medium">Brand scoring</span> and{' '}
                                            <span className="font-medium">generative AI</span> need a completed,
                                            published brand guidelines model. Finish research → review → build →
                                            publish so we can score assets and personalize AI to your brand.
                                        </>
                                    )}
                                </p>
                                {!dnaReady && hasPublished && !scoringOn && (
                                    <p className="mt-2 text-sm text-amber-900/90">
                                        Published guidelines found, but{' '}
                                        <span className="font-medium">brand DNA scoring is off</span>. Turn it on in
                                        Brand Settings (Strategy) so scoring and AI can use your DNA.
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="flex flex-col sm:flex-row gap-2 sm:flex-shrink-0 w-full sm:w-auto">
                            {!dnaReady && (
                                <Link
                                    href={g.research_url || '#'}
                                    className="inline-flex justify-center items-center gap-2 rounded-lg bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-violet-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                                >
                                    <SparklesIcon className="h-5 w-5" aria-hidden />
                                    {hasPublished ? 'Update brand guidelines' : 'Start brand guidelines'}
                                    <ArrowRightIcon className="h-4 w-4" aria-hidden />
                                </Link>
                            )}
                            {(dnaReady || hasPublished) && g.guidelines_url && (
                                <Link
                                    href={g.guidelines_url}
                                    className={`inline-flex justify-center items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm ${
                                        dnaReady
                                            ? 'bg-white text-violet-800 ring-1 ring-inset ring-violet-200 hover:bg-violet-50/80'
                                            : 'bg-white/80 text-slate-800 ring-1 ring-inset ring-amber-200 hover:bg-white'
                                    }`}
                                >
                                    View guidelines
                                    <ArrowRightIcon className="h-4 w-4" aria-hidden />
                                </Link>
                            )}
                            {!dnaReady && hasPublished && !scoringOn && g.brand_settings_url && (
                                <Link
                                    href={g.brand_settings_url}
                                    className="inline-flex justify-center items-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-amber-900 ring-1 ring-inset ring-amber-300 hover:bg-amber-50"
                                >
                                    Brand settings (Strategy)
                                </Link>
                            )}
                        </div>
                    </div>
                </section>

                {/* Logo white-on-white insight */}
                {logoWhiteRisk && !logoAnalysisLoading && (
                    <section
                        className="rounded-xl border border-amber-200 bg-amber-50/90 p-4 sm:p-5"
                        aria-labelledby="insights-logo-contrast-heading"
                    >
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div className="flex gap-3 min-w-0">
                                <div className="flex-shrink-0 rounded-lg bg-amber-100 p-2.5">
                                    <EyeIcon className="h-6 w-6 text-amber-800" aria-hidden />
                                </div>
                                <div className="min-w-0">
                                    <h2
                                        id="insights-logo-contrast-heading"
                                        className="text-base font-semibold text-gray-900"
                                    >
                                        Your logo may not work well on white backgrounds
                                    </h2>
                                    <p className="mt-1.5 text-sm text-gray-700 leading-relaxed">
                                        We analyzed your primary logo and found it's mostly{' '}
                                        <span className="font-medium">white, very light, or transparent</span> — it
                                        can disappear or look washed out on light backgrounds throughout the app and
                                        in shared links. Upload a{' '}
                                        <span className="font-medium">dark version</span> of your logo in Brand
                                        Settings so Jackpot can use the right variant automatically.
                                    </p>
                                    {logoSrc && (
                                        <div className="mt-3 flex items-center gap-3">
                                            <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white p-2">
                                                <img
                                                    src={logoSrc}
                                                    alt="Current logo on white"
                                                    className="h-8 max-w-[120px] object-contain"
                                                />
                                            </div>
                                            <span className="text-xs text-amber-800">Low contrast on white</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                            {brandSettingsUrl && (
                                <div className="flex flex-shrink-0">
                                    <Link
                                        href={brandSettingsUrl}
                                        className="inline-flex justify-center items-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-amber-900 ring-1 ring-inset ring-amber-300 shadow-sm hover:bg-amber-50"
                                    >
                                        Brand Settings
                                        <ArrowRightIcon className="h-4 w-4" aria-hidden />
                                    </Link>
                                </div>
                            )}
                        </div>
                    </section>
                )}

                {/* Brand totals — single rail w/ dividers (editorial hierarchy, no floating icon tiles) */}
                <section>
                    <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Brand totals</h2>
                    <div className="overflow-hidden rounded-xl border border-slate-200/90 bg-white shadow-sm">
                        <div
                            className={`grid grid-cols-2 divide-x divide-y divide-slate-100 sm:grid-cols-3 ${
                                showAiCredits ? 'lg:grid-cols-6' : 'lg:grid-cols-5'
                            }`}
                        >
                            <WorkbenchStatCell
                                label="Total assets"
                                value={(stats.total_assets ?? 0).toLocaleString()}
                            />
                            <WorkbenchStatCell
                                label="Storage"
                                value={formatStorage(stats.storage_mb ?? 0)}
                                subline={
                                    stats.storage_limit_mb
                                        ? formatStorageWithLimit(stats.storage_mb, stats.storage_limit_mb)
                                        : `${formatStorage(stats.storage_mb ?? 0)} used`
                                }
                            />
                            <WorkbenchStatCell
                                label="Downloads (mo.)"
                                value={(stats.downloads ?? 0).toLocaleString()}
                                subline={
                                    stats.downloads_limit
                                        ? formatDownloadsWithLimit(stats.downloads, stats.downloads_limit)
                                        : null
                                }
                            />
                            <WorkbenchStatCell
                                label="Collections"
                                value={(stats.collections ?? 0).toLocaleString()}
                            />
                            <WorkbenchStatCell
                                label="Executions"
                                value={(stats.executions ?? 0).toLocaleString()}
                            />
                            {showAiCredits ? (
                                <WorkbenchStatCell
                                    label="AI credits"
                                    value={(ai_usage.credits_used ?? 0).toLocaleString()}
                                    subline={formatAiCreditsSubtext(ai_usage)}
                                />
                            ) : null}
                        </div>
                    </div>
                    <div className="mt-5">
                        <StorageInsightPanel storage_insight={storage_insight} formatStorage={formatStorage} />
                    </div>
                </section>

                {creator_module_enabled && creator_insights != null && (
                    <CreatorInsights insights={creator_insights} />
                )}

                {/* Metadata Health Summary — loaded after first paint */}
                <section>
                    <h2 className="mb-4 flex items-center text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <ChartBarIcon className="mr-2 h-4 w-4 text-slate-400" />
                        Metadata health summary
                    </h2>
                    {metadataLoadError && (
                        <div
                            className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 mb-4"
                            role="alert"
                        >
                            <p>Couldn&apos;t load metadata summary. Your brand totals above are still accurate.</p>
                            <button
                                type="button"
                                onClick={() => fetchMetadataAnalytics()}
                                className="mt-2 text-sm font-semibold text-violet-700 hover:text-violet-600"
                            >
                                Try again
                            </button>
                        </div>
                    )}
                    {metadataLoading ? (
                        <MetadataAnalyticsSkeleton />
                    ) : (
                        !metadataLoadError && (
                            <>
                                <div className="rounded-xl border border-slate-200/90 bg-white p-6 shadow-sm">
                                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                                        <div>
                                            <p className="text-sm font-medium text-gray-500">Completeness</p>
                                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                                {overview.completeness_percentage?.toFixed(1) ?? '0'}%
                                            </p>
                                            <p className="mt-0.5 text-xs text-gray-500">
                                                {overview.assets_with_metadata?.toLocaleString() ?? 0} of{' '}
                                                {overview.total_assets?.toLocaleString() ?? 0} assets
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-gray-500">Avg Metadata per Asset</p>
                                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                                {overview.avg_metadata_per_asset?.toFixed(1) ?? '0'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-gray-500">Total Metadata Values</p>
                                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                                {overview.total_metadata_values?.toLocaleString() ?? '0'}
                                            </p>
                                        </div>
                                        <div>
                                            <Link
                                                href="/app/insights/metadata"
                                                className="inline-flex items-center gap-1.5 text-sm font-medium text-violet-600 hover:text-violet-500"
                                            >
                                                View full metadata insights
                                                <ArrowRightIcon className="h-4 w-4" />
                                            </Link>
                                        </div>
                                    </div>
                                    {lowestCoverage.length > 0 && (
                                        <div className="mt-6 pt-6 border-t border-gray-200">
                                            <h3 className="text-sm font-semibold text-gray-900 mb-3">
                                                Fields with Lowest Coverage
                                            </h3>
                                            <div className="space-y-2">
                                                {lowestCoverage.map((field, idx) => (
                                                    <div
                                                        key={field.field_key ?? idx}
                                                        className="flex items-center justify-between text-sm"
                                                    >
                                                        <span className="text-gray-700">{field.field_label}</span>
                                                        <span className="text-gray-500 tabular-nums">
                                                            {field.coverage_percentage}%
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </>
                        )
                    )}
                </section>

                {/* AI Suggestion Effectiveness (preview) */}
                {!metadataLoading &&
                    !metadataLoadError &&
                    (ai_effectiveness?.total_suggestions > 0 || ai_effectiveness?.approved_suggestions > 0) && (
                    <section>
                        <h2 className="mb-4 flex items-center text-xs font-semibold uppercase tracking-wider text-slate-500">
                            <SparklesIcon className="mr-2 h-4 w-4 text-slate-400" />
                            AI suggestion effectiveness
                        </h2>
                        <div className="rounded-xl border border-slate-200/90 bg-white p-6 shadow-sm">
                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <p className="text-sm font-medium text-gray-500">Total Suggestions</p>
                                    <p className="mt-1 text-2xl font-semibold text-gray-900">
                                        {ai_effectiveness.total_suggestions?.toLocaleString() ?? 0}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-gray-500">Approved</p>
                                    <p className="mt-1 text-2xl font-semibold text-violet-700">
                                        {ai_effectiveness.approved_suggestions?.toLocaleString() ?? 0}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-gray-500">Acceptance Rate</p>
                                    <p className="mt-1 text-2xl font-semibold text-gray-900">
                                        {ai_effectiveness.acceptance_rate?.toFixed(1) ?? 0}%
                                    </p>
                                </div>
                                <div>
                                    <Link
                                        href="/app/insights/metadata"
                                        className="inline-flex items-center gap-1.5 text-sm font-medium text-violet-600 hover:text-violet-500"
                                    >
                                        View details
                                        <ArrowRightIcon className="h-4 w-4" />
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </section>
                    )}

                {/* Rights & Risk Indicators */}
                {!metadataLoading &&
                    !metadataLoadError &&
                    (rights_risk?.expired_count > 0 ||
                    rights_risk?.expiring_30_days > 0 ||
                    rights_risk?.expiring_60_days > 0 ||
                    rights_risk?.expiring_90_days > 0) && (
                    <section>
                        <h2 className="mb-4 flex items-center text-xs font-semibold uppercase tracking-wider text-slate-500">
                            <ShieldCheckIcon className="mr-2 h-4 w-4 text-slate-400" />
                            Rights &amp; risk indicators
                        </h2>
                        <div className="rounded-xl border border-slate-200/90 bg-white p-6 shadow-sm">
                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                                {rights_risk.expired_count > 0 && (
                                    <div className="flex items-center gap-3">
                                        <ExclamationTriangleIcon className="h-8 w-8 text-red-500 flex-shrink-0" />
                                        <div>
                                            <p className="text-sm font-medium text-gray-500">Expired</p>
                                            <p className="text-xl font-semibold text-red-600">
                                                {rights_risk.expired_count} assets
                                            </p>
                                        </div>
                                    </div>
                                )}
                                {rights_risk.expiring_30_days > 0 && (
                                    <div className="flex items-center gap-3">
                                        <ExclamationTriangleIcon className="h-8 w-8 text-amber-500 flex-shrink-0" />
                                        <div>
                                            <p className="text-sm font-medium text-gray-500">Expiring in 30 days</p>
                                            <p className="text-xl font-semibold text-amber-600">
                                                {rights_risk.expiring_30_days} assets
                                            </p>
                                        </div>
                                    </div>
                                )}
                                {rights_risk.expiring_60_days > 0 && (
                                    <div>
                                        <p className="text-sm font-medium text-gray-500">Expiring in 60 days</p>
                                        <p className="text-xl font-semibold text-gray-900">
                                            {rights_risk.expiring_60_days} assets
                                        </p>
                                    </div>
                                )}
                                {rights_risk.expiring_90_days > 0 && (
                                    <div>
                                        <p className="text-sm font-medium text-gray-500">Expiring in 90 days</p>
                                        <p className="text-xl font-semibold text-gray-900">
                                            {rights_risk.expiring_90_days} assets
                                        </p>
                                    </div>
                                )}
                            </div>
                            <div className="mt-4">
                                <Link
                                    href="/app/insights/metadata"
                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-violet-600 hover:text-violet-500"
                                >
                                    View rights details
                                    <ArrowRightIcon className="h-4 w-4" />
                                </Link>
                            </div>
                        </div>
                    </section>
                    )}
            </div>

            {/* AI suggestions review modal — opened via ?open=suggestions deep link */}
            {suggestionsModalOpen && (
                <PendingAiSuggestionsModal
                    isOpen={suggestionsModalOpen}
                    onClose={() => setSuggestionsModalOpen(false)}
                />
            )}
        </InsightsLayout>
    )
}
