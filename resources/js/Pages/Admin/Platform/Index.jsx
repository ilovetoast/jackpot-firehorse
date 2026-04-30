import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'
import { useAdminPermissions } from '../../../hooks/useAdminPermissions'
import {
    LockClosedIcon,
    TagIcon,
    FolderIcon,
    BellIcon,
    InboxIcon,
    EnvelopeIcon,
    AcademicCapIcon,
} from '@heroicons/react/24/outline'

const card =
    'flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-colors hover:border-indigo-200 hover:bg-indigo-50/40'

export default function AdminPlatformIndex() {
    const { auth } = usePage().props
    const perms = useAdminPermissions()

    const items = [
        { name: 'Permissions', description: 'Site and company role permissions', href: '/app/admin/permissions', icon: LockClosedIcon, show: perms.canManagePermissions },
        { name: 'Metadata registry', description: 'System metadata fields', href: '/app/admin/metadata/registry', icon: TagIcon, show: perms.canViewMetadataRegistry },
        { name: 'System categories', description: 'Category templates', href: '/app/admin/system-categories', icon: FolderIcon, show: true },
        { name: 'Notifications', description: 'Email templates', href: '/app/admin/notifications', icon: BellIcon, show: true },
        { name: 'Mail system', description: 'Outbound mail and staging', href: '/app/admin/mail-system', icon: InboxIcon, show: true },
        { name: 'Email test', description: 'Send test messages', href: '/app/admin/email-test', icon: EnvelopeIcon, show: true },
        { name: 'Onboarding defaults', description: 'Default categories and fields for new accounts', href: '/app/admin/onboarding/defaults', icon: AcademicCapIcon, show: true },
    ].filter((x) => x.show)

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="platform"
                    breadcrumbs={[{ label: 'Admin', href: '/app/admin' }, { label: 'Platform configuration' }]}
                    title="Platform configuration"
                    description="How the platform behaves: permissions, metadata, notifications, mail, and onboarding templates."
                >
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {items.map((item) => (
                            <Link key={item.href} href={item.href} className={card}>
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100">
                                    <item.icon className="h-5 w-5 text-slate-600" />
                                </div>
                                <div className="min-w-0">
                                    <p className="font-semibold text-slate-900">{item.name}</p>
                                    <p className="text-sm text-slate-500">{item.description}</p>
                                </div>
                            </Link>
                        ))}
                    </div>
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
