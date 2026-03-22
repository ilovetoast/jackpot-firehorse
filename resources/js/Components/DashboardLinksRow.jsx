import { Link } from '@inertiajs/react'
import { ChevronRightIcon } from '@heroicons/react/24/outline'
import { showWorkspaceSwitchingOverlay } from '../utils/workspaceSwitchOverlay'

/**
 * Contextual Company / Agency / Brand dashboard shortcuts (omit or mark “here” for the active surface).
 */
export default function DashboardLinksRow({ links = {}, variant = 'dark', className = '' }) {
    const dashLinks = links && typeof links === 'object' ? links : {}
    const hasRow = Boolean(
        dashLinks.company ||
            dashLinks.agency ||
            dashLinks.brand ||
            dashLinks.company_current ||
            dashLinks.agency_current ||
            dashLinks.brand_current
    )

    const dashboardLinkClass =
        variant === 'dark'
            ? 'group inline-flex items-center gap-0.5 text-[11px] font-medium text-white/40 transition-colors hover:text-white/75'
            : 'group inline-flex items-center gap-0.5 text-[11px] font-medium text-gray-500 transition-colors hover:text-gray-800'

    const hereClass =
        variant === 'dark'
            ? 'inline-flex items-center gap-0.5 text-[11px] font-medium text-white/35'
            : 'inline-flex items-center gap-0.5 text-[11px] font-medium text-gray-400'

    const hereMutedClass = variant === 'dark' ? 'text-white/25' : 'text-gray-400'

    const goAgency = (e) => {
        e.preventDefault()
        const href = dashLinks.agency
        const tid = dashLinks.agency_switch_tenant_id
        if (!href) {
            return
        }
        if (tid) {
            showWorkspaceSwitchingOverlay('company')
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
            const fd = new FormData()
            fd.append('_token', csrfToken)
            fd.append('redirect', href)
            fetch(`/app/companies/${tid}/switch`, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then(() => {
                    window.location.href = href
                })
                .catch(() => {
                    window.location.href = href
                })
        } else {
            window.location.href = href
        }
    }

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
                        Company
                        <ChevronRightIcon
                            className="h-3 w-3 shrink-0 opacity-40 transition-opacity group-hover:opacity-70"
                            aria-hidden
                        />
                    </Link>
                )}
                {dashLinks.company_current && (
                    <span className={hereClass}>
                        Company<span className={`ml-0.5 text-[10px] font-normal ${hereMutedClass}`}>· here</span>
                    </span>
                )}
                {dashLinks.agency && (
                    <>
                        {dashLinks.agency_switch_tenant_id ? (
                            <button type="button" onClick={goAgency} className={`${dashboardLinkClass} bg-transparent border-0 p-0 cursor-pointer`}>
                                Agency
                                <ChevronRightIcon
                                    className="h-3 w-3 shrink-0 opacity-40 transition-opacity group-hover:opacity-70"
                                    aria-hidden
                                />
                            </button>
                        ) : (
                            <Link href={dashLinks.agency} className={dashboardLinkClass}>
                                Agency
                                <ChevronRightIcon
                                    className="h-3 w-3 shrink-0 opacity-40 transition-opacity group-hover:opacity-70"
                                    aria-hidden
                                />
                            </Link>
                        )}
                    </>
                )}
                {dashLinks.agency_current && (
                    <span className={hereClass}>
                        Agency<span className={`ml-0.5 text-[10px] font-normal ${hereMutedClass}`}>· here</span>
                    </span>
                )}
                {dashLinks.brand && (
                    <Link href={dashLinks.brand} className={dashboardLinkClass}>
                        Brand
                        <ChevronRightIcon
                            className="h-3 w-3 shrink-0 opacity-40 transition-opacity group-hover:opacity-70"
                            aria-hidden
                        />
                    </Link>
                )}
                {dashLinks.brand_current && (
                    <span className={hereClass}>
                        Brand<span className={`ml-0.5 text-[10px] font-normal ${hereMutedClass}`}>· here</span>
                    </span>
                )}
            </div>
        </div>
    )
}
