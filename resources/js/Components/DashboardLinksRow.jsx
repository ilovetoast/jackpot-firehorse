import { Link } from '@inertiajs/react'
import { ChevronRightIcon } from '@heroicons/react/24/outline'

/**
 * Contextual Company / Brand dashboard shortcuts. Omits the current surface (no “here” indicator).
 */
export default function DashboardLinksRow({ links = {}, variant = 'dark', className = '' }) {
    const dashLinks = links && typeof links === 'object' ? links : {}
    const companyLabel = dashLinks.company_label || 'Company settings'
    const brandLabel = dashLinks.brand_label || 'Brand settings'
    const hasRow = Boolean(dashLinks.company || dashLinks.brand)

    const dashboardLinkClass =
        variant === 'dark'
            ? 'group inline-flex items-center gap-0.5 text-[11px] font-medium text-white/40 transition-colors hover:text-white/75'
            : 'group inline-flex items-center gap-0.5 text-[11px] font-medium text-gray-500 transition-colors hover:text-gray-800'

    if (!hasRow) {
        return null
    }

    return (
        <div className={className}>
            <p
                className={`mb-1 text-[10px] font-medium uppercase tracking-wider ${
                    variant === 'dark' ? 'text-white/35' : 'text-gray-400'
                }`}
            >
                Dashboards
            </p>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                {dashLinks.company && (
                    <Link href={dashLinks.company} className={dashboardLinkClass}>
                        {companyLabel}
                        <ChevronRightIcon
                            className="h-3 w-3 shrink-0 opacity-40 transition-opacity group-hover:opacity-70"
                            aria-hidden
                        />
                    </Link>
                )}
                {dashLinks.brand && (
                    <Link href={dashLinks.brand} className={dashboardLinkClass}>
                        {brandLabel}
                        <ChevronRightIcon
                            className="h-3 w-3 shrink-0 opacity-40 transition-opacity group-hover:opacity-70"
                            aria-hidden
                        />
                    </Link>
                )}
            </div>
        </div>
    )
}
