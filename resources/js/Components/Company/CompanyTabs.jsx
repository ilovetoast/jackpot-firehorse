import { Link, usePage } from '@inertiajs/react'
import PermissionGate from '../PermissionGate'

/**
 * Tab navigation for Company section — Overview, Team, Permissions, Activity, Setting, Agency.
 * Use on Company Overview and other company pages for consistent nav.
 */
export default function CompanyTabs() {
    const page = usePage()
    const { auth } = page.props
    const url = page.url
    const currentPath = typeof url === 'string' ? new URL(url, 'http://localhost').pathname : (typeof window !== 'undefined' ? window.location.pathname : '')
    const isAgency = auth?.activeCompany?.is_agency === true

    const tabClass = (isActive) =>
        `whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium ${
            isActive ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
        }`

    return (
        <div className="mb-8 border-b border-gray-200">
            <nav className="-mb-px flex flex-wrap gap-x-6" aria-label="Company sections">
                <Link href="/app" className={tabClass(currentPath === '/app' || currentPath === '/app/')}>
                    Overview
                </Link>
                <PermissionGate permission="team.manage">
                    <Link href="/app/companies/team" className={tabClass(currentPath === '/app/companies/team')}>
                        Team
                    </Link>
                </PermissionGate>
                <PermissionGate permission="team.manage">
                    <Link href="/app/companies/permissions" className={tabClass(currentPath.startsWith('/app/companies/permissions'))}>
                        Permissions
                    </Link>
                </PermissionGate>
                <PermissionGate permission="activity_logs.view">
                    <Link href="/app/companies/activity" className={tabClass(currentPath.startsWith('/app/companies/activity'))}>
                        Activity
                    </Link>
                </PermissionGate>
                <PermissionGate permission="company_settings.view">
                    <Link href="/app/companies/settings" className={tabClass(currentPath.startsWith('/app/companies/settings'))}>
                        Setting
                    </Link>
                </PermissionGate>
                {isAgency && (
                    <Link href="/app/agency/dashboard" className={tabClass(currentPath.startsWith('/app/agency/dashboard'))}>
                        Agency
                    </Link>
                )}
            </nav>
        </div>
    )
}
