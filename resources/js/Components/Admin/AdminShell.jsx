import AdminBreadcrumbs from './AdminBreadcrumbs'
import AdminGlobalNav from './AdminGlobalNav'

/**
 * Shared admin layout: global center nav + breadcrumbs + page header.
 * Use inside <main> below AppNav.
 */
export default function AdminShell({
    centerKey,
    breadcrumbs = [],
    title,
    description,
    technicalNote,
    /** Optional local center sidebar (desktop) + mobile “Sections” control */
    sidebar = null,
    children,
}) {
    return (
        <div>
            <div className="border-b border-slate-800 bg-slate-900">
                <div className="mx-auto w-full max-w-admin-shell px-4 sm:px-6 lg:px-8">
                    <AdminGlobalNav activeCenter={centerKey} />
                </div>
            </div>
            <div className="bg-slate-50">
                <div className="mx-auto w-full max-w-admin-shell px-4 sm:px-6 lg:px-8 py-6">
                    <AdminBreadcrumbs items={breadcrumbs} />
                    <header className="mb-6">
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{title}</h1>
                        {description ? <p className="mt-2 max-w-3xl text-sm text-slate-600">{description}</p> : null}
                        {technicalNote}
                    </header>
                    {sidebar ? (
                        <div className="flex min-w-0 flex-col gap-6 lg:flex-row lg:items-start lg:gap-8">
                            <div className="min-w-0 shrink-0 lg:w-[260px]">{sidebar}</div>
                            <div className="min-w-0 flex-1">{children}</div>
                        </div>
                    ) : (
                        children
                    )}
                </div>
            </div>
        </div>
    )
}
