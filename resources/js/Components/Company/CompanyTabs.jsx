import { Link, usePage } from '@inertiajs/react'
import PermissionGate from '../PermissionGate'

/**
 * Company shell — horizontal tabs; violet active state (aligned with company / Jackpot primary accent).
 *
 * @param {'default'|'cinematic'} variant — cinematic = dark / glass (Agency dashboard)
 * @param {boolean} showAgencyTab
 */
export default function CompanyTabs({ variant = 'default', showAgencyTab = true }) {
    const page = usePage()
    const { auth } = page.props
    const url = page.url
    const currentPath = typeof url === 'string' ? new URL(url, 'http://localhost').pathname : (typeof window !== 'undefined' ? window.location.pathname : '')
    const isAgency = auth?.activeCompany?.is_agency === true
    const cinematic = variant === 'cinematic'

    const tabClass = (isActive) => {
        const base = 'whitespace-nowrap border-b-2 py-3.5 px-1.5 text-sm font-medium transition-colors -mb-px min-h-[2.75rem] flex items-center sm:px-2.5 sm:min-h-0 sm:py-3.5'
        if (cinematic) {
            return `${base} ${
                isActive
                    ? 'border-white/50 text-white'
                    : 'border-transparent text-white/50 hover:text-white/85'
            }`
        }
        return `${base} ${
            isActive
                ? 'border-violet-600 text-violet-800'
                : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-200'
        }`
    }

    return (
        <div
            className={
                cinematic
                    ? 'mb-6 border-b border-white/10'
                    : '-mt-0.5 mb-1 border-b border-slate-200/90'
            }
        >
            <nav
                className="flex flex-wrap items-stretch gap-x-1.5 sm:gap-x-2 md:gap-x-3"
                aria-label="Company areas"
            >
                <Link
                    href="/app"
                    className={tabClass(currentPath === '/app' || currentPath === '/app/')}
                    aria-current={currentPath === '/app' || currentPath === '/app/' ? 'page' : undefined}
                >
                    Dashboard
                </Link>
                <PermissionGate permission="team.manage">
                    <Link
                        href="/app/companies/team"
                        className={tabClass(currentPath === '/app/companies/team')}
                        aria-current={currentPath === '/app/companies/team' ? 'page' : undefined}
                    >
                        People
                    </Link>
                </PermissionGate>
                <PermissionGate permission="team.manage">
                    <Link
                        href="/app/companies/permissions"
                        className={tabClass(currentPath.startsWith('/app/companies/permissions'))}
                        aria-current={currentPath.startsWith('/app/companies/permissions') ? 'page' : undefined}
                    >
                        Access
                    </Link>
                </PermissionGate>
                <PermissionGate permission="activity_logs.view">
                    <Link
                        href="/app/companies/activity"
                        className={tabClass(currentPath.startsWith('/app/companies/activity'))}
                        aria-current={currentPath.startsWith('/app/companies/activity') ? 'page' : undefined}
                    >
                        Activity
                    </Link>
                </PermissionGate>
                <PermissionGate permission="company_settings.view">
                    <Link
                        href="/app/companies/settings"
                        className={tabClass(currentPath.startsWith('/app/companies/settings'))}
                        aria-current={currentPath.startsWith('/app/companies/settings') ? 'page' : undefined}
                    >
                        Admin
                    </Link>
                </PermissionGate>
                {isAgency && showAgencyTab && (
                    <Link
                        href="/app/agency/dashboard"
                        className={tabClass(currentPath.startsWith('/app/agency/dashboard'))}
                        aria-current={currentPath.startsWith('/app/agency/dashboard') ? 'page' : undefined}
                    >
                        Agency dashboard
                    </Link>
                )}
            </nav>
        </div>
    )
}
