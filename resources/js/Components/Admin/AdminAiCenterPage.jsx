import AppNav from '../AppNav'
import AppFooter from '../AppFooter'
import AdminShell from './AdminShell'
import AdminAiSectionSidebar from './AdminAiSectionSidebar'
import { usePage } from '@inertiajs/react'

/**
 * Standard AI Control Center shell: global admin nav + local AI sidebar + page body.
 */
export default function AdminAiCenterPage({ breadcrumbs, title, description, technicalNote, children }) {
    const { auth } = usePage().props

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="ai"
                    breadcrumbs={breadcrumbs}
                    title={title}
                    description={description}
                    technicalNote={technicalNote}
                    sidebar={<AdminAiSectionSidebar />}
                >
                    {children}
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
