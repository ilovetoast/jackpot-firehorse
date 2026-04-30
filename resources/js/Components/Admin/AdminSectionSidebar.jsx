import { Link, usePage } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import { ChevronDownIcon } from '@heroicons/react/24/outline'

/**
 * Grouped local navigation for an admin center (desktop sidebar + mobile disclosure).
 *
 * @typedef {{ href: string, label: string, match?: 'exact' | 'prefix', active?: boolean }} SectionLink
 * @typedef {{ label: string, links: SectionLink[] }} SectionGroup
 *
 * @param {object} props
 * @param {string} [props.ariaLabel]
 * @param {SectionGroup[]} props.groups
 */
export default function AdminSectionSidebar({ ariaLabel = 'Section navigation', groups }) {
    const { url } = usePage()
    const [mobileOpen, setMobileOpen] = useState(false)

    const pageUrl = url || ''
    const currentPath = pageUrl.split('?')[0].replace(/\/$/, '') || '/'

    const isLinkActive = useMemo(() => {
        return (link) => {
            if (typeof link.active === 'boolean') {
                return link.active
            }
            const mode = link.match ?? 'prefix'
            const href = (link.href || '').split('?')[0].replace(/\/$/, '') || '/'
            if (mode === 'exact') {
                return currentPath === href
            }
            return currentPath === href || currentPath.startsWith(`${href}/`)
        }
    }, [currentPath])

    const linkClass = (active) =>
        [
            'block rounded-md px-3 py-2 text-sm font-medium transition-colors',
            active
                ? 'border-l-4 border-indigo-600 bg-indigo-50 text-indigo-900 -ml-px pl-[11px]'
                : 'border-l-4 border-transparent text-slate-700 hover:bg-slate-50 hover:text-slate-900',
        ].join(' ')

    const NavInner = () => (
        <div className="space-y-6">
            {groups.map((group) => (
                <div key={group.label}>
                    <p className="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wide text-slate-500">{group.label}</p>
                    <ul className="space-y-0.5">
                        {group.links.map((link) => {
                            const active = isLinkActive(link)
                            return (
                                <li key={`${group.label}-${link.href}-${link.label}`}>
                                    <Link href={link.href} className={linkClass(active)} onClick={() => setMobileOpen(false)}>
                                        {link.label}
                                    </Link>
                                </li>
                            )
                        })}
                    </ul>
                </div>
            ))}
        </div>
    )

    return (
        <>
            <div className="lg:hidden">
                <button
                    type="button"
                    onClick={() => setMobileOpen((o) => !o)}
                    className="flex w-full items-center justify-between rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-left text-sm font-medium text-slate-800 shadow-sm"
                    aria-expanded={mobileOpen}
                >
                    Sections
                    <ChevronDownIcon className={`h-5 w-5 text-slate-500 transition-transform ${mobileOpen ? 'rotate-180' : ''}`} />
                </button>
                {mobileOpen ? (
                    <nav
                        className="mt-2 max-h-[min(70vh,520px)] overflow-y-auto rounded-lg border border-slate-200 bg-white p-3 shadow-sm"
                        aria-label={ariaLabel}
                    >
                        <NavInner />
                    </nav>
                ) : null}
            </div>

            <aside className="hidden lg:block w-[260px] shrink-0" aria-hidden={false}>
                <nav
                    className="max-h-[calc(100vh-8rem)] overflow-y-auto rounded-lg border border-slate-200 bg-white p-3 shadow-sm lg:sticky lg:top-24"
                    aria-label={ariaLabel}
                >
                    <NavInner />
                </nav>
            </aside>
        </>
    )
}
