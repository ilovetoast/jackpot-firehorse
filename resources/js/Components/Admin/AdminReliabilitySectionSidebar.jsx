import { useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import AdminSectionSidebar from './AdminSectionSidebar'

/**
 * Reliability Center — grouped local navigation (tabs as query links + cross-links).
 *
 * @param {{ tabSwitchRouteName?: string }} props
 */
export default function AdminReliabilitySectionSidebar({ tabSwitchRouteName = 'admin.reliability.index' }) {
    const { url } = usePage()
    const pageUrl = url || ''
    const path = pageUrl.split('?')[0]
    const search = pageUrl.includes('?') ? pageUrl.split('?')[1] : ''
    const tabParam = new URLSearchParams(search).get('tab') || 'queue'

    const isReliabilityShell =
        path.includes('/admin/reliability') || path.includes('/admin/operations-center')

    const groups = useMemo(() => {
        const tabHref = (id) => route(tabSwitchRouteName, { tab: id })
        const tabActive = (id) => isReliabilityShell && tabParam === id
        const under = (prefix) => path === prefix || path.startsWith(`${prefix}/`)

        return [
            {
                label: 'Overview',
                links: [
                    {
                        href: '/app/admin/system-status',
                        label: 'System status',
                        active: under('/app/admin/system-status'),
                    },
                ],
            },
            {
                label: 'Runtime health',
                links: [
                    { href: tabHref('queue'), label: 'Queue & scheduler', active: tabActive('queue') },
                    { href: tabHref('reliability'), label: 'Reliability metrics', active: tabActive('reliability') },
                    { href: '/app/admin/performance', label: 'Performance', active: under('/app/admin/performance') },
                ],
            },
            {
                label: 'Failures & recovery',
                links: [
                    { href: tabHref('incidents'), label: 'Incidents', active: tabActive('incidents') },
                    {
                        href: '/app/admin/asset-processing-issues',
                        label: 'Asset processing issues',
                        active: under('/app/admin/asset-processing-issues'),
                    },
                    { href: tabHref('application-errors'), label: 'Application errors', active: tabActive('application-errors') },
                    { href: tabHref('failed-jobs'), label: 'Queue failed jobs', active: tabActive('failed-jobs') },
                    { href: tabHref('studio-exports'), label: 'Studio export failures', active: tabActive('studio-exports') },
                    { href: '/app/admin/assets', label: 'Asset operations', active: under('/app/admin/assets') },
                ],
            },
            {
                label: 'Diagnostics',
                links: [
                    { href: '/app/admin/logs', label: 'Raw logs', active: under('/app/admin/logs') },
                    {
                        href: '/app/admin/ai-error-monitoring',
                        label: 'Sentry / AI error monitoring',
                        active: under('/app/admin/ai-error-monitoring'),
                    },
                    {
                        href: '/app/admin/activity-logs',
                        label: 'Activity history',
                        active: under('/app/admin/activity-logs'),
                    },
                ],
            },
        ]
    }, [tabSwitchRouteName, path, tabParam, isReliabilityShell])

    return <AdminSectionSidebar ariaLabel="Reliability Center sections" groups={groups} />
}
