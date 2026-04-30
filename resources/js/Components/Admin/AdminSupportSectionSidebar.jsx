import { useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import AdminSectionSidebar from './AdminSectionSidebar'

/** Support center — hub + ticket queues (used as AdminShell `sidebar`). */
export default function AdminSupportSectionSidebar() {
    const { url } = usePage()
    const ticket = usePage().props.ticket

    const groups = useMemo(() => {
        const pageUrl = url || ''
        const path = pageUrl.split('?')[0].replace(/\/$/, '') || '/'
        const qs = pageUrl.includes('?') ? pageUrl.slice(pageUrl.indexOf('?') + 1) : ''
        const params = new URLSearchParams(qs)
        const engineeringFromQuery =
            params.get('type') === 'engineering' || params.get('engineering_only') === '1'

        const hubActive = path === '/app/admin/support'
        const ticketsBase = '/app/admin/support/tickets'
        const isTicketDetail = /^\/app\/admin\/support\/tickets\/\d+$/.test(path)
        const isTicketsList = path === ticketsBase

        const isEngTicket =
            ticket?.is_engineering_queue === true ||
            (ticket?.type === 'internal' && ticket?.assigned_team === 'engineering')

        const supportTicketsActive =
            (isTicketsList && !engineeringFromQuery) || (isTicketDetail && ticket != null && !isEngTicket)
        const engineeringActive =
            (isTicketsList && engineeringFromQuery) || (isTicketDetail && ticket != null && isEngTicket)

        return [
            {
                label: 'Overview',
                links: [
                    {
                        href: '/app/admin/support',
                        label: 'Support hub',
                        match: 'exact',
                        active: hubActive,
                    },
                ],
            },
            {
                label: 'Queues',
                links: [
                    {
                        href: '/app/admin/support/tickets',
                        label: 'Support tickets',
                        active: supportTicketsActive,
                    },
                    {
                        href: '/app/admin/support/tickets?type=engineering',
                        label: 'Engineering queue',
                        active: engineeringActive,
                    },
                ],
            },
        ]
    }, [url, ticket])

    return <AdminSectionSidebar ariaLabel="Support sections" groups={groups} />
}
