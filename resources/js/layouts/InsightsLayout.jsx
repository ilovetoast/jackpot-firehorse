import { usePage } from '@inertiajs/react'
import { useMemo } from 'react'
import { InsightsBadge, InsightsCountsProvider, useInsightsCounts } from '../contexts/InsightsCountsContext'
import AppHead from '../Components/AppHead'
import AppNav from '../Components/AppNav'
import AppFooter from '../Components/AppFooter'
import BrandWorkbenchMasthead from '../components/brand-workspace/BrandWorkbenchMasthead'
import WorkbenchLocalNav from '../components/brand-workspace/WorkbenchLocalNav'
import { BRAND_WORKBENCH_CONTENT, WORKBENCH_ASIDE_WIDTH, workbenchPageColumnsClass } from '../components/brand-workspace/brandWorkspaceTokens'
import WorkbenchSegmentedNav from '../components/brand-workspace/WorkbenchSegmentedNav'
import {
    ChartBarIcon,
    TableCellsIcon,
    ArrowTrendingUpIcon,
    ClockIcon,
    SparklesIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline'

const BASE_SIDEBAR_ITEMS = [
    { id: 'overview', label: 'Overview', href: '/app/insights/overview', icon: ChartBarIcon },
    { id: 'review', label: 'Review', href: '/app/insights/review', icon: SparklesIcon },
    { id: 'metadata', label: 'Metadata', href: '/app/insights/metadata', icon: TableCellsIcon },
    { id: 'usage', label: 'Usage', href: '/app/insights/usage', icon: ArrowTrendingUpIcon },
    { id: 'activity', label: 'Activity', href: '/app/insights/activity', icon: ClockIcon },
]

function InsightsWorkbenchNav({ activeSection, sidebarItems }) {
    const { reviewNavTotal } = useInsightsCounts() || { reviewNavTotal: 0 }

    const navItems = useMemo(
        () =>
            sidebarItems.map((item) => ({
                ...item,
                suffix:
                    item.id === 'review' && reviewNavTotal > 0 ? (
                        <InsightsBadge count={reviewNavTotal} className="ml-auto shrink-0 lg:ml-0" />
                    ) : null,
            })),
        [sidebarItems, reviewNavTotal],
    )

    return (
        <>
            <WorkbenchSegmentedNav
                items={navItems.map(({ id, href, label, disabled, suffix }) => ({
                    id,
                    href,
                    label,
                    disabled,
                    suffix,
                }))}
                activeId={activeSection}
                ariaLabel="Insights sections"
            />
            <aside className={`hidden shrink-0 ${WORKBENCH_ASIDE_WIDTH} lg:block`}>
                <WorkbenchLocalNav items={navItems} activeId={activeSection} ariaLabel="Insights sections" />
            </aside>
        </>
    )
}

export default function InsightsLayout({ children, title = 'Insights', activeSection = 'overview' }) {
    const { auth, tenant, creator_module_status, reviewTabCounts } = usePage().props
    const brand = auth?.activeBrand
    const company = auth?.activeCompany
    const brandColor = brand?.primary_color || company?.primary_color

    const sidebarItems = useMemo(() => {
        const creatorOn = creator_module_status?.enabled === true
        if (!creatorOn) {
            return BASE_SIDEBAR_ITEMS
        }
        const items = [...BASE_SIDEBAR_ITEMS]
        const reviewIdx = items.findIndex((i) => i.id === 'review')
        const insertAt = reviewIdx >= 0 ? reviewIdx + 1 : items.length
        items.splice(insertAt, 0, {
            id: 'creator',
            label: 'Creator',
            href: '/app/insights/creator',
            icon: UserGroupIcon,
        })
        return items
    }, [creator_module_status?.enabled])

    return (
        <InsightsCountsProvider initialReviewTabCounts={reviewTabCounts ?? null}>
            <div className="min-h-screen flex flex-col bg-slate-50">
                <AppHead title={title} />
                <AppNav brand={auth?.activeBrand} tenant={tenant} />

                <div className="flex-1">
                    <div className={BRAND_WORKBENCH_CONTENT}>
                        <BrandWorkbenchMasthead
                            companyName={company?.name}
                            brandName={brand?.name}
                            canLinkCompany={
                                Array.isArray(auth?.effective_permissions) &&
                                auth.effective_permissions.includes('company_settings.view')
                            }
                            companyHref={typeof route === 'function' ? route('companies.settings') : '/app/companies/settings'}
                            title="Insights"
                            description="Analytics, metadata health, AI review, and creator performance for this brand."
                            brandColor={brandColor}
                        />

                        <div className={workbenchPageColumnsClass}>
                            <InsightsWorkbenchNav activeSection={activeSection} sidebarItems={sidebarItems} />
                            <main className="min-w-0 flex-1 lg:pl-0">{children}</main>
                        </div>
                    </div>
                </div>

                <AppFooter />
            </div>
        </InsightsCountsProvider>
    )
}
