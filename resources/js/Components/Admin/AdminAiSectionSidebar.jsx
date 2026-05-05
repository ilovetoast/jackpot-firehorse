import { useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import AdminSectionSidebar from './AdminSectionSidebar'

/** AI Control Center — grouped local navigation (used as AdminShell `sidebar`). */
export default function AdminAiSectionSidebar() {
    const { url } = usePage()
    const pageUrl = url || ''
    const pathRaw = pageUrl.split('?')[0].replace(/\/$/, '') || '/'
    const aiRoot = '/app/admin/ai'
    const search = pageUrl.includes('?') ? pageUrl.split('?')[1] : ''
    const tabParam = new URLSearchParams(search).get('tab')

    const groups = useMemo(() => {
        const overviewActive = pathRaw === aiRoot && tabParam !== 'alerts'
        const alertsActive = pathRaw === aiRoot && tabParam === 'alerts'

        return [
            {
                label: 'Overview',
                links: [{ href: '/app/admin/ai', label: 'Overview', active: overviewActive }],
            },
            {
                label: 'Usage & spend',
                links: [
                    { href: '/app/admin/ai/activity', label: 'AI activity' },
                    { href: '/app/admin/ai/budgets', label: 'Spend & budgets' },
                    { href: '/app/admin/ai/reports', label: 'Reports' },
                    { href: '/app/admin/ai?tab=alerts', label: 'Alerts', active: alertsActive },
                ],
            },
            {
                label: 'Configuration',
                links: [
                    { href: '/app/admin/ai/models', label: 'Models & providers' },
                    { href: '/app/admin/ai/agents', label: 'Agents' },
                    { href: '/app/admin/ai/automations', label: 'Automations' },
                    { href: '/app/admin/ai/studio-platform-features', label: 'AI feature controls' },
                ],
            },
            {
                label: 'Studio & generation',
                links: [
                    { href: '/app/admin/ai/editor-image-audit', label: 'AI generation audit' },
                    { href: '/app/admin/ai/studio-layer-extraction', label: 'Studio layer extraction' },
                    { href: '/app/admin/ai/analyzed-content', label: 'Video intelligence' },
                ],
            },
            {
                label: 'Quality & monitoring',
                links: [
                    { href: '/app/admin/ai/help-diagnostics', label: 'Help AI' },
                    { href: '/app/admin/brand-intelligence', label: 'Brand intelligence' },
                    { href: '/app/admin/ai-agents', label: 'Agent health' },
                    { href: '/app/admin/ai-error-monitoring', label: 'AI error monitoring' },
                ],
            },
        ]
    }, [pathRaw, tabParam])

    return <AdminSectionSidebar ariaLabel="AI Control sections" groups={groups} />
}
