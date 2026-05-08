import { Link, usePage } from '@inertiajs/react'
import { useAdminPermissions } from '../../hooks/useAdminPermissions'

const CENTERS = [
    { key: 'command', label: 'Command Center', href: '/app/admin' },
    { key: 'reliability', label: 'Reliability', href: '/app/admin/reliability' },
    { key: 'ai', label: 'AI Control', href: '/app/admin/ai' },
    { key: 'organization', label: 'Organization', href: '/app/admin/organization' },
    { key: 'billing', label: 'Billing', href: '/app/admin/billing' },
    { key: 'platform', label: 'Platform', href: '/app/admin/platform' },
    { key: 'support', label: 'Support', href: '/app/admin/support' },
]

function pathWithoutQuery(url) {
    if (!url) {
        return ''
    }
    const s = String(url)
    const q = s.indexOf('?')
    return q === -1 ? s : s.slice(0, q)
}

function isActiveCenter(activeKey, path, key) {
    if (activeKey) {
        return activeKey === key
    }
    if (key === 'command') {
        return path === '/app/admin'
    }
    if (key === 'reliability') {
        return (
            path.startsWith('/app/admin/reliability') ||
            path.startsWith('/app/admin/operations-center') ||
            path.startsWith('/app/admin/logs') ||
            path.startsWith('/app/admin/system-status') ||
            path.startsWith('/app/admin/performance') ||
            path.startsWith('/app/admin/download-failures') ||
            path.startsWith('/app/admin/upload-failures') ||
            path.startsWith('/app/admin/derivative-failures') ||
            path.startsWith('/app/admin/deletion-errors')
        )
    }
    if (key === 'ai') {
        return (
            path.startsWith('/app/admin/ai') ||
            path.startsWith('/app/admin/ai-agents') ||
            path.startsWith('/app/admin/ai-error-monitoring') ||
            path.startsWith('/app/admin/brand-intelligence')
        )
    }
    if (key === 'organization') {
        return path.startsWith('/app/admin/organization') || path.startsWith('/app/admin/activity-logs') || path.startsWith('/app/admin/companies/')
    }
    if (key === 'billing') {
        return path.startsWith('/app/admin/billing')
    }
    if (key === 'platform') {
        return (
            path.startsWith('/app/admin/platform') ||
            path.startsWith('/app/admin/permissions') ||
            path.startsWith('/app/admin/metadata') ||
            path.startsWith('/app/admin/system-categories') ||
            path.startsWith('/app/admin/notifications') ||
            path.startsWith('/app/admin/mail-system') ||
            path.startsWith('/app/admin/email-test') ||
            path.startsWith('/app/admin/onboarding/defaults') ||
            path.startsWith('/app/admin/stripe-status')
        )
    }
    if (key === 'support') {
        return (
            path.startsWith('/app/admin/support') ||
            path.startsWith('/app/admin/impersonation') ||
            path.startsWith('/app/admin/demo-workspaces')
        )
    }
    return false
}

/**
 * Global admin center navigation (one row).
 * @param {{ activeCenter?: string }} props
 */
export default function AdminGlobalNav({ activeCenter }) {
    const { url } = usePage()
    const path = pathWithoutQuery(url)
    const perms = useAdminPermissions()

    const visible = CENTERS.filter((c) => {
        if (c.key === 'ai') {
            return perms.canViewAI
        }
        if (c.key === 'support') {
            return perms.canViewSupport || perms.canViewEngineering
        }
        return true
    })

    return (
        <div className="flex flex-wrap items-center gap-x-1 gap-y-2 py-3 text-sm font-medium">
            {visible.map((c) => {
                const active = isActiveCenter(activeCenter, path, c.key)
                return (
                    <Link
                        key={c.key}
                        href={c.href}
                        className={`rounded-md px-3 py-2 transition-colors ${
                            active ? 'bg-white/15 text-white' : 'text-slate-300 hover:bg-white/10 hover:text-white'
                        }`}
                    >
                        {c.label}
                    </Link>
                )
            })}
        </div>
    )
}
